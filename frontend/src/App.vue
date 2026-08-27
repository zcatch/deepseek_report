<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import {
  zhCN,
  dateZhCN,
  NConfigProvider,
  NCard,
  NSpin,
  NMessageProvider,
  type MessageProviderInst,
  NSelect,
  NButton,
  NTabs,
  NTab,
} from "naive-ui";
import StatCard from "./components/StatCard.vue";
import RankTable from "./components/RankTable.vue";
import TrendChart from "./components/TrendChart.vue";
import ScatterChart from "./components/ScatterChart.vue";
import UserTrendPanel from "./components/UserTrendPanel.vue";
import CompareChart from "./components/CompareChart.vue";
import RangeBar from "./components/RangeBar.vue";
import CardTitle from "./components/CardTitle.vue";
import ProfileWall from "./components/ProfileWall.vue";
import HologramPanel from "./components/HologramPanel.vue";
import SideNav from "./components/SideNav.vue";
import { useSafeBottom } from "./useSafeBottom";
import { fetchUsage, CATEGORY_COLORS, type UsageData, type PerUser } from "./api";
import { formatToken, formatCost, formatPercent, outputRatioColor } from "./format";
import { computePersonas } from "./persona";

// 全局主题：统一主色为柔和蓝，按钮/下拉/日期/切换器一起生效
const themeOverrides = {
  common: {
    primaryColor: "#4f6ef7",
    primaryColorHover: "#6a85fa",
    primaryColorPressed: "#3e5ce6",
    primaryColorSuppl: "#6a85fa",
    borderRadius: "10px",
  },
  Card: { borderRadius: "16px" },
  Button: { borderRadiusMedium: "10px" },
};

const data = ref<UsageData>(emptyUsage("今天"));
const loading = ref(false);
const range = ref("今天");
const model = ref("all");
// 视角：null = 全员，否则 = 某用户（右上角身份切换器切换，localStorage 记住）
const viewer = ref<string | null>(null);
// 全员视角的人员筛选（多选）：KPI 显示选中合计，排行/画像/趋势/散点跟随过滤；个人视角下隐藏
const user = ref<string[]>([]);
const viewerOpen = ref(false);
const VIEWER_KEY = "ds_report_viewer";
const scatterTab = ref<"io" | "hit">("io");
const selectedUser = ref<string | null>(null);
const personaExpanded = ref(false);
const personaHasMore = ref(false);
const holoUser = ref<string | null>(null);

// 移动端画像墙「收起」悬浮按钮：地址栏动态视口补偿（贴可视区底部）
const { bottom: collapseBottom } = useSafeBottom(76);

// 气泡提示：NMessageProvider 实例（API 报错等用，几秒后自动消失）
const msg = ref<MessageProviderInst | null>(null);

// 空数据结构：区域始终渲染，各图表自行显示空态文案（如"暂无数据"）
function emptyUsage(r: string): UsageData {
  return {
    ok: true,
    range: r,
    startIso: "",
    endIso: "",
    unit: "",
    categories: [],
    meta: {
      users: 0,
      days: 0,
      totalTokens: 0,
      totalInput: 0,
      totalOutput: 0,
      avgTokens: 0,
      estimatedCost: 0,
      actualCost: 0,
      byModel: {},
      estLabel: "",
      actualLabel: "",
    },
    rankTotal: [],
    rankByModel: {},
    trend: [],
    perUser: {},
  };
}

async function query(r?: string) {
  const target = (r ?? range.value).trim();
  if (!target) return;
  range.value = target;
  loading.value = true;
  try {
    const j = await fetchUsage(target);
    // ok=false 也是正常响应（如范围无数据）：用空结构兜底，区域照常渲染，仅气泡提示
    data.value = j && j.ok ? j : emptyUsage(target);
    restoreViewer();
    if (j && !j.ok && (j as any).error) msg.value?.error((j as any).error);
  } catch (e: any) {
    data.value = emptyUsage(target);
    restoreViewer();
    msg.value?.error(e.message || "加载失败");
  } finally {
    loading.value = false;
  }
}

onMounted(() => query());

// 模型筛选变更：更新并立即重查（转圈反馈）
function onModelChange(v: string) {
  model.value = v;
  query();
}

// 人员筛选变更（多选）：更新并立即重查（转圈反馈）
function onUserChange(v: string[]) {
  user.value = v;
  query();
}

// 清空筛选：范围回「今天」、模型/人员回默认；视角身份不动（右上角切换器单独控制）
function onReset() {
  model.value = "all";
  user.value = [];
  query("今天");
}

// 视角持久化：记住上次选的个人/全员，刷新保留；用户不在当前数据里则自动回退全员
function restoreViewer() {
  try {
    const saved = localStorage.getItem(VIEWER_KEY);
    if (!saved || saved === "all") { viewer.value = null; return; }
    if (data.value && data.value.perUser[saved]) viewer.value = saved;
    else { localStorage.removeItem(VIEWER_KEY); viewer.value = null; }
  } catch {
    viewer.value = null;
  }
}
function setViewer(v: string | null) {
  viewer.value = v;
  viewerOpen.value = false;
  try {
    if (v) localStorage.setItem(VIEWER_KEY, v);
    else localStorage.removeItem(VIEWER_KEY);
  } catch {}
}

// 视角下拉菜单定位：fixed + JS 钳制在视口内，窄屏按钮被换行到任意位置都不会溢出屏幕左右
const viewerMenuStyle = computed(() => {
  if (!viewerOpen.value) return {};
  const btn = document.querySelector<HTMLElement>(".viewer-btn");
  if (!btn) return {};
  const r = btn.getBoundingClientRect();
  const w = 200; // 预估菜单宽度，与 CSS box-sizing:border-box 的 width 恒等
  const pad = 12;
  // 钳制目标：内容区右缘（减去滚动条宽）再留空隙，菜单永远不盖住右侧滚动条
  const sb = window.innerWidth - document.documentElement.clientWidth;
  const maxRight = window.innerWidth - sb - pad;
  let left = r.left;
  if (left + w > maxRight) left = Math.max(pad, maxRight - w);
  return { position: "fixed", left: `${left}px`, top: `${r.bottom + 6}px` };
});

const userList = computed(() => (data.value ? data.value.rankTotal.map(r => r.user) : []));

// 类别列表（含颜色）：key + label 来自后端，颜色按顺序循环取色；加新类别零改动
const cats = computed(() =>
  (data.value?.categories ?? []).map((c, i) => ({ key: c.key, label: c.label, color: CATEGORY_COLORS[i % CATEGORY_COLORS.length] }))
);

const subtitle = computed(() => {
  if (!data.value) return "";
  if (viewer.value) return `${data.value.range} · ${viewer.value} 的个人视角`;
  const n = user.value.length;
  return `${data.value.range} · ${n === 0 ? `共 ${data.value.meta.users} 人` : `只看 ${n} 人`}`;
});

// 当前视角用户在团队中的名次（个人 KPI「团队排名」卡用）
const rankOfViewer = computed(() => {
  if (!viewer.value || !data.value) return null;
  const r = data.value.rankTotal.find(x => x.user === viewer.value);
  return r ? r.rank : null;
});
// 图表组件只接受非空用户名的字符串（个人视角分支下 viewer 必非空）
const viewerName = computed(() => viewer.value ?? "");

// 全员视角选中人员的某项指标求和（用于合计视角）
function sumSelected(fn: (u: PerUser) => number): number {
  const d = data.value;
  if (!d) return 0;
  return user.value.reduce((s, name) => s + (d.perUser[name] ? fn(d.perUser[name]) : 0), 0);
}

const kpis = computed(() => {
  if (!data.value) return [];
  const m = data.value.meta;
  const catsList = cats.value;
  // 个人视角：换成该用户自己的指标
  if (viewer.value) {
    const pu = data.value.perUser[viewer.value];
    if (!pu) return [];
    const total = pu.total;
    const outRatio = total > 0 ? ((pu.output / total) * 100).toFixed(1) : null;
    const share = m.totalTokens > 0 ? ((total / m.totalTokens) * 100).toFixed(1) : null;
    const cards: any[] = [
      { label: "团队排名", value: rankOfViewer.value ? `#${rankOfViewer.value}` : "—", desc: `按总 Token 排名第 ${rankOfViewer.value ?? "—"} 名`, minor: true },
      { label: "总 Token", value: formatToken(total), desc: "输入 + 输出的 token 总量" },
      { label: "占团队", value: share ? share + "%" : "—", desc: "占团队总 token 的比例", minor: true },
    ];
    for (const c of catsList) {
      cards.push({ label: `${c.label} Token`, value: formatToken(pu.models[c.key]?.tokens ?? 0), desc: `${c.label} 模型的 token 量`, minor: true });
    }
    cards.push(
      { label: "输出占比", value: formatPercent(outRatio), color: outputRatioColor(outRatio), desc: "输出 ÷ 总 token：越低越偏「读判」，越高越偏「生成」" },
      { label: "成本", value: formatCost(pu.cost), color: "#d03050", desc: "估算成本合计" },
    );
    for (const c of catsList) {
      const mc = pu.models[c.key];
      const hit = mc && mc.ch + mc.cm > 0 ? ((mc.ch / (mc.ch + mc.cm)) * 100).toFixed(1) : null;
      cards.push({ label: `${c.label} 命中率`, value: hit ? hit + "%" : "—", color: "#18a058", desc: `${c.label} 缓存命中 ÷ (命中 + 未命中)` });
    }
    return cards;
  }
  // 全员视角：选中了人员 → 这些人的合计
  const n = user.value.length;
  if (n > 0) {
    const total = sumSelected(u => u.total);
    const output = sumSelected(u => u.output);
    const cost = sumSelected(u => u.cost);
    const outRatio = total > 0 ? ((output / total) * 100).toFixed(1) : null;
    const cards: any[] = [
      { label: "人数", value: String(n), desc: "当前选中的人数", minor: true },
      { label: "总 Token", value: formatToken(total), desc: "选中人员合计 token" },
      { label: "人均 Token", value: formatToken(n ? Math.round(total / n) : 0), desc: "合计 token ÷ 人数", minor: true },
    ];
    for (const c of catsList) {
      cards.push({ label: `${c.label} Token`, value: formatToken(sumSelected(u => u.models[c.key]?.tokens ?? 0)), desc: `选中人员 ${c.label} token`, minor: true });
    }
    cards.push(
      { label: "输出占比", value: formatPercent(outRatio), color: outputRatioColor(outRatio), desc: "输出 ÷ 总 token：越低越偏「读判」，越高越偏「生成」" },
      { label: "成本", value: formatCost(cost), color: "#d03050", desc: "选中人员估算成本合计" },
    );
    for (const c of catsList) {
      const ch = sumSelected(u => u.models[c.key]?.ch ?? 0);
      const cm = sumSelected(u => u.models[c.key]?.cm ?? 0);
      const hit = ch + cm > 0 ? ((ch / (ch + cm)) * 100).toFixed(1) + "%" : "—";
      cards.push({ label: `${c.label} 命中率`, value: hit, color: "#18a058", desc: `选中人员 ${c.label} 缓存命中率` });
    }
    return cards;
  }
  // 全员视角：团队汇总
  const outRatio = m.totalTokens > 0 ? ((m.totalOutput / m.totalTokens) * 100).toFixed(1) : null;
  const cards: any[] = [
    { label: "用户数", value: `${m.users}人 · ${m.days}天`, desc: "统计范围内有调用记录的人数与天数", minor: true },
    { label: "总 Token", value: formatToken(m.totalTokens), desc: "输入 + 输出的 token 总量" },
    { label: "人均 Token", value: formatToken(m.avgTokens), desc: "总 Token ÷ 人数", minor: true },
  ];
  for (const c of catsList) {
    cards.push({ label: `${c.label} Token`, value: formatToken(m.byModel[c.key]?.tokens ?? 0), desc: `${c.label} 模型的 token 总量`, minor: true });
  }
  cards.push(
    { label: "输出占比", value: formatPercent(outRatio), color: outputRatioColor(outRatio), desc: "输出 ÷ 总 token：越低越偏「读判」，越高越偏「生成」" },
    { label: "成本", value: formatCost(m.actualCost), color: "#d03050", desc: "实际扣费（估算≈实际）" },
  );
  for (const c of catsList) {
    const hit = m.byModel[c.key]?.hitRate;
    cards.push({ label: `${c.label} 命中率`, value: hit ? hit + "%" : "—", color: "#18a058", desc: `${c.label} 缓存命中 ÷ (命中 + 未命中)` });
  }
  return cards;
});

const totalCols = [
  { key: "total", label: "总 Token", type: "token" },
  { key: "cost", label: "成本", type: "cost" },
  { key: "hitRate", label: "命中率", type: "hit", help: "缓存命中 ÷ (命中 + 未命中) token，越高越省钱" },
  { key: "outputRatio", label: "输出占比", type: "ratio", help: "输出 ÷ 总 token\n越低越偏「读判」（批量读取/分类/摘要）\n越高越偏「生成」（写代码/长文）" },
];
const modelCols = [
  { key: "tokens", label: "Token", type: "token" },
  { key: "cost", label: "成本", type: "cost" },
  { key: "hitRate", label: "缓存命中率", type: "hit", help: "缓存命中 ÷ (命中 + 未命中) token，越高越省钱" },
  { key: "cacheHit", label: "缓存命中", type: "int" },
  { key: "cacheMiss", label: "缓存未命中", type: "int" },
  { key: "output", label: "输出", type: "int" },
];

const rankView = computed(() => {
  if (!data.value) return { rows: [], cols: totalCols };
  let rows: any[];
  let cols: { key: string; label: string; type?: string; help?: string }[];
  if (model.value === "all") {
    rows = data.value.rankTotal;
    cols = totalCols;
  } else {
    rows = data.value.rankByModel[model.value] ?? [];
    cols = modelCols;
  }
  if (user.value.length > 0) {
    const set = new Set(user.value);
    rows = rows.filter((r: any) => set.has(r.user));
  }
  return { rows, cols };
});

function onScatterTabChange(v: string | number | null) {
  scatterTab.value = v === "hit" ? "hit" : "io";
}

const scatterPoints = computed(() => {
  if (!data.value) return [];
  // 个人视角始终看全团队分布（highlight 高亮当前用户）；全员视角跟随人员筛选
  const rows = viewer.value || user.value.length === 0
    ? data.value.rankTotal
    : data.value.rankTotal.filter(r => user.value.includes(r.user));
  return rows.map(r => ({ user: r.user, total: r.total, hitRate: r.hitRate, cost: r.cost, input: r.input, output: r.output, outputRatio: r.outputRatio }));
});

// 散点图两个视角的注解说明（\n 在气泡里换行显示）
const scatterNote = computed(() =>
  scatterTab.value === "hit"
    ? "横轴 = 总 Token，纵轴 = 缓存命中率\n气泡大小 = 成本；命中率越高越省钱"
    : "横轴 = 输入 Token，纵轴 = 输出 Token\n气泡大小 = 成本；越靠左越偏「读判」，越靠右上越偏「生成」"
);

function onSelectUser(u: string) {
  selectedUser.value = selectedUser.value === u ? null : u;
}

const selectedDaily = computed(() => {
  if (!selectedUser.value || !data.value) return null;
  const daily = data.value.perUser[selectedUser.value]?.daily;
  return daily ? { user: selectedUser.value, daily } : null;
});

const trendView = computed(() => {
  if (!data.value) return { data: [], personal: false };
  if (viewer.value) {
    const pu = data.value.perUser[viewer.value];
    return { data: pu?.daily ?? [], personal: true };
  }
  if (user.value.length === 0) return { data: data.value.trend, personal: false };
  // 合并选中人员的每日各类 token
  const m = new Map<string, Record<string, number>>();
  for (const name of user.value) {
    const pu = data.value.perUser[name];
    if (!pu) continue;
    for (const d of pu.daily) {
      const e = m.get(d.day) ?? {};
      for (const [k, v] of Object.entries(d.models)) e[k] = (e[k] ?? 0) + v;
      m.set(d.day, e);
    }
  }
  const daily = [...m.entries()].sort((a, b) => a[0].localeCompare(b[0]))
    .map(([day, e]) => ({ day, models: e }));
  return { data: daily, personal: true };
});

const trendTitle = computed(() => {
  if (viewer.value) return "每日 Token 趋势";
  const n = user.value.length;
  if (n === 0) return "每日费用趋势";
  if (n === 1) return `${user.value[0]} 每日 Token 趋势`;
  return `选中 ${n} 人 每日 Token 趋势`;
});

const rankTitle = computed(() => {
  const n = user.value.length;
  if (n === 0) return "用量排行";
  if (n === 1) return `${user.value[0]} 的用量明细`;
  return `用量明细（${n} 人）`;
});

// 卡片标题旁的问号注解（\n 换行）
const trendHelp = computed(() => {
  if (viewer.value) return "各类模型每日 Token 曲线，看个人用量走势\n命中率与多维度对比见下方「个人分析」";
  if (user.value.length === 0) return "左轴 = 费用（元）：各类估算、实际扣费\n右轴 = 命中率：缓存命中 ÷ (命中 + 未命中) token";
  return "各类模型每日 Token 曲线（选中人员每日合计）\n命中率见下方排行行点击展开的详情面板";
});
const rankHelp = "按总 Token 降序排列，点击表头可切换排序\n命中率 <80% 标红、>95% 标绿\n点击行展开该用户每日趋势";
// 个人分析卡注解（个人视角下 rank 区块替换成两张模型分析卡）
const personalHelp = "每类模型各一张卡：该模型维度的 Token、成本、缓存命中率、使用天数，相对团队同模型用户的偏差%\n没用某类模型的用户，该类卡显示空态";
const personaHelp = "四维画像：规模 / 模型偏好 / 使用模式 / 成本效率\n规模：重度（成本前1/3）/ 中度 / 轻度\n模型：占比最高类（>66%）标该类名，否则「混用」\n模式：读判（输出<0.5%）/ 生成（输出>2%）/ 均衡\n效率：省钱（命中>95%）/ 持平 / 费钱（<80%）\n成本强度：成本 ÷ 全团队最高成本";

// 画像分档始终按全团队算（避免筛选后单独看某人时标签乱变），展示随筛选过滤
// 雷达图「活跃」轴需要每人活跃天数 + 统计天数
const activityMap = computed(() => {
  const m = new Map<string, number>();
  if (data.value) {
    for (const [name, pu] of Object.entries(data.value.perUser)) m.set(name, pu.daily.length);
  }
  return m;
});
const personas = computed(() => computePersonas(data.value?.rankTotal ?? [], activityMap.value, data.value?.meta.days ?? 0, data.value?.categories ?? []));
const profileRows = computed(() => {
  if (!data.value) return [];
  // 个人视角聚焦当前用户；全员视角跟随人员筛选
  if (viewer.value) return data.value.rankTotal.filter(r => r.user === viewer.value);
  if (user.value.length === 0) return data.value.rankTotal;
  const set = new Set(user.value);
  return data.value.rankTotal.filter(r => set.has(r.user));
});

// 全息面板数据：点击画像卡后找到对应 row + persona
const holoRow = computed(() => {
  if (!holoUser.value || !data.value) return null;
  const r = data.value.rankTotal.find(x => x.user === holoUser.value);
  return r ? { row: r, persona: personas.value.get(r.user)! } : null;
});

// hover 气泡详情：每人 rank / 占比 / token / 成本 / 命中率
const detailMap = computed(() => {
  const d = data.value;
  if (!d) return {};
  const map: Record<string, any> = {};
  // rank 用当前视图的排名（排序/过滤后），而非原始 rankTotal
  const rankOf: Record<string, number> = {};
  for (const row of rankView.value.rows) rankOf[row.user] = row.rank;
  for (const r of d.rankTotal) {
    const pu = d.perUser[r.user];
    if (!pu) continue;
    map[r.user] = {
      user: r.user,
      rank: rankOf[r.user] ?? r.rank,
      share: d.meta.totalTokens ? +((pu.total / d.meta.totalTokens) * 100).toFixed(1) : 0,
      total: pu.total,
      input: r.input,
      output: r.output,
      outputRatio: r.outputRatio,
      cost: pu.cost,
      hitRate: r.hitRate,
      models: cats.value.map(c => ({ label: c.label, tokens: r.models[c.key]?.tokens ?? 0, hitRate: r.models[c.key]?.hitRate ?? null })),
    };
  }
  return map;
});
</script>

<template>
  <n-config-provider :locale="zhCN" :date-locale="dateZhCN" :theme-overrides="themeOverrides">
    <n-message-provider ref="msg">
      <div class="app">
      <header class="header">
        <div class="title">
          <h1>DeepSeek 用量排行</h1>
          <span v-if="data" class="subtitle">{{ subtitle }}</span>
        </div>
        <div v-if="data" class="viewer-wrap">
          <button class="viewer-btn" :class="{ personal: viewer }" :title="viewer ? '切换视角' : '切换到某人的个人视角'" @click="viewerOpen = !viewerOpen">
            <svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true">
              <circle cx="8" cy="5" r="3" fill="none" stroke="currentColor" stroke-width="1.5" />
              <path d="M3 13c0-2.5 2.2-3.5 5-3.5s5 1 5 3.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
            </svg>
            <span class="viewer-name">{{ viewer ?? "全员" }}</span>
            <svg class="viewer-caret" viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
              <path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </button>
          <div v-if="viewerOpen" class="viewer-mask" @click="viewerOpen = false"></div>
          <div v-if="viewerOpen" class="viewer-menu" :style="viewerMenuStyle" @click.stop>
            <button class="viewer-opt" :class="{ active: !viewer }" @click="setViewer(null)">全员视角</button>
            <div class="viewer-divider" />
            <button
              v-for="u in userList"
              :key="u"
              class="viewer-opt"
              :class="{ active: viewer === u }"
              @click="setViewer(u)"
            >{{ u }}</button>
          </div>
        </div>
        <p class="header-note">数据实时直查 DeepSeek API，统计存在延迟与口径落差，请以官方账单为准</p>
      </header>

      <RangeBar
        :users="userList"
        :model="model"
        :user="user"
        :cats="cats"
        :show-user="!viewer"
        @query="query"
        @update:model="onModelChange"
        @update:user="onUserChange"
        @reset="onReset"
      />

      <n-spin :show="loading">
        <template v-if="data">
          <div class="kpi-grid" id="kpi">
            <div v-for="k in kpis" :key="k.label">
              <StatCard :label="k.label" :value="k.value" :color="k.color" :desc="k.desc" :minor="k.minor" />
            </div>
          </div>

          <n-card :bordered="false" class="block" size="small" id="scatter">
            <template #header>
              <div class="card-head">
                <CardTitle title="Token 结构分布" :help="scatterNote" />
                <n-tabs type="segment" size="small" :value="scatterTab" @update:value="onScatterTabChange">
                  <n-tab name="io">输入×输出</n-tab>
                  <n-tab name="hit">命中率×Token</n-tab>
                </n-tabs>
              </div>
            </template>
            <ScatterChart :points="scatterPoints" :mode="scatterTab" :highlight="viewer ?? undefined" />
          </n-card>

          <n-card :bordered="false" class="block" size="small" id="persona">
            <template #header>
              <div class="card-head">
                <CardTitle title="人员画像" :help="personaHelp" />
                <n-button v-if="personaHasMore" size="small" secondary @click="personaExpanded = !personaExpanded">
                  <span class="toggle-inner">
                    {{ personaExpanded ? "收起" : `展开全部 ${profileRows.length} 人` }}
                    <svg v-if="!personaExpanded" class="toggle-icon" viewBox="0 0 16 16" width="14" height="14"><path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    <svg v-else class="toggle-icon" viewBox="0 0 16 16" width="14" height="14"><path d="M4 10l4-4 4 4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                  </span>
                </n-button>
              </div>
            </template>
            <ProfileWall :rows="profileRows" :personas="personas" :cats="cats" :expanded="personaExpanded" @update:hasMore="personaHasMore = $event" @select="holoUser = $event" />
          </n-card>

          <n-card :bordered="false" class="block" size="small" id="trend">
            <template #header>
              <div class="card-head">
                <CardTitle :title="trendTitle" :help="trendHelp" />
              </div>
            </template>
            <TrendChart :data="trendView.data" :personal="trendView.personal" :cats="cats" />
          </n-card>

          <n-card v-if="!viewer" :bordered="false" class="block" size="small" id="rank">
            <template #header>
              <div class="card-head">
                <CardTitle :title="rankTitle" :help="rankHelp" />
              </div>
            </template>
            <RankTable
              :data="rankView.rows"
              :columns="rankView.cols"
              :details="detailMap"
              :selected-user="selectedUser"
              @select="onSelectUser"
            />
            <Teleport to="body">
              <UserTrendPanel
                v-if="selectedDaily"
                :user="selectedDaily.user"
                :daily="selectedDaily.daily"
                :cats="cats"
                @close="selectedUser = null"
              />
            </Teleport>
          </n-card>

          <!-- 个人视角：两张图各自独立成卡，直接排在页面上（对齐顶部 KPI 卡风格），中间露出页面背景 -->
          <div v-else class="pa-block" id="rank">
            <div class="card-head pa-head">
              <CardTitle title="个人分析" :help="personalHelp" />
            </div>
            <div class="pa-grid">
              <div v-for="c in cats" :key="c.key" class="pa-card">
                <div class="pa-sub">{{ c.label }} 分析</div>
                <CompareChart :user="viewerName" :data="data" :model="c.key" :label="c.label" :color="c.color" />
              </div>
            </div>
          </div>
        </template>
      </n-spin>

      <transition name="holo-fade">
        <div v-if="holoUser" class="holo-mask" @click="holoUser = null">
          <div class="holo-wrap" @click.stop>
            <HologramPanel v-if="holoRow" :row="holoRow.row" :persona="holoRow.persona" :cats="cats" />
          </div>
        </div>
      </transition>

      <SideNav :ready="!!data" :loading="loading" :personal="!!viewer" @refresh="query()" />

      <!-- 移动端：画像墙展开较多时，右下角浮动「收起」按钮（叠在侧导航 FAB 上方） -->
      <transition name="pcoll">
        <button
          v-if="personaExpanded"
          class="persona-collapse-fab"
          :style="{ bottom: collapseBottom + 'px' }"
          title="收起人员画像"
          aria-label="收起人员画像"
          @click="personaExpanded = false"
        >
          <svg viewBox="0 0 16 16" width="18" height="18" aria-hidden="true">
            <path d="M2 8h6M4.5 5.5L8 8l-3.5 2.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M14 8H8M11.5 5.5L8 8l3.5 2.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
      </transition>
      </div>
    </n-message-provider>
  </n-config-provider>
</template>

<style>
body {
  margin: 0;
  background: linear-gradient(160deg, #eef1fb 0%, #f6f3ff 45%, #e9f4ff 100%);
  font-family: -apple-system, "PingFang SC", "Microsoft YaHei", "Segoe UI", sans-serif;
  color: #303133;
}
.app {
  max-width: 1800px;
  margin: 0 auto;
  padding: 24px 20px 36px;
}
/* 移动端：收窄内边距，底部留出「回顶」悬浮钮空间 */
@media (max-width: 768px) {
  .app {
    padding: 16px 12px 72px;
  }
}
.header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  column-gap: 16px;
  row-gap: 8px;
  margin-bottom: 20px;
}
.title {
  display: flex;
  align-items: baseline;
  gap: 12px;
  min-width: 0;
}
.title h1 {
  margin: 0 0 4px;
  font-size: 24px;
  font-weight: 700;
  background: linear-gradient(90deg, #3e5ce6, #7c5cff);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
}
.subtitle {
  color: #8a8f99;
  font-size: 14px;
}
.header-note {
  margin: 2px 0 0;
  width: 100%;
  font-size: 12px;
  color: #a0a6b3;
}
/* 移动端：标题降字号，副标题与说明换行不溢出 */
@media (max-width: 768px) {
  .title h1 {
    font-size: 20px;
  }
  .subtitle {
    font-size: 12px;
  }
  .header-note {
    font-size: 11px;
  }
}
/* 右上角身份切换器：毛玻璃蓝底 + 个人态渐变蓝白字，贴合主题 */
.viewer-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  height: 36px;
  padding: 0 14px;
  font-size: 13px;
  font-weight: 600;
  color: #4f6ef7;
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(79, 110, 247, 0.30);
  border-radius: 12px;
  cursor: pointer;
  box-shadow: 0 8px 24px rgba(79, 110, 247, 0.12);
  transition: all 0.2s;
}
.viewer-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 10px 28px rgba(79, 110, 247, 0.20);
}
.viewer-btn.personal {
  color: #fff;
  background: linear-gradient(135deg, #3e5ce6, #7c5cff);
  border-color: transparent;
}
.viewer-name {
  max-width: 150px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.viewer-caret {
  flex-shrink: 0;
  opacity: 0.75;
}
/* 身份切换器容器：菜单绝对定位锚点 */
.viewer-wrap {
  position: relative;
}
/* 透明遮罩：点菜单外任意处关闭（不拦截菜单本身点击） */
.viewer-mask {
  position: fixed;
  inset: 0;
  z-index: 1999;
  background: transparent;
}
/* 视角下拉菜单：定位由 viewerMenuStyle 内联（fixed + 视口内钳制），这里只留视觉与尺寸。
   固定 width + box-sizing:border-box 让总宽恒为 200px，与 JS 的预估值精确一致 */
.viewer-menu {
  position: fixed;
  z-index: 2000;
  width: 200px;
  box-sizing: border-box;
  max-height: 320px;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 4px;
  background: #fff;
  border: 1px solid rgba(79, 110, 247, 0.12);
  border-radius: 12px;
  box-shadow: 0 12px 32px rgba(79, 110, 247, 0.18);
}
.viewer-opt {
  display: flex;
  width: 100%;
  padding: 7px 12px;
  border: none;
  background: transparent;
  border-radius: 8px;
  color: #303133;
  font-size: 13px;
  text-align: left;
  cursor: pointer;
  white-space: nowrap;
}
.viewer-opt:hover {
  background: rgba(79, 110, 247, 0.08);
  color: #4f6ef7;
}
.viewer-opt.active {
  background: rgba(79, 110, 247, 0.14);
  color: #4f6ef7;
  font-weight: 600;
}
.viewer-divider {
  height: 1px;
  margin: 4px 6px;
  background: rgba(79, 110, 247, 0.14);
}
/* 个人分析：不套大卡，两张图各自独立成卡直接排在页面上（对齐顶部 KPI 卡风格）。
   中间由页面背景自然间隔；minmax(0,1fr) + min-width:0 防 ECharts canvas 内联宽度撑破 grid 列（横向溢出） */
.pa-block {
  scroll-margin-top: 16px;
  margin-bottom: 16px;
}
.pa-head {
  margin-bottom: 12px;
}
.pa-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
}
.pa-card {
  min-width: 0;
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(255, 255, 255, 0.75);
  border-radius: 16px;
  padding: 14px 16px 10px;
  box-shadow: 0 10px 40px rgba(79, 110, 247, 0.10);
}
.pa-sub {
  font-size: 12px;
  color: #8a8f99;
  margin-bottom: 6px;
}
@media (max-width: 768px) {
  .pa-grid {
    grid-template-columns: minmax(0, 1fr);
    gap: 12px;
  }
  .viewer-btn {
    height: 32px;
    padding: 0 11px;
    font-size: 12px;
  }
}.block {
  --n-color: rgba(255, 255, 255, 0.92);
  border-radius: 16px;
  margin-bottom: 16px;
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(255, 255, 255, 0.75);
  box-shadow: 0 10px 40px rgba(79, 110, 247, 0.10);
}
.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  row-gap: 6px;
  width: 100%;
  gap: 12px;
}
/* segment tabs 在 flex 里会被拉伸占满整行，收缩到内容宽度 */
.card-head .n-tabs {
  width: fit-content;
  max-width: 100%;
}
/* 展开/收起按钮：文字 + 右侧 chevron 图标 */
.toggle-inner {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.toggle-icon {
  display: block;
}
/* 全息面板弹层：自绘遮罩（点遮罩关闭）+ 3D 翻转 perspective */
.holo-mask {
  position: fixed;
  inset: 0;
  z-index: 2000;
  background: rgba(15, 18, 35, 0.5);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}
.holo-fade-enter-active,
.holo-fade-leave-active {
  transition: opacity 0.25s ease;
}
.holo-fade-enter-from,
.holo-fade-leave-to {
  opacity: 0;
}
.holo-wrap {
  position: relative;
  width: 100%;
  max-width: 280px;
  perspective: 1200px;
}
/* 移动端：全息面板固定宽度居中（全宽会太空），超窄屏时让 margin 兜底 */
@media (max-width: 768px) {
  .holo-mask {
    padding: 16px;
  }
  .holo-wrap {
    max-width: 300px;
    width: 100%;
  }
}
/* 画像墙「收起」浮动按钮：仅移动端显示（桌面端顶部按钮 + 右侧导航已够用，无需浮动钮）。
   移动端展开画像墙后叠在侧导航 FAB 上方；圆形毛玻璃钮，
   左右向内收缩箭头（区别于置顶的上箭头） */
.persona-collapse-fab {
  display: none;
  position: fixed;
  right: 14px;
  bottom: 76px;
  z-index: 690;
  width: 46px;
  height: 46px;
  border-radius: 50%;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.9);
  border: 1px solid rgba(255, 255, 255, 0.75);
  box-shadow: 0 10px 28px rgba(79, 110, 247, 0.28);
  color: #4f6ef7;
  cursor: pointer;
}
.persona-collapse-fab:active {
  background: rgba(79, 110, 247, 0.12);
}
.pcoll-enter-active,
.pcoll-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.pcoll-enter-from,
.pcoll-leave-to {
  opacity: 0;
  transform: translateY(6px);
}
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 16px;
  margin-bottom: 16px;
}
/* 移动端：KPI 卡 3 列排 + 画像收起浮动钮显示 */
@media (max-width: 768px) {
  .kpi-grid {
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
  }
  .persona-collapse-fab {
    display: flex;
  }
}
.kpi-grid,
.block {
  scroll-margin-top: 16px;
}
/* segment 切换器：选中态靠 .n-tabs-capsule 滑块（Naive UI 默认白色）盖在 active tab 上，改成蓝色滑块 */
.n-tabs--segment-type .n-tabs-rail {
  background: #f0f2f5;
  border-radius: 10px;
  padding: 2px;
  gap: 2px;
}
.n-tabs--segment-type .n-tabs-capsule {
  background: #4f6ef7 !important;
  border-radius: 8px !important;
}
.n-tabs--segment-type .n-tabs-tab[data-name] {
  border-radius: 8px !important;
  padding: 4px 14px !important;
  color: #525a6e;
}
.n-tabs--segment-type .n-tabs-tab[data-name]:hover {
  color: #4f6ef7;
}
.n-tabs--segment-type .n-tabs-tab--active {
  color: #fff !important;
}
</style>
