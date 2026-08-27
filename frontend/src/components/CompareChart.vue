<script setup lang="ts">
import { computed, ref, watch, onMounted, onBeforeUnmount } from "vue";
import * as echarts from "echarts";
import { NEmpty } from "naive-ui";
import type { UsageData } from "../api";
import { formatToken, formatCost } from "../format";

// 模型分析：该用户在某一模型维度 vs 团队同模型用户均值，横向条形显示相对偏差
const props = defineProps<{ user: string; data: UsageData; model: string; label: string; color: string }>();

const el = ref<HTMLDivElement>();
let chart: echarts.ECharts | null = null;

const modelLabel = computed(() => props.label);
const emptyText = computed(() => `该用户未使用 ${modelLabel.value}`);

interface CmpItem {
  key: string;
  label: string;
  mine: number | null;
  team: number | null;
  bias: number | null;
  kind: "pct" | "token" | "cost" | "days";
  note: string;
}

const items = computed<CmpItem[]>(() => {
  const d = props.data;
  const pu = d.perUser[props.user];
  if (!pu) return [];
  const model = props.model;
  const myToken = pu.models[model]?.tokens ?? 0;
  if (myToken <= 0) return [];
  const rows = d.rankByModel[model] ?? [];
  if (!rows.length) return [];

  // 该模型维度的活跃天数：daily 里该模型 token > 0 的天数
  const activeDays = (u: string) =>
    (d.perUser[u]?.daily ?? []).filter(x => (x.models?.[model] ?? 0) > 0).length;

  const each = rows.map(r => ({
    tokens: r.tokens,
    cost: r.cost,
    hit: r.hitRate != null ? parseFloat(r.hitRate) : null,
    days: activeDays(r.user),
  }));
  const mean = (f: (e: (typeof each)[number]) => number | null): number | null => {
    const vals = each.map(f).filter((v): v is number => v != null);
    return vals.length ? vals.reduce((a, b) => a + b, 0) / vals.length : null;
  };
  const team = {
    tokens: mean(e => e.tokens),
    cost: mean(e => e.cost),
    hit: mean(e => e.hit),
    days: mean(e => e.days),
  };

  const myRow = rows.find(r => r.user === props.user);
  const me = {
    tokens: myToken,
    cost: myRow?.cost ?? 0,
    hit: myRow?.hitRate != null ? parseFloat(myRow.hitRate) : null,
    days: activeDays(props.user),
  };

  const bias = (v: number | null, m: number | null): number | null => {
    if (v == null || m == null || m === 0) return null;
    const b = ((v - m) / Math.abs(m)) * 100;
    return Math.max(-100, Math.min(100, b));
  };

  return [
    { key: "tokens", label: "Token 用量", mine: me.tokens, team: team.tokens, bias: bias(me.tokens, team.tokens), kind: "token", note: "该模型总用量" },
    { key: "cost", label: "估算成本", mine: me.cost, team: team.cost, bias: bias(me.cost, team.cost), kind: "cost", note: "该模型估算成本" },
    { key: "hit", label: "缓存命中率", mine: me.hit, team: team.hit, bias: bias(me.hit, team.hit), kind: "pct", note: "越高越省钱" },
    { key: "days", label: "使用天数", mine: me.days, team: team.days, bias: bias(me.days, team.days), kind: "days", note: "统计期内使用该模型的天数" },
  ];
});

// 该模型在团队同模型大盘里的占比（分母从「你自己」换成「团队该模型」，单模型用户不再出现无意义的 100%）
const ratio = computed(() => {
  const pu = props.data.perUser[props.user];
  if (!pu) return null;
  const model = props.model;
  const t = pu.models[model]?.tokens ?? 0;
  const rows = props.data.rankByModel[model] ?? [];
  const c = rows.find(r => r.user === props.user)?.cost ?? 0;
  const sumT = rows.reduce((a, r) => a + r.tokens, 0);
  const sumC = rows.reduce((a, r) => a + r.cost, 0);
  const tp = sumT > 0 ? ((t / sumT) * 100).toFixed(1) : "0.0";
  const cp = sumC > 0 ? ((c / sumC) * 100).toFixed(1) : "0.0";
  return { text: `${modelLabel.value} token ${formatToken(t)} · 占团队${modelLabel.value} ${tp}%　|　成本 ${formatCost(c)} · 占团队${modelLabel.value}成本 ${cp}%` };
});

function fmtVal(i: CmpItem): string {
  const v = i.mine!;
  if (i.kind === "cost") return formatCost(v);
  if (i.kind === "token") return formatToken(Math.round(v));
  if (i.kind === "days") return Math.round(v) + " 天";
  return v.toFixed(1) + "%";
}
function fmtTeam(i: CmpItem): string {
  const v = i.team!;
  if (i.kind === "cost") return formatCost(v);
  if (i.kind === "token") return formatToken(Math.round(v));
  if (i.kind === "days") return Math.round(v) + " 天";
  return v.toFixed(1) + "%";
}

function barColor(color: string): echarts.graphic.LinearGradient {
  return new echarts.graphic.LinearGradient(0, 0, 1, 0, [{ offset: 0, color }, { offset: 1, color }]);
}

function render() {
  if (!el.value) return;
  // v-if 在空态/有图之间切换会替换 chart div：若实例绑定的 DOM 已不是当前 el，需重建
  // （否则 setOption 画到已从页面分离的旧 div，新 div 永远是空白）
  if (chart && chart.getDom() !== el.value) {
    chart.dispose();
    chart = null;
  }
  if (!chart) chart = echarts.init(el.value);
  const list = items.value.filter(i => i.mine != null && i.team != null);
  if (!list.length) return;
  const narrow = el.value.clientWidth < 500;
  const maxAbs = Math.max(5, ...list.map(i => Math.abs(i.bias ?? 0)));

  chart.setOption({
    tooltip: {
      trigger: "axis",
      axisPointer: { type: "shadow" },
      formatter: (params: any) => {
        const i = list[params?.[0]?.dataIndex];
        if (!i || i.bias == null) return "";
        const arrow = i.bias >= 0 ? "高于" : "低于";
        return `${i.label}（${i.note}）<br/>本人：${fmtVal(i)}<br/>团队${modelLabel.value}均值：${fmtTeam(i)}<br/><b>${arrow}团队${modelLabel.value}均值 ${Math.abs(i.bias).toFixed(1)}%</b>`;
      },
    },
    grid: narrow ? { left: 8, right: 16, top: 10, bottom: 20, containLabel: true } : { left: 8, right: 24, top: 14, bottom: 28, containLabel: true },
    xAxis: {
      type: "value",
      min: -maxAbs,
      max: maxAbs,
      axisLabel: { formatter: "{value}%", fontSize: narrow ? 10 : 12 },
      splitLine: { lineStyle: { type: "dashed" } },
    },
    yAxis: { type: "category", data: list.map(i => i.label), axisLabel: { fontSize: narrow ? 11 : 12, color: "#525a6e" } },
    series: [
      {
        type: "bar",
        barWidth: narrow ? 14 : 20,
        barMinWidth: 28,
        label: {
          show: true,
          position: "top",
          distance: 6,
          color: "#525a6e",
          fontWeight: 700,
          fontSize: 11,
          formatter: (p: any) => `${p.value > 0 ? "+" : ""}${p.value.toFixed(1)}%`,
        },
        data: list.map(i => ({
          value: i.bias,
          itemStyle: { borderRadius: 6, color: barColor(props.color) },
        })),
        markLine: {
          silent: true,
          symbol: "none",
          lineStyle: { color: "#a0a6b3", type: "dashed" },
          // 均值竖线只做参照，不显示「团队均值」label，避免压住中线附近的条形文字
          label: { show: false },
          data: [{ xAxis: 0 }],
        },
      },
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
// flush: post —— 等 DOM 更新后再渲染，否则从空态切到有图时 chart div 还没挂载，el 为 null 会提前 return，图表永远空白
watch(() => [props.user, props.data, props.model, props.label, props.color], render, { deep: true, flush: "post" });
</script>

<template>
  <div class="chart-wrap">
    <div v-if="ratio && items.length" class="ratio-line">{{ ratio.text }}</div>
    <div v-if="items.length" ref="el" class="chart"></div>
    <div v-else class="empty"><n-empty :description="emptyText" size="small" /></div>
    <div v-if="items.length" class="chart-foot">横轴 = 相对团队{{ modelLabel }}用户均值的偏差%：向右高于均值，向左低于均值</div>
  </div>
</template>

<style scoped>
.chart-wrap {
  position: relative;
}
.ratio-line {
  text-align: center;
  font-size: 11px;
  color: #8a8f99;
  margin: 2px 0 6px;
}
.chart {
  height: 320px;
  width: 100%;
}
.empty {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 320px;
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
  .empty {
    height: 260px;
  }
}
</style>
