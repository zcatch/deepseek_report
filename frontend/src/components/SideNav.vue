<script setup lang="ts">
import { ref, watch, nextTick, onMounted, onBeforeUnmount } from "vue";

const props = defineProps<{ ready: boolean; loading?: boolean }>();

const emit = defineEmits<{ (e: "refresh"): void }>();

const ITEMS = [
  { id: "kpi", label: "总览" },
  { id: "scatter", label: "结构分布" },
  { id: "persona", label: "人员画像" },
  { id: "trend", label: "每日趋势" },
  { id: "rank", label: "用量排行" },
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

watch(() => props.ready, r => {
  if (r) nextTick(observe);
});

onMounted(() => {
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();
  if (props.ready) nextTick(observe);
});

onBeforeUnmount(() => {
  window.removeEventListener("scroll", onScroll);
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
</template>

<style scoped>
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
  background: rgba(255, 255, 255, 0.62);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
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
