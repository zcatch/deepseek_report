<script setup lang="ts">
import { computed, h, ref, onBeforeUnmount } from "vue";
import { NDataTable, NEmpty, NPopover, type DataTableColumns } from "naive-ui";
import { formatToken, formatInt, formatCost, formatHitRate, hitRateColor, formatPercent, outputRatioColor } from "../format";
import UserDetail from "./UserDetail.vue";

const props = defineProps<{
  data: Record<string, any>[];
  columns: { key: string; label: string; type?: "token" | "cost" | "int" | "hit" | "ratio"; help?: string }[];
  details?: Record<string, any>;
  selectedUser?: string | null;
}>();

const emit = defineEmits<{ (e: "select", user: string): void }>();

const MEDALS = ["🥇", "🥈", "🥉"];

// 移动端（触屏）无 hover：禁用行气泡 + 表头问号改点击触发
const isMobile = ref(false);
function updateIsMobile() {
  isMobile.value = window.matchMedia("(max-width: 768px)").matches;
}
updateIsMobile();
window.addEventListener("resize", updateIsMobile);

// 受控气泡：整行悬停触发，跟随鼠标（偏移 14px）
const pop = ref<{ show: boolean; x: number; y: number; user: string | null }>({ show: false, x: 0, y: 0, user: null });

// 延迟隐藏：打断「离开行→气泡消失→鼠标回行→又出现」的抖动循环
let hideTimer: ReturnType<typeof setTimeout> | null = null;
function clearHide() {
  if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
}
function scheduleHide(user: string) {
  clearHide();
  hideTimer = setTimeout(() => {
    if (pop.value.user === user) pop.value.show = false;
    hideTimer = null;
  }, 200);
}
onBeforeUnmount(clearHide);

function locate(e: MouseEvent, user: string) {
  pop.value = { show: true, x: e.clientX + 14, y: e.clientY + 14, user };
}

const cols = computed<DataTableColumns>(() => [
  {
    key: "rank",
    title: "排名",
    width: 72,
    align: "center",
    render: (row: any) => MEDALS[row.rank - 1] ?? String(row.rank),
  },
  {
    key: "user",
    title: "用户",
    width: 140,
    render: (row: any) => h("span", { class: "user-cell" }, row.user),
  },
  ...props.columns.map(c => {
    const col: any = {
      key: c.key,
      title: c.label,
      align: "right",
      render: (row: any) => {
        const v = row[c.key];
        if (c.type === "token") return formatToken(v);
        if (c.type === "cost") return formatCost(v);
        if (c.type === "int") return formatInt(v);
        if (c.type === "hit") return h("span", { style: { color: hitRateColor(v) } }, formatHitRate(v));
        if (c.type === "ratio") return h("span", { style: { color: outputRatioColor(v) } }, formatPercent(v));
        return v;
      },
    };
    // 表头问号说明：hover 整个表头文字即弹出（不只问号）；触屏无 hover → 改点击
    if (c.help) {
      col.title = () => h(NPopover, { trigger: isMobile.value ? "click" : "hover", placement: "top" }, {
        trigger: () => h("span", { class: "th-head" }, [
          c.label,
          h("span", { class: "help-icon" }, "?"),
        ]),
        default: () => h("div", { class: "help-desc" }, c.help),
      });
    }
    // 表头排序：数值列直接比；百分比列（string|null）转数字比，null 沉底
    if (c.type === "hit" || c.type === "ratio") {
      col.sorter = (a: any, b: any) => {
        const av = a[c.key] == null ? -Infinity : parseFloat(a[c.key]);
        const bv = b[c.key] == null ? -Infinity : parseFloat(b[c.key]);
        return av - bv;
      };
    } else if (c.type === "token" || c.type === "cost" || c.type === "int") {
      col.sorter = (a: any, b: any) => (a[c.key] ?? 0) - (b[c.key] ?? 0);
    }
    return col;
  }),
]);

const rowProps = (row: any) => {
  const selected = props.selectedUser === row.user;
  const base: Record<string, any> = { cursor: "pointer" };
  if (selected) base.background = "#d8f3e6";
  else if (row.rank === 1) base.background = "#fdf6e3";
  else if (row.rank === 2) base.background = "#f5f7fa";
  else if (row.rank === 3) base.background = "#fdf0e6";
  const hasDetail = !!props.details?.[row.user];
  return {
    style: base,
    onClick: () => emit("select", row.user),
    onMouseenter: (e: MouseEvent) => {
      if (isMobile.value || !hasDetail) return;
      clearHide();
      locate(e, row.user);
    },
    onMousemove: (e: MouseEvent) => {
      if (isMobile.value || !hasDetail) return;
      clearHide();
      locate(e, row.user);
    },
    onMouseleave: () => {
      if (isMobile.value || !hasDetail) return;
      scheduleHide(row.user);
    },
  };
};

const popDetail = computed(() => (pop.value.user ? props.details?.[pop.value.user] : null));
</script>

<template>
  <div class="table-scroll">
    <n-data-table
      :columns="cols"
      :data="data"
      :row-key="(r: any) => r.rank"
      :row-props="rowProps"
      :bordered="false"
      size="small"
      :max-height="520"
    >
      <template #empty>
        <n-empty description="暂无用量排行数据" size="small" />
      </template>
    </n-data-table>
  </div>
  <n-popover trigger="manual" :show="pop.show" :x="pop.x" :y="pop.y">
    <div class="bubble">
      <UserDetail v-if="popDetail" :detail="popDetail" />
    </div>
  </n-popover>
</template>

<style scoped>
/* 移动端：6 列表格横向滚动，避免窄屏挤压 */
@media (max-width: 768px) {
  .table-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  :deep(.n-data-table) {
    min-width: 560px;
  }
}
:deep(.user-cell) {
  color: #4f6ef7;
  font-weight: 600;
}
:deep(.n-data-table-th) {
  background: linear-gradient(180deg, #f5f7ff, #edf1ff) !important;
  color: #525a6e !important;
  font-weight: 600 !important;
}
:deep(.n-data-table) {
  --n-td-color-hover: rgba(79, 110, 247, 0.06);
}
/* popover 对鼠标透明：避免浮层覆盖鼠标位置触发 tr 的 mouseleave，造成「关闭又展开」循环 */
:global(.n-popover) {
  pointer-events: none;
}
/* 表头问号说明（h() 动态生成，需 :global 命中） */
:global(.th-head) {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
:global(.help-icon) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: rgba(79, 110, 247, 0.14);
  color: #4f6ef7;
  font-size: 11px;
  line-height: 1;
  cursor: help;
  font-style: normal;
}
:global(.help-desc) {
  max-width: 340px;
  font-size: 12px;
  color: #4a5064;
  line-height: 1.6;
  white-space: pre-line;
}
</style>
