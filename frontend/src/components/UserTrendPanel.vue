<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from "vue";
import * as echarts from "echarts";
import { NButton, NEmpty } from "naive-ui";
import { formatToken, formatCost } from "../format";

const props = defineProps<{
  user: string;
  daily: { day: string; models: Record<string, number>; cost: number; hitRate: string | null }[];
  cats?: { key: string; label: string; color: string }[];
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
  const narrow = el.value.clientWidth < 500;
  const labelSize = narrow ? 10 : 12;
  // auto 抽稀：数据点多时保留间隔日期，不用 hideOverlap（会全隐藏）
  const axisLabel = { fontSize: labelSize, interval: "auto" };
  const grid = narrow
    ? { left: 40, right: 20, top: 32, bottom: 22 }
    : { left: 64, right: 24, top: 40, bottom: 32 };

  chart.setOption({
    tooltip: {
      trigger: "axis",
      formatter: (params: any) => {
        const idx = params?.[0]?.dataIndex ?? 0;
        const d = props.daily[idx];
        if (!d) return "";
        const cats = props.cats ?? [];
        const lines = cats.map(c => `${c.label}：${formatToken(d.models?.[c.key] ?? 0)}`).join("<br/>");
        return `${d.day}<br/>${lines}<br/>命中率：${d.hitRate ?? "—"}%<br/>估算成本：${formatCost(d.cost)}`;
      },
    },
    legend: { data: [...(props.cats ?? []).map(c => `${c.label} Token`), "命中率"], top: 0 },
    grid,
    xAxis: { type: "category", boundaryGap: false, data: props.daily.map(d => d.day), axisLabel },
    yAxis: [
      // 轴不设单位名：单位说明统一放图表底部（chart-foot）
      { type: "value", axisLabel: { formatter: fmtAxis, fontSize: labelSize }, splitLine: { lineStyle: { type: "dashed" } } },
      { type: "value", min: 0, max: 100, axisLabel: { formatter: "{value}%", fontSize: labelSize } },
    ],
    series: [
      ...(props.cats ?? []).map(c => ({ name: `${c.label} Token`, type: "line", smooth: true, symbol: "circle", symbolSize: 6, data: props.daily.map(d => d.models?.[c.key] ?? 0), itemStyle: { color: c.color }, lineStyle: { width: 2 } })),
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
watch(() => [props.daily, props.cats], render, { deep: true });
</script>

<template>
  <div class="drawer-backdrop" @click="emit('close')"></div>
  <div class="panel">
    <div class="panel-head">
      <span class="panel-title">{{ user }} · 每日趋势</span>
      <n-button size="tiny" quaternary @click="emit('close')">收起</n-button>
    </div>
    <div v-if="daily.length" ref="el" class="chart"></div>
    <div v-else class="empty"><n-empty description="暂无该用户趋势数据" size="small" /></div>
    <div v-if="daily.length" class="chart-foot">左轴 = token · 右轴 = 命中率%</div>
  </div>
</template>

<style scoped>
/* 遮罩：全屏铺满，点遮罩关闭（桌面弹窗 / 移动抽屉共用） */
.drawer-backdrop {
  position: fixed;
  inset: 0;
  z-index: 800;
  background: rgba(15, 18, 35, 0.45);
}
/* 桌面端：居中弹窗（z-index 高于遮罩） */
.panel {
  position: fixed;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  z-index: 810;
  width: min(760px, calc(100vw - 48px));
  padding: 16px;
  background: #fafbfc;
  border-radius: 12px;
  border: 1px solid #ebeef5;
  box-shadow: 0 8px 32px rgba(79, 110, 247, 0.18);
}
/* 移动端：底部抽屉浮层（覆盖桌面居中定位） */
@media (max-width: 768px) {
  .panel {
    left: 0;
    right: 0;
    top: auto;
    bottom: 0;
    transform: none;
    width: auto;
    max-height: 70vh;
    overflow: auto;
    border-radius: 16px 16px 0 0;
    box-shadow: 0 -8px 32px rgba(79, 110, 247, 0.18);
    padding-bottom: 24px;
  }
}
.panel-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 8px;
}
.panel-title {
  font-weight: 700;
  font-size: 15px;
  color: #303133;
}
.chart {
  height: 300px;
  width: 100%;
}
/* 图表底部单位说明 */
.chart-foot {
  text-align: center;
  font-size: 11px;
  color: #a0a6b3;
  margin-top: 4px;
}
.empty {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 300px;
  color: #a0a6b3;
  font-size: 14px;
}
@media (max-width: 768px) {
  .chart {
    height: 220px;
  }
  .empty {
    height: 220px;
  }
}
</style>
