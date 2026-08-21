<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from "vue";
import * as echarts from "echarts";
import { formatToken, formatCost } from "../format";

const props = defineProps<{
  points: { user: string; total: number; hitRate: string | null; cost: number; input: number; output: number; outputRatio: string | null }[];
  mode?: "io" | "hit";
}>();

const el = ref<HTMLDivElement>();
let chart: echarts.ECharts | null = null;

function fmtAxis(v: number): string {
  const a = Math.abs(v);
  if (a >= 1e8) return (v / 1e8).toFixed(1) + "亿";
  if (a >= 1e4) return (v / 1e4).toFixed(0) + "万";
  return String(v);
}

const SYMBOL = (val: any) => {
  const cost = val?.[2] ?? 0;
  const r = Math.sqrt(cost);
  return Math.min(50, Math.max(8, r * 0.6));
};
const EMPHASIS = {
  itemStyle: { opacity: 1 },
  label: { show: true, position: "top", formatter: (p: any) => p.name, fontSize: 11, color: "#303133" },
};

function renderIo() {
  if (!el.value) return;
  if (!chart) chart = echarts.init(el.value);
  // 输入/输出值域跨 4 个数量级，用对数轴；log 轴不接受 0，clamp 到 1
  const data = props.points.map(p => ({ name: p.user, value: [Math.max(p.input, 1), Math.max(p.output, 1), p.cost, p.outputRatio] }));
  const xs = data.map(d => d.value[0]);
  const ys = data.map(d => d.value[1]);
  const minVal = Math.min(...xs, ...ys);
  const maxVal = Math.max(...xs, ...ys);

  chart.setOption({
    tooltip: {
      formatter: (params: any) => {
        const d = params.data;
        if (!d) return "";
        const [input, output, cost, ratio] = d.value;
        return `${d.name}<br/>输入：${formatToken(input)}<br/>输出：${formatToken(output)}<br/>输出占比：${ratio ? ratio + "%" : "—"}<br/>成本：${formatCost(cost)}`;
      },
    },
    grid: { left: 96, right: 32, top: 28, bottom: 60 },
    xAxis: { type: "log", name: "输入 Token", nameLocation: "middle", nameGap: 40, axisLabel: { formatter: (v: number) => fmtAxis(v) }, splitLine: { lineStyle: { type: "dashed" } } },
    yAxis: { type: "log", name: "输出 Token", nameLocation: "middle", nameGap: 60, axisLabel: { formatter: (v: number) => fmtAxis(v) }, splitLine: { lineStyle: { type: "dashed" } } },
    series: [
      {
        type: "scatter",
        symbolSize: SYMBOL,
        data,
        itemStyle: { color: "#5470c6", opacity: 0.7 },
        label: { show: false },
        emphasis: EMPHASIS,
        markLine: {
          silent: true,
          symbol: "none",
          lineStyle: { type: "dashed", color: "#999" },
          label: { formatter: "输出=输入", position: "insideEndTop", color: "#999" },
          data: [[{ coord: [minVal, minVal] }, { coord: [maxVal, maxVal] }]],
        },
      },
    ],
  }, true);
}

function renderHit() {
  if (!el.value) return;
  if (!chart) chart = echarts.init(el.value);
  const data = props.points.map(p => ({ name: p.user, value: [p.total, p.hitRate != null ? parseFloat(p.hitRate) : null, p.cost] }));

  chart.setOption({
    tooltip: {
      formatter: (params: any) => {
        const d = params.data;
        if (!d) return "";
        const [total, hitRate, cost] = d.value;
        return `${d.name}<br/>总 Token：${formatToken(total)}<br/>命中率：${hitRate ?? "—"}%<br/>成本：${formatCost(cost)}`;
      },
    },
    grid: { left: 80, right: 32, top: 28, bottom: 56 },
    xAxis: { type: "value", name: "总 Token", nameLocation: "middle", nameGap: 36, axisLabel: { formatter: (v: number) => fmtAxis(v) }, splitLine: { lineStyle: { type: "dashed" } } },
    yAxis: { type: "value", name: "命中率%", nameLocation: "middle", nameGap: 56, min: 0, max: 100, axisLabel: { formatter: "{value}%" }, splitLine: { lineStyle: { type: "dashed" } } },
    series: [
      {
        type: "scatter",
        symbolSize: SYMBOL,
        data,
        itemStyle: { color: "#5470c6", opacity: 0.7 },
        label: { show: false },
        emphasis: EMPHASIS,
      },
    ],
  }, true);
}

function render() {
  if (props.mode === "hit") renderHit();
  else renderIo();
}

function onResize() { chart?.resize(); }

onMounted(() => {
  render();
  window.addEventListener("resize", onResize);
});
onBeforeUnmount(() => {
  window.removeEventListener("resize", onResize);
  chart?.dispose();
  chart = null;
});
watch([() => props.points, () => props.mode], render, { deep: true });
</script>

<template>
  <div ref="el" class="chart"></div>
</template>

<style scoped>
.chart {
  height: 320px;
  width: 100%;
}
</style>
