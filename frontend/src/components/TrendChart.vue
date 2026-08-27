<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from "vue";
import * as echarts from "echarts";
import { NEmpty } from "naive-ui";

const props = defineProps<{ data: any[]; personal?: boolean; cats?: { key: string; label: string; color: string }[] }>();
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
  // 窄屏（手机）用紧凑 grid 与更小的 x 轴标签，避免边距挤占与日期重叠
  const narrow = el.value.clientWidth < 500;
  // 显式 axisLabel（始终是对象）：非窄屏也传 fontSize，避免 undefined 导致 x 轴日期标签不渲染
  const axisLabel = { fontSize: narrow ? 10 : 12 };

  const catsList = props.cats ?? [];
  // personal 视角只看 token（命中率在个人面板看），团队视角加命中率曲线
  if (props.personal) {
    const legend = catsList.map(c => `${c.label} Token`);
    const series = catsList.map(c => ({
      name: `${c.label} Token`, type: "line", smooth: true, symbol: "circle", symbolSize: 6,
      data: props.data.map((d: any) => d.models?.[c.key] ?? 0),
      itemStyle: { color: c.color }, lineStyle: { width: 2 },
    }));
    chart.setOption({
      tooltip: { trigger: "axis" },
      legend: { data: legend, top: 0 },
      grid: narrow ? { left: 40, right: 10, top: 32, bottom: 22 } : { left: 64, right: 24, top: 40, bottom: 32 },
      xAxis: { type: "category", boundaryGap: false, data: props.data.map((d: any) => d.day), axisLabel },
      // 轴不设单位名：单位说明统一放图表底部（chart-foot），避免左右轴名占用/挤占
      yAxis: { type: "value", axisLabel: { formatter: fmtTokenAxis, ...axisLabel }, splitLine: { lineStyle: { type: "dashed" } } },
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
  const legend = [...catsList.map(c => `${c.label} 估算`), "实际扣费", "命中率"];
  const series = [
    ...catsList.map(c => ({ name: `${c.label} 估算`, type: "line", smooth: true, symbol: "circle", symbolSize: 6, data: props.data.map((d: any) => d.est?.[c.key] ?? 0), itemStyle: { color: c.color }, lineStyle: { width: 2 } })),
    { name: "实际扣费", type: "line", smooth: true, symbol: "diamond", symbolSize: 7, data: props.data.map((d: any) => d.actual), itemStyle: { color: "#ee6666" }, lineStyle: { width: 2 } },
    hitSeries,
  ];
  chart.setOption({
    tooltip: { trigger: "axis" },
    legend: { data: legend, top: 0 },
    grid: narrow ? { left: 40, right: 20, top: 32, bottom: 22 } : { left: 64, right: 24, top: 40, bottom: 32 },
    xAxis: { type: "category", boundaryGap: false, data: props.data.map((d: any) => d.day), axisLabel },
    yAxis: [
      { type: "value", splitLine: { lineStyle: { type: "dashed" } } },
      { type: "value", min: 0, max: 100, axisLabel: { formatter: "{value}%", ...axisLabel } },
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

watch([() => props.data, () => props.personal, () => props.cats], render, { deep: true });
</script>

<template>
  <div class="chart-wrap">
    <div ref="el" class="chart" :class="{ hidden: !props.data.length }"></div>
    <div v-if="!props.data.length" class="empty"><n-empty :description="props.personal ? '暂无个人趋势数据' : '暂无费用趋势数据'" size="small" /></div>
    <div v-if="props.data.length" class="chart-foot">
      {{ props.personal ? "单位：token" : "左轴 = 费用（元）· 右轴 = 命中率%" }}
    </div>
  </div>
</template>

<style scoped>
.chart-wrap {
  position: relative;
}
.chart {
  height: 340px;
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
    height: 240px;
  }
}
</style>
