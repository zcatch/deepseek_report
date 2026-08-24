<script setup lang="ts">
import { ref, watch, nextTick, onMounted, onBeforeUnmount } from "vue";
import { useSafeBottom } from "../useSafeBottom";

const props = defineProps<{ ready: boolean; loading?: boolean }>();

const emit = defineEmits<{ (e: "refresh"): void }>();

const { bottom: fabBottom } = useSafeBottom(20);

const ITEMS = [
  { id: "kpi", label: "总览" },
  { id: "scatter", label: "结构" },
  { id: "persona", label: "画像" },
  { id: "trend", label: "趋势" },
  { id: "rank", label: "排行" },
];

const activeId = ref("");
const showBackTop = ref(false);
let observer: IntersectionObserver | null = null;

function observe() {
  observer?.disconnect();
  observer = new IntersectionObserver(
    entries => {
      for (const e of entries) {
        if (e.isIntersecting) activeId.value = e.target.id;
      }
    },
    { rootMargin: "-45% 0px -50% 0px", threshold: 0 },
  );
  for (const item of ITEMS) {
    const el = document.getElementById(item.id);
    if (el) observer.observe(el);
  }
}

function onScroll() {
  showBackTop.value = window.scrollY > 500;
}

function goTo(id: string) {
  document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });
}

function backTop() {
  window.scrollTo({ top: 0, behavior: "smooth" });
}

// 移动端右下角悬浮导航：点开菜单 → 跳区块；点外部关闭
const fabOpen = ref(false);
function onNav(item: { id: string }) {
  goTo(item.id);
  fabOpen.value = false;
}
function onDocClick() {
  if (fabOpen.value) fabOpen.value = false;
}

watch(() => props.ready, r => {
  if (r) nextTick(observe);
});

onMounted(() => {
  window.addEventListener("scroll", onScroll, { passive: true });
  document.addEventListener("click", onDocClick);
  onScroll();
  if (props.ready) nextTick(observe);
});

onBeforeUnmount(() => {
  window.removeEventListener("scroll", onScroll);
  document.removeEventListener("click", onDocClick);
  observer?.disconnect();
});
</script>

<template>
  <nav class="side-nav">
    <button class="action-btn refresh" :class="{ spinning: loading }" title="按当前筛选条件刷新数据" @click="emit('refresh')">
      <svg viewBox="0 0 16 16" width="15" height="15">
        <path d="M13 8a5 5 0 1 1-1.5-3.5M13 2v3h-3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </button>

    <div class="divider"></div>

    <div class="nav-items">
      <button
        v-for="item in ITEMS"
        :key="item.id"
        class="nav-dot"
        :class="{ active: item.id === activeId }"
        :title="item.label"
        @click="goTo(item.id)"
      >
        <span class="dot"></span>
        <span class="tip">{{ item.label }}</span>
      </button>
    </div>

    <div class="divider"></div>

    <button class="action-btn back-top" :class="{ enabled: showBackTop }" title="返回顶部" @click="backTop">
      <svg viewBox="0 0 16 16" width="16" height="16">
        <path d="M8 12V4M4 7l4-4 4 4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </button>
  </nav>

  <!-- 移动端：右下角悬浮导航，点开菜单跳区块 -->
  <div class="fab mobile-only" :style="{ bottom: fabBottom + 'px' }">
    <transition name="fab-pop">
      <div v-if="fabOpen" class="fab-menu" @click.stop>
        <button class="fab-item fab-icon-item" title="刷新" @click="emit('refresh'); fabOpen = false">
          <svg viewBox="0 0 16 16" width="16" height="16">
            <path d="M13 8a5 5 0 1 1-1.5-3.5M13 2v3h-3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
        <div class="fab-divider"></div>
        <button
          v-for="item in ITEMS"
          :key="item.id"
          class="fab-item"
          :class="{ active: item.id === activeId }"
          @click="onNav(item)"
        >{{ item.label }}</button>
        <div class="fab-divider"></div>
        <button class="fab-item fab-icon-item" title="置顶" @click="backTop(); fabOpen = false">
          <svg viewBox="0 0 16 16" width="16" height="16">
            <path d="M8 12V4M4 7l4-4 4 4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
      </div>
    </transition>
    <button class="fab-btn" title="区块导航" @click.stop="fabOpen = !fabOpen">
      <svg viewBox="0 0 16 16" width="18" height="18">
        <circle cx="5" cy="5" r="1.6" fill="currentColor" />
        <circle cx="11" cy="5" r="1.6" fill="currentColor" />
        <circle cx="5" cy="11" r="1.6" fill="currentColor" />
        <circle cx="11" cy="11" r="1.6" fill="currentColor" />
      </svg>
    </button>
  </div>
</template>

<style scoped>
/* 移动端 FAB：桌面端默认隐藏，仅 ≤768px 显示 */
.fab {
  display: none;
}
.side-nav {
  position: fixed;
  right: 18px;
  top: 50%;
  transform: translateY(-50%);
  z-index: 500;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 10px 8px;
  background: rgba(255, 255, 255, 0.9);
  border: 1px solid rgba(255, 255, 255, 0.75);
  border-radius: 18px;
  box-shadow: 0 12px 36px rgba(79, 110, 247, 0.15);
}
.action-btn {
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  border: none;
  border-radius: 9px;
  color: #8a93a8;
  cursor: pointer;
  transition: all 0.25s;
}
.action-btn:hover {
  background: rgba(79, 110, 247, 0.08);
  color: #4f6ef7;
}
.refresh.spinning svg {
  animation: spin 0.9s linear infinite;
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
.back-top {
  color: #c6cbd8;
  cursor: default;
  pointer-events: none;
}
.back-top.enabled {
  color: #8a93a8;
  cursor: pointer;
  pointer-events: auto;
}
.back-top.enabled:hover {
  background: rgba(79, 110, 247, 0.08);
  color: #4f6ef7;
}
/* 移动端：桌面右侧导航隐藏，改为右下角悬浮导航（FAB 点开菜单跳区块） */
@media (max-width: 768px) {
  .side-nav {
    display: none;
  }
  .fab {
    position: fixed;
    right: 14px;
    bottom: 20px;
    z-index: 700;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 10px;
  }
  .fab-btn {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.9);
    border: 1px solid rgba(255, 255, 255, 0.75);
    box-shadow: 0 10px 28px rgba(79, 110, 247, 0.28);
    color: #4f6ef7;
    cursor: pointer;
  }
  .fab-btn:active {
    background: rgba(79, 110, 247, 0.12);
  }
  .fab-menu {
    display: flex;
    flex-direction: column;
    padding: 6px;
    background: rgba(255, 255, 255, 0.95);
    border: 1px solid rgba(255, 255, 255, 0.75);
    border-radius: 12px;
    box-shadow: 0 12px 32px rgba(79, 110, 247, 0.18);
  }
  .fab-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 18px;
    font-size: 13px;
    color: #525a6e;
    border: none;
    background: transparent;
    border-radius: 8px;
    text-align: left;
    white-space: nowrap;
    cursor: pointer;
  }
  .fab-item:active {
    background: rgba(79, 110, 247, 0.16);
  }
  .fab-item.active {
    color: #4f6ef7;
    font-weight: 600;
    background: rgba(79, 110, 247, 0.10);
  }
  /* 纯图标操作项（刷新/置顶）：图标居中，与区块项同宽对齐 */
  .fab-icon-item {
    justify-content: center;
    padding: 8px 0;
  }
  .fab-ic {
    flex-shrink: 0;
    opacity: 0.75;
  }
  .fab-divider {
    height: 1px;
    margin: 5px 8px;
    background: rgba(79, 110, 247, 0.14);
  }
  .fab-pop-enter-active,
  .fab-pop-leave-active {
    transition: opacity 0.15s ease, transform 0.15s ease;
  }
  .fab-pop-enter-from,
  .fab-pop-leave-to {
    opacity: 0;
    transform: translateY(6px);
  }
}
.nav-dot {
  position: relative;
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  border: none;
  border-radius: 9px;
  cursor: pointer;
  transition: background 0.2s;
}
.nav-dot:hover {
  background: rgba(79, 110, 247, 0.08);
}
.dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #c6cbd8;
  transition: all 0.25s;
}
.nav-dot.active .dot {
  width: 14px;
  height: 14px;
  background: #4f6ef7;
  box-shadow: 0 0 0 4px rgba(79, 110, 247, 0.15);
}
.tip {
  position: absolute;
  right: calc(100% + 12px);
  top: 50%;
  transform: translateY(-50%) translateX(6px);
  white-space: nowrap;
  padding: 4px 10px;
  font-size: 12px;
  color: #fff;
  background: #4f6ef7;
  border-radius: 8px;
  opacity: 0;
  pointer-events: none;
  transition: all 0.2s;
}
.nav-dot:hover .tip {
  opacity: 1;
  transform: translateY(-50%) translateX(0);
}
.divider {
  width: 16px;
  height: 1px;
  margin: 4px 0;
  background: rgba(79, 110, 247, 0.18);
}
</style>
