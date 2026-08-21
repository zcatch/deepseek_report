<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from "vue";
import * as echarts from "echarts";

const props = defineProps<{ data: any[]; personal?: boolean }>();
const el = ref<HTMLDivElement>();
let chart: echarts.ECharts | null = null;

function fmtTokenAxis(v: number): string {
  const a = Math.abs(v);
  if (a >= 1e8) return (v / 1e8).toFixed(1) + "亿";
  if (a >= 1e4) return (v / 1e4).toFixed(0) + "万";
  return String(v);
}

function render() {
  if (!el.value) return;
  if (!chart) chart = echarts.init(el.value);

  // personal 视角只看 token（命中率在个人面板看），团队视角加命中率曲线
  if (props.personal) {
    const legend = ["Pro Token", "Flash Token"];
    const series = [
      { name: "Pro Token", type: "line", smooth: true, symbol: "circle", symbolSize: 6, data: props.data.map((d: any) => d.pro), itemStyle: { color: "#5470c6" }, lineStyle: { width: 2 } },
      { name: "Flash Token", type: "line", smooth: true, symbol: "circle", symbolSize: 6, data: props.data.map((d: any) => d.flash), itemStyle: { color: "#91cc75" }, lineStyle: { width: 2 } },
    ];
    chart.setOption({
      tooltip: { trigger: "axis" },
      legend: { data: legend, top: 0 },
      grid: { left: 64, right: 24, top: 40, bottom: 32 },
      xAxis: { type: "category", boundaryGap: false, data: props.data.map((d: any) => d.day) },
      yAxis: { type: "value", name: "token", axisLabel: { formatter: fmtTokenAxis }, splitLine: { lineStyle: { type: "dashed" } } },
      series,
    }, true);
    return;
  }

  const hitSeries = {
    name: "命中率",
    type: "line" as const,
    yAxisIndex: 1,
    smooth: true,
    symbol: "triangle",
    symbolSize: 6,
    data: props.data.map((d: any) => (d.hitRate != null ? parseFloat(d.hitRate) : null)),
    itemStyle: { color: "#f0a020" },
    lineStyle: { width: 2, type: "dashed" as const },
  };
  const legend = ["Pro 估算", "Flash 估算", "实际扣费", "命中率"];
  const series = [
    { name: "Pro 估算", type: "line", smooth: true, symbol: "circle", symbolSize: 6, data: props.data.map((d: any) => d.proEst), itemStyle: { color: "#5470c6" }, lineStyle: { width: 2 } },
    { name: "Flash 估算", type: "line", smooth: true, symbol: "circle", symbolSize: 6, data: props.data.map((d: any) => d.flashEst), itemStyle: { color: "#91cc75" }, lineStyle: { width: 2 } },
    { name: "实际扣费", type: "line", smooth: true, symbol: "diamond", symbolSize: 7, data: props.data.map((d: any) => d.actual), itemStyle: { color: "#ee6666" }, lineStyle: { width: 2 } },
    hitSeries,
  ];
  chart.setOption({
    tooltip: { trigger: "axis" },
    legend: { data: legend, top: 0 },
    grid: { left: 64, right: 64, top: 40, bottom: 32 },
    xAxis: { type: "category", boundaryGap: false, data: props.data.map((d: any) => d.day) },
    yAxis: [
      { type: "value", name: "元", splitLine: { lineStyle: { type: "dashed" } } },
      { type: "value", name: "命中率%", min: 0, max: 100, axisLabel: { formatter: "{value}%" } },
    ],
    series,
  }, true);
}

function onResize() {
  chart?.resize();
}

onMounted(() => {
  render();
  window.addEventListener("resize", onResize);
});

onBeforeUnmount(() => {
  window.removeEventListener("resize", onResize);
  chart?.dispose();
  chart = null;
});

watch([() => props.data, () => props.personal], render, { deep: true });
</script>

<template>
  <div ref="el" class="chart"></div>
</template>

<style scoped>
.chart {
  height: 340px;
  width: 100%;
}
</style>
