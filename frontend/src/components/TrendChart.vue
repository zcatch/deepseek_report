<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from "vue";
import * as echarts from "echarts";
import { NEmpty } from "naive-ui";
import { formatToken } from "../format";
import { CATEGORY_GRADIENTS } from "../api";

const props = defineProps<{
  data: any[];
  personal?: boolean;
  cats?: { key: string; label: string; color: string }[];
  userDaily?: Map<string, { user: string; tokens: number }[]>;
  byUser?: { user: string; tokens: number; cost: number; hitRate: string | null; models: Record<string, number> }[] | null;
}>();
const el = ref<HTMLDivElement>();
let chart: echarts.ECharts | null = null;

function fmtTokenAxis(v: number): string {
  const a = Math.abs(v);
  if (a >= 1e8) return (v / 1e8).toFixed(1) + "亿";
  if (a >= 1e4) return (v / 1e4).toFixed(0) + "万";
  return String(v);
}

// tooltip 里追加「当天各人 token」明细（默认全部人 / 筛选后仅选中人）；人多时内部滚动
function userSection(day: string): string {
  const users = props.userDaily?.get(day) ?? [];
  if (!users.length) return "";
  const total = users.reduce((s, u) => s + u.tokens, 0);
  const lines = users.map((u, i) => `${i + 1}. ${u.user}：${formatToken(u.tokens)}`).join("<br/>");
  return `<br/>────────────<br/>总 Token：${formatToken(total)}<br/><div style="max-height:140px;overflow-y:auto;padding-right:4px;">${lines}</div>`;
}

function render() {
  if (!el.value) return;
  if (!chart) chart = echarts.init(el.value);
  // 窄屏（手机）用紧凑 grid 与更小的 x 轴标签，避免边距挤占与日期重叠
  const narrow = el.value.clientWidth < 500;
  // 显式 axisLabel（始终是对象）：非窄屏也传 fontSize，避免 undefined 导致 x 轴日期标签不渲染
  const axisLabel = { fontSize: narrow ? 10 : 12 };

  // 高度自适应：单天按人排行榜按人数撑高全部展示（条宽固定不变细），趋势/个人恢复 CSS 默认高度
  if (props.byUser?.length) {
    el.value.style.height = Math.max(340, props.byUser.length * 32 + 70) + "px";
  } else {
    el.value.style.height = "";
  }
  chart.resize();

  const catsList = props.cats ?? [];
  // 单天：横向堆叠条形排行榜（渐变能量条 + 生长动画；固定条宽全部展示，人多图表自动变高）
  if (props.byUser && props.byUser.length) {
    const list = props.byUser;
    const totals = list.map(u => u.tokens);
    const series = catsList.map((c, ci) => {
      const grad = CATEGORY_GRADIENTS[ci % CATEGORY_GRADIENTS.length];
      const data = list.map((u, ui) => {
        const val = u.models?.[c.key] ?? 0;
        // 判断该模型是否为这个人「最右侧非零」的段：决定右侧圆角与条尾总量标签
        let isRightmost = false;
        if (val > 0) {
          isRightmost = true;
          for (let j = ci + 1; j < catsList.length; j++) {
            if ((list[ui].models?.[catsList[j].key] ?? 0) > 0) { isRightmost = false; break; }
          }
        }
        const item: any = { value: val, itemStyle: { borderRadius: isRightmost ? [0, 6, 6, 0] : 0 } };
        if (isRightmost && !narrow) {
          item.label = {
            show: true, position: "right", distance: 8,
            formatter: () => formatToken(totals[ui]),
            fontSize: 12, fontWeight: 600, color: "#3e5ce6",
          };
        }
        return item;
      });
      return {
        name: `${c.label} Token`, type: "bar", stack: "total", barWidth: narrow ? 16 : 20,
        data,
        itemStyle: {
          color: new echarts.graphic.LinearGradient(0, 0, 1, 0, [
            { offset: 0, color: grad[0] },
            { offset: 1, color: grad[1] },
          ]),
        },
        emphasis: { focus: "series", itemStyle: { shadowBlur: 10, shadowColor: "rgba(79, 110, 247, 0.35)" } },
      };
    });
    chart.setOption({
      animationDuration: 800,
      animationEasing: "cubicOut",
      tooltip: {
        trigger: "axis",
        axisPointer: { type: "shadow" },
        formatter: (params: any) => {
          const idx = params?.[0]?.dataIndex ?? 0;
          const u = list[idx];
          if (!u) return "";
          const lines = catsList.map(c => `${c.label}：${formatToken(u.models?.[c.key] ?? 0)}`).join("<br/>");
          return `${u.user}<br/>总 Token：${formatToken(u.tokens)}<br/>${lines}<br/>成本：¥${u.cost.toFixed(2)}<br/>命中率：${u.hitRate ?? "—"}%`;
        },
      },
      legend: { data: catsList.map(c => `${c.label} Token`), top: 0 },
      grid: { containLabel: true, left: 8, right: narrow ? 12 : 76, top: 36, bottom: 8 },
      xAxis: { type: "value", axisLabel: { formatter: fmtTokenAxis, ...axisLabel }, splitLine: { lineStyle: { type: "dashed", color: "#e6e8f0" } } },
      yAxis: {
        type: "category", data: list.map(u => u.user), inverse: true,
        axisLabel: {
          ...axisLabel,
          interval: 0,
          color: "#3d434f",
          fontSize: narrow ? 11 : 13,
        },
        axisLine: { show: false },
        axisTick: { show: false },
      },
      series,
    }, true);
    return;
  }
  // personal 视角只看 token（命中率在个人面板看），团队视角加命中率曲线
  if (props.personal) {
    const legend = catsList.map(c => `${c.label} Token`);
    const series = catsList.map(c => ({
      name: `${c.label} Token`, type: "line", smooth: true, symbol: "circle", symbolSize: 6,
      data: props.data.map((d: any) => d.models?.[c.key] ?? 0),
      itemStyle: { color: c.color }, lineStyle: { width: 2 },
    }));
    chart.setOption({
      tooltip: {
        trigger: "axis",
        enterable: true,
        confine: true,
        formatter: (params: any) => {
          const idx = params?.[0]?.dataIndex ?? 0;
          const day = props.data[idx]?.day ?? "";
          const lines = params.map((p: any) => `${p.marker}${p.seriesName}：${typeof p.value === "number" ? formatToken(p.value) : p.value}`).join("<br/>");
          return `${day}<br/>${lines}${userSection(day)}`;
        },
      },
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
    tooltip: {
      trigger: "axis",
      enterable: true,
      confine: true,
      formatter: (params: any) => {
        const idx = params?.[0]?.dataIndex ?? 0;
        const day = props.data[idx]?.day ?? "";
        const lines = params.map((p: any) => {
          if (p.seriesName === "命中率") {
            const v = p.value != null ? parseFloat(p.value) : null;
            return `${p.marker}命中率：${v != null ? v.toFixed(1) + "%" : "—"}`;
          }
          return `${p.marker}${p.seriesName}：¥${Number(p.value ?? 0).toFixed(2)}`;
        }).join("<br/>");
        return `${day}<br/>${lines}${userSection(day)}`;
      },
    },
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

watch([() => props.data, () => props.personal, () => props.cats, () => props.userDaily, () => props.byUser], render, { deep: true });
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
