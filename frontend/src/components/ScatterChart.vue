<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from "vue";
import * as echarts from "echarts";
import { NEmpty } from "naive-ui";
import { formatToken, formatCost } from "../format";

const props = defineProps<{
  points: { user: string; total: number; hitRate: string | null; cost: number; input: number; output: number; outputRatio: string | null }[];
  mode?: "io" | "hit";
  highlight?: string;
}>();

const el = ref<HTMLDivElement>();
let chart: echarts.ECharts | null = null;

// 高亮某用户：主题色 + 白色描边 + 放大 + 光晕（个人视角下在团队里定位他）
const HIGHLIGHT = {
  color: "#4f6ef7",
  borderColor: "#fff",
  borderWidth: 3,
  opacity: 1,
  shadowBlur: 14,
  shadowColor: "rgba(79, 110, 247, 0.55)",
};

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

function renderIo(narrow: boolean) {
  if (!el.value) return;
  if (!chart) chart = echarts.init(el.value);
  // 输入/输出值域跨 4 个数量级，用对数轴；log 轴不接受 0，clamp 到 1
  const data = props.points.map(p => ({
    name: p.user,
    value: [Math.max(p.input, 1), Math.max(p.output, 1), p.cost, p.outputRatio],
    itemStyle: p.user === props.highlight ? HIGHLIGHT : undefined,
    symbolSize: p.user === props.highlight ? 26 : undefined,
  }));
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
    grid: narrow ? { left: 44, right: 16, top: 24, bottom: 24 } : { left: 64, right: 24, top: 28, bottom: 32 },
    // 轴不设单位名：说明统一放图表底部（chart-foot）
    xAxis: { type: "log", axisLabel: { formatter: (v: number) => fmtAxis(v) }, splitLine: { lineStyle: { type: "dashed" } } },
    yAxis: { type: "log", axisLabel: { formatter: (v: number) => fmtAxis(v) }, splitLine: { lineStyle: { type: "dashed" } } },
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

function renderHit(narrow: boolean) {
  if (!el.value) return;
  if (!chart) chart = echarts.init(el.value);
  const data = props.points.map(p => ({
    name: p.user,
    value: [p.total, p.hitRate != null ? parseFloat(p.hitRate) : null, p.cost],
    itemStyle: p.user === props.highlight ? HIGHLIGHT : undefined,
    symbolSize: p.user === props.highlight ? 26 : undefined,
  }));

  chart.setOption({
    tooltip: {
      formatter: (params: any) => {
        const d = params.data;
        if (!d) return "";
        const [total, hitRate, cost] = d.value;
        return `${d.name}<br/>总 Token：${formatToken(total)}<br/>命中率：${hitRate ?? "—"}%<br/>成本：${formatCost(cost)}`;
      },
    },
    grid: narrow ? { left: 44, right: 16, top: 24, bottom: 24 } : { left: 64, right: 24, top: 28, bottom: 32 },
    xAxis: { type: "value", axisLabel: { formatter: (v: number) => fmtAxis(v) }, splitLine: { lineStyle: { type: "dashed" } } },
    yAxis: { type: "value", min: 0, max: 100, axisLabel: { formatter: "{value}%" }, splitLine: { lineStyle: { type: "dashed" } } },
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
  const narrow = !!el.value && el.value.clientWidth < 500;
  if (props.mode === "hit") renderHit(narrow);
  else renderIo(narrow);
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
watch([() => props.points, () => props.mode, () => props.highlight], render, { deep: true });
</script>

<template>
  <div class="chart-wrap">
    <div ref="el" class="chart" :class="{ hidden: !props.points.length }"></div>
    <div v-if="!props.points.length" class="empty"><n-empty description="暂无数据" size="small" /></div>
    <div v-if="props.points.length" class="chart-foot">
      {{ props.mode === "hit" ? "横轴 = 总 Token · 纵轴 = 命中率%" : "横轴 = 输入 Token · 纵轴 = 输出 Token" }}
    </div>
  </div>
</template>

<style scoped>
.chart-wrap {
  position: relative;
}
.chart {
  height: 320px;
  width: 100%;
}
.chart.hidden {
  visibility: hidden;
}
.empty {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #a0a6b3;
  font-size: 14px;
}
.chart-foot {
  text-align: center;
  font-size: 11px;
  color: #a0a6b3;
  margin-top: 4px;
}
@media (max-width: 768px) {
  .chart {
    height: 260px;
  }
}
</style>
