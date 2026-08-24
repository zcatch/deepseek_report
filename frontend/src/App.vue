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
import RangeBar from "./components/RangeBar.vue";
import CardTitle from "./components/CardTitle.vue";
import ProfileWall from "./components/ProfileWall.vue";
import HologramPanel from "./components/HologramPanel.vue";
import SideNav from "./components/SideNav.vue";
import { useSafeBottom } from "./useSafeBottom";
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

const data = ref<UsageData>(emptyUsage("今天"));
const loading = ref(false);
const range = ref("今天");
const model = ref("all");
const user = ref<string[]>([]);
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
    meta: {
      users: 0,
      days: 0,
      totalTokens: 0,
      proTokens: 0,
      flashTokens: 0,
      totalInput: 0,
      totalOutput: 0,
      avgTokens: 0,
      avgPro: 0,
      avgFlash: 0,
      estimatedCost: 0,
      actualCost: 0,
      proCacheHitRate: null,
      flashCacheHitRate: null,
      estLabel: "",
      actualLabel: "",
    },
    rankTotal: [],
    rankPro: [],
    rankFlash: [],
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
    if (j && !j.ok && (j as any).error) msg.value?.error((j as any).error);
  } catch (e: any) {
    data.value = emptyUsage(target);
    msg.value?.error(e.message || "加载失败");
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

// 清空筛选：模型/人员回默认，范围回「今天」（RangeBar 已重置自身预设高亮与日期）
function onReset() {
  model.value = "all";
  user.value = [];
  query("今天");
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
      // 9 张（3×3）：合并冗余（估算≈实际成本；用户数+天数），删总输入/总输出（输出占比已覆盖）
      { label: "用户数", value: `${m.users}人 · ${m.days}天`, desc: "统计范围内有调用记录的人数与天数", minor: true },
      { label: "总 Token", value: formatToken(m.totalTokens), desc: "输入 + 输出的 token 总量" },
      { label: "人均 Token", value: formatToken(m.avgTokens), desc: "总 Token ÷ 人数", minor: true },
      { label: "Pro Token", value: formatToken(m.proTokens), desc: "V4-Pro 模型的 token 总量", minor: true },
      { label: "Flash Token", value: formatToken(m.flashTokens), desc: "V4-Flash 模型的 token 总量", minor: true },
      { label: "输出占比", value: formatPercent(outRatio), color: outputRatioColor(outRatio), desc: "输出 ÷ 总 token：越低越偏「读判」，越高越偏「生成」" },
      { label: "成本", value: formatCost(m.actualCost), color: "#d03050", desc: "实际扣费（估算≈实际）" },
      { label: "Pro 命中率", value: m.proCacheHitRate ? m.proCacheHitRate + "%" : "—", color: "#18a058", desc: "V4-Pro 缓存命中 ÷ (命中 + 未命中)" },
      { label: "Flash 命中率", value: m.flashCacheHitRate ? m.flashCacheHitRate + "%" : "—", color: "#18a058", desc: "V4-Flash 缓存命中 ÷ (命中 + 未命中)" },
    ];
  }
  // 选中视角：这些人合计
  const n = user.value.length;
  const total = sumSelected(u => u.total);
  const pro = sumSelected(u => u.pro);
  const flash = sumSelected(u => u.flash);
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
    // 9 张（3×3）：删总输入/总输出（输出占比已覆盖），估算成本并入「成本」
    { label: "人数", value: String(n), desc: "当前选中的人数", minor: true },
    { label: "总 Token", value: formatToken(total), desc: "选中人员合计 token" },
    { label: "人均 Token", value: formatToken(n ? Math.round(total / n) : 0), desc: "合计 token ÷ 人数", minor: true },
    { label: "Pro Token", value: formatToken(pro), desc: "选中人员 V4-Pro token", minor: true },
    { label: "Flash Token", value: formatToken(flash), desc: "选中人员 V4-Flash token", minor: true },
    { label: "输出占比", value: formatPercent(outRatio), color: outputRatioColor(outRatio), desc: "输出 ÷ 总 token：越低越偏「读判」，越高越偏「生成」" },
    { label: "成本", value: formatCost(cost), color: "#d03050", desc: "选中人员估算成本合计" },
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
    ? "左轴 = 费用（元）：Pro 估算、Flash 估算、实际扣费三条线\n右轴 = 命中率：缓存命中 ÷ (命中 + 未命中) token"
    : "Pro / Flash 两条每日 Token 曲线\n个人命中率见下方点击行展开的详情面板"
);
const rankHelp = "按总 Token 降序排列，点击表头可切换排序\n命中率 <80% 标红、>95% 标绿\n点击行展开该用户每日趋势";
const personaHelp = "四维画像：规模 / 模型偏好 / 使用模式 / 成本效率\n规模：重度（成本前1/3）/ 中度 / 轻度\n模型：Pro（Pro>66%）/ Flash / 混用\n模式：读判（输出<0.5%）/ 生成（输出>2%）/ 均衡\n效率：省钱（命中>95%）/ 持平 / 费钱（<80%）\n成本强度：成本 ÷ 全团队最高成本";

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
    <n-message-provider ref="msg">
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
            <Teleport to="body">
              <UserTrendPanel
                v-if="selectedDaily"
                :user="selectedDaily.user"
                :daily="selectedDaily.daily"
                @close="selectedUser = null"
              />
            </Teleport>
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
.block {
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
