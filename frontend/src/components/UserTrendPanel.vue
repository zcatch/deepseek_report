<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from "vue";
import * as echarts from "echarts";
import { NButton } from "naive-ui";
import { formatToken, formatCost } from "../format";

const props = defineProps<{
  user: string;
  daily: { day: string; pro: number; flash: number; cost: number; hitRate: string | null }[];
}>();

const emit = defineEmits<{ (e: "close"): void }>();

const el = ref<HTMLDivElement>();
let chart: echarts.ECharts | null = null;

function fmtAxis(v: number): string {
  const a = Math.abs(v);
  if (a >= 1e8) return (v / 1e8).toFixed(1) + "亿";
  if (a >= 1e4) return (v / 1e4).toFixed(0) + "万";
  return String(v);
}

function render() {
  if (!el.value) return;
  if (!chart) chart = echarts.init(el.value);

  chart.setOption({
    tooltip: {
      trigger: "axis",
      formatter: (params: any) => {
        const idx = params?.[0]?.dataIndex ?? 0;
        const d = props.daily[idx];
        if (!d) return "";
        return `${d.day}<br/>Pro：${formatToken(d.pro)}<br/>Flash：${formatToken(d.flash)}<br/>命中率：${d.hitRate ?? "—"}%<br/>估算成本：${formatCost(d.cost)}`;
      },
    },
    legend: { data: ["Pro Token", "Flash Token", "命中率"], top: 0 },
    grid: { left: 64, right: 64, top: 40, bottom: 32 },
    xAxis: { type: "category", boundaryGap: false, data: props.daily.map(d => d.day) },
    yAxis: [
      { type: "value", name: "token", axisLabel: { formatter: fmtAxis }, splitLine: { lineStyle: { type: "dashed" } } },
      { type: "value", name: "命中率%", min: 0, max: 100, axisLabel: { formatter: "{value}%" } },
    ],
    series: [
      { name: "Pro Token", type: "line", smooth: true, symbol: "circle", symbolSize: 6, data: props.daily.map(d => d.pro), itemStyle: { color: "#5470c6" }, lineStyle: { width: 2 } },
      { name: "Flash Token", type: "line", smooth: true, symbol: "circle", symbolSize: 6, data: props.daily.map(d => d.flash), itemStyle: { color: "#91cc75" }, lineStyle: { width: 2 } },
      { name: "命中率", type: "line", yAxisIndex: 1, smooth: true, symbol: "triangle", symbolSize: 6, data: props.daily.map(d => (d.hitRate != null ? parseFloat(d.hitRate) : null)), itemStyle: { color: "#f0a020" }, lineStyle: { width: 2, type: "dashed" } },
    ],
  }, true);
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
watch(() => props.daily, render, { deep: true });
</script>

<template>
  <div class="panel">
    <div class="panel-head">
      <span class="panel-title">{{ user }} · 每日趋势</span>
      <span class="panel-sub">token 左轴 · 命中率右轴（虚线）</span>
      <n-button size="tiny" quaternary @click="emit('close')">收起</n-button>
    </div>
    <div ref="el" class="chart"></div>
  </div>
</template>

<style scoped>
.panel {
  margin-top: 16px;
  padding: 16px;
  background: #fafbfc;
  border-radius: 10px;
  border: 1px solid #ebeef5;
}
.panel-head {
  display: flex;
  align-items: baseline;
  gap: 12px;
  margin-bottom: 8px;
}
.panel-title {
  font-weight: 700;
  font-size: 15px;
  color: #303133;
}
.panel-sub {
  font-size: 12px;
  color: #8a8f99;
  flex: 1;
}
.chart {
  height: 300px;
  width: 100%;
}
</style>
