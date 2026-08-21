<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import {
  zhCN,
  dateZhCN,
  NConfigProvider,
  NCard,
  NSpin,
  NAlert,
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
import RangeBar from "./components/RangeBar.vue";
import CardTitle from "./components/CardTitle.vue";
import ProfileWall from "./components/ProfileWall.vue";
import HologramPanel from "./components/HologramPanel.vue";
import SideNav from "./components/SideNav.vue";
import { fetchUsage, type UsageData, type PerUser } from "./api";
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

const data = ref<UsageData | null>(null);
const loading = ref(false);
const error = ref("");
const range = ref("今天");
const model = ref("all");
const user = ref<string[]>([]);
const scatterTab = ref<"io" | "hit">("io");
const selectedUser = ref<string | null>(null);
const personaExpanded = ref(false);
const personaHasMore = ref(false);
const holoUser = ref<string | null>(null);

async function query(r?: string) {
  const target = (r ?? range.value).trim();
  if (!target) return;
  range.value = target;
  loading.value = true;
  error.value = "";
  try {
    data.value = await fetchUsage(target);
  } catch (e: any) {
    error.value = e.message || "加载失败";
  } finally {
    loading.value = false;
  }
}

onMounted(() => query());

// 模型/人员下拉变更：更新筛选并立即重查（转圈反馈）
function onModelChange(v: string) {
  model.value = v;
  query();
}
function onUserChange(v: string[]) {
  user.value = v;
  query();
}

const userList = computed(() => (data.value ? data.value.rankTotal.map(r => r.user) : []));

const subtitle = computed(() => {
  if (!data.value) return "";
  const n = user.value.length;
  const who = n === 0 ? `共 ${data.value.meta.users} 人` : `只看 ${n} 人`;
  return `${data.value.range} · ${who}`;
});

// 选中人员的某项指标求和（用于合计视角）
function sumSelected(fn: (u: PerUser) => number): number {
  const d = data.value;
  if (!d) return 0;
  return user.value.reduce((s, name) => s + (d.perUser[name] ? fn(d.perUser[name]) : 0), 0);
}

const kpis = computed(() => {
  if (!data.value) return [];
  const m = data.value.meta;
  if (user.value.length === 0) {
    const outRatio = m.totalTokens > 0 ? ((m.totalOutput / m.totalTokens) * 100).toFixed(1) : null;
    return [
      { label: "用户数", value: String(m.users), desc: "统计范围内有调用记录的人数" },
      { label: "统计天数", value: String(m.days), desc: "范围内有数据的天数" },
      { label: "总 Token", value: formatToken(m.totalTokens), desc: "输入 + 输出的 token 总量" },
      { label: "人均总 Token", value: formatToken(m.avgTokens), desc: "总 Token ÷ 人数" },
      { label: "Pro Token", value: formatToken(m.proTokens), desc: "V4-Pro 模型的 token 总量" },
      { label: "Flash Token", value: formatToken(m.flashTokens), desc: "V4-Flash 模型的 token 总量" },
      { label: "总输入", value: formatToken(m.totalInput), desc: "缓存命中 + 未命中的输入 token" },
      { label: "总输出", value: formatToken(m.totalOutput), desc: "模型生成内容的输出 token" },
      { label: "输出占比", value: formatPercent(outRatio), color: outputRatioColor(outRatio), desc: "输出 ÷ 总 token：越低越偏「读判」，越高越偏「生成」" },
      { label: "估算成本", value: formatCost(m.estimatedCost), color: "#d03050", desc: "按 token 单价估算的费用" },
      { label: "实际扣费", value: formatCost(m.actualCost), color: "#d03050", desc: "DeepSeek 官方账单实际扣费" },
      { label: "Pro 缓存命中率", value: m.proCacheHitRate ? m.proCacheHitRate + "%" : "—", color: "#18a058", desc: "V4-Pro 缓存命中 ÷ (命中 + 未命中)" },
      { label: "Flash 缓存命中率", value: m.flashCacheHitRate ? m.flashCacheHitRate + "%" : "—", color: "#18a058", desc: "V4-Flash 缓存命中 ÷ (命中 + 未命中)" },
    ];
  }
  // 选中视角：这些人合计
  const n = user.value.length;
  const total = sumSelected(u => u.total);
  const pro = sumSelected(u => u.pro);
  const flash = sumSelected(u => u.flash);
  const input = sumSelected(u => u.input);
  const output = sumSelected(u => u.output);
  const cost = sumSelected(u => u.cost);
  const proCh = sumSelected(u => u.proCh);
  const proCm = sumSelected(u => u.proCm);
  const flashCh = sumSelected(u => u.flashCh);
  const flashCm = sumSelected(u => u.flashCm);
  const proHit = proCh + proCm > 0 ? ((proCh / (proCh + proCm)) * 100).toFixed(1) + "%" : "—";
  const flashHit = flashCh + flashCm > 0 ? ((flashCh / (flashCh + flashCm)) * 100).toFixed(1) + "%" : "—";
  const outRatio = total > 0 ? ((output / total) * 100).toFixed(1) : null;
  return [
    { label: "人数", value: String(n), desc: "当前选中的人数" },
    { label: "总 Token", value: formatToken(total), desc: "选中人员合计 token" },
    { label: "人均 Token", value: formatToken(n ? Math.round(total / n) : 0), desc: "合计 token ÷ 人数" },
    { label: "Pro Token", value: formatToken(pro), desc: "选中人员 V4-Pro token" },
    { label: "Flash Token", value: formatToken(flash), desc: "选中人员 V4-Flash token" },
    { label: "总输入", value: formatToken(input), desc: "选中人员输入 token 合计" },
    { label: "总输出", value: formatToken(output), desc: "选中人员输出 token 合计" },
    { label: "输出占比", value: formatPercent(outRatio), color: outputRatioColor(outRatio), desc: "输出 ÷ 总 token：越低越偏「读判」，越高越偏「生成」" },
    { label: "估算成本", value: formatCost(cost), color: "#d03050", desc: "选中人员估算成本合计" },
    { label: "Pro 命中率", value: proHit, color: "#18a058", desc: "选中人员 Pro 缓存命中率" },
    { label: "Flash 命中率", value: flashHit, color: "#18a058", desc: "选中人员 Flash 缓存命中率" },
  ];
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
  if (model.value === "pro") {
    rows = data.value.rankPro;
    cols = modelCols;
  } else if (model.value === "flash") {
    rows = data.value.rankFlash;
    cols = modelCols;
  } else {
    rows = data.value.rankTotal;
    cols = totalCols;
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
  // 跟随人员筛选：未选人时看全部，选中后只看选中的人（与画像墙一致）
  const rows = user.value.length === 0
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
  if (user.value.length === 0) return { data: data.value.trend, personal: false };
  // 合并选中人员的每日 token
  const m = new Map<string, { pro: number; flash: number }>();
  for (const name of user.value) {
    const pu = data.value.perUser[name];
    if (!pu) continue;
    for (const d of pu.daily) {
      const e = m.get(d.day) ?? { pro: 0, flash: 0 };
      e.pro += d.pro;
      e.flash += d.flash;
      m.set(d.day, e);
    }
  }
  const daily = [...m.entries()].sort((a, b) => a[0].localeCompare(b[0]))
    .map(([day, e]) => ({ day, pro: e.pro, flash: e.flash }));
  return { data: daily, personal: true };
});

const trendTitle = computed(() => {
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
const trendHelp = computed(() =>
  user.value.length === 0
    ? "左轴 = 费用（元）：Pro 估算、Flash 估算、实际扣费三条线\n右轴 = 命中率（虚线）：缓存命中 ÷ (命中 + 未命中) token"
    : "Pro / Flash 两条每日 Token 曲线\n个人命中率见下方点击行展开的详情面板"
);
const rankHelp = "按总 Token 降序排列，点击表头可切换排序\n命中率 <80% 标红、>95% 标绿\n点击行展开该用户每日趋势";
const personaHelp = "四维画像：规模（按成本分位）/ 模型偏好 / 使用模式 / 成本效率\n省钱 = 命中率 >95，费 = 命中率 <80\n读判型 = 输出占比 <0.5%，生成型 = 输出占比 >2%\nPro 党 = Pro token 占比 >66%";

// 画像分档始终按全团队算（避免筛选后单独看某人时标签乱变），展示随筛选过滤
// 雷达图「活跃」轴需要每人活跃天数 + 统计天数
const activityMap = computed(() => {
  const m = new Map<string, number>();
  if (data.value) {
    for (const [name, pu] of Object.entries(data.value.perUser)) m.set(name, pu.daily.length);
  }
  return m;
});
const personas = computed(() => computePersonas(data.value?.rankTotal ?? [], activityMap.value, data.value?.meta.days ?? 0));
const profileRows = computed(() => {
  if (!data.value) return [];
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
      pro: pu.pro,
      flash: pu.flash,
      input: r.input,
      output: r.output,
      outputRatio: r.outputRatio,
      cost: pu.cost,
      hitRate: r.hitRate,
      proHitRate: r.proHitRate,
      flashHitRate: r.flashHitRate,
    };
  }
  return map;
});
</script>

<template>
  <n-config-provider :locale="zhCN" :date-locale="dateZhCN" :theme-overrides="themeOverrides">
    <div class="app">
      <header class="header">
        <div class="title">
          <h1>DeepSeek 用量排行</h1>
          <span v-if="data" class="subtitle">{{ subtitle }}</span>
        </div>
        <p class="header-note">数据实时直查 DeepSeek API，统计存在延迟与口径落差，请以官方账单为准</p>
      </header>

      <RangeBar
        :users="userList"
        :model="model"
        :user="user"
        @query="query"
        @update:model="onModelChange"
        @update:user="onUserChange"
      />

      <n-alert v-if="error" type="error" :bordered="false" style="margin-bottom: 16px">
        {{ error }}
      </n-alert>

      <n-spin :show="loading">
        <template v-if="data">
          <div class="kpi-grid" id="kpi">
            <div v-for="k in kpis" :key="k.label">
              <StatCard :label="k.label" :value="k.value" :color="k.color" :desc="k.desc" />
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
            <ScatterChart :points="scatterPoints" :mode="scatterTab" />
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
            <ProfileWall :rows="profileRows" :personas="personas" :expanded="personaExpanded" @update:hasMore="personaHasMore = $event" @select="holoUser = $event" />
          </n-card>

          <n-card :bordered="false" class="block" size="small" id="trend">
            <template #header>
              <div class="card-head">
                <CardTitle :title="trendTitle" :help="trendHelp" />
              </div>
            </template>
            <TrendChart :data="trendView.data" :personal="trendView.personal" />
          </n-card>

          <n-card :bordered="false" class="block" size="small" id="rank">
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
            <UserTrendPanel
              v-if="selectedDaily"
              :user="selectedDaily.user"
              :daily="selectedDaily.daily"
              @close="selectedUser = null"
            />
          </n-card>
        </template>
      </n-spin>

      <transition name="holo-fade">
        <div v-if="holoUser" class="holo-mask" @click="holoUser = null">
          <div class="holo-wrap" @click.stop>
            <HologramPanel v-if="holoRow" :row="holoRow.row" :persona="holoRow.persona" />
          </div>
        </div>
      </transition>

      <SideNav :ready="!!data" :loading="loading" @refresh="query()" />
    </div>
  </n-config-provider>
</template>

<style>
body {
  margin: 0;
  background: linear-gradient(160deg, #eef1fb 0%, #f6f3ff 45%, #e9f4ff 100%);
  background-attachment: fixed;
  font-family: -apple-system, "PingFang SC", "Microsoft YaHei", "Segoe UI", sans-serif;
  color: #303133;
}
.app {
  max-width: 1800px;
  margin: 0 auto;
  padding: 24px 20px 48px;
}
.header {
  margin-bottom: 20px;
}
.title {
  display: flex;
  align-items: baseline;
  gap: 12px;
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
  font-size: 12px;
  color: #a0a6b3;
}
.block {
  --n-color: rgba(255, 255, 255, 0.62);
  border-radius: 16px;
  margin-bottom: 16px;
  background: rgba(255, 255, 255, 0.62);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border: 1px solid rgba(255, 255, 255, 0.75);
  box-shadow: 0 10px 40px rgba(79, 110, 247, 0.10);
}
.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  gap: 12px;
}
/* segment tabs 在 flex 里会被拉伸占满整行，收缩到内容宽度 */
.card-head .n-tabs {
  width: fit-content;
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
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 16px;
  margin-bottom: 16px;
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
