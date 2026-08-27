<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount, nextTick, watch } from "vue";
import { NEmpty } from "naive-ui";
import type { RankTotal } from "../api";
import type { Persona } from "../persona";
import ProfileCard from "./ProfileCard.vue";

const props = defineProps<{
  rows: RankTotal[];
  personas: Map<string, Persona>;
  cats?: { key: string; label: string; color: string }[];
  expanded?: boolean;
}>();

const emit = defineEmits<{ (e: "update:hasMore", v: boolean): void; (e: "select", user: string): void }>();

const wallEl = ref<HTMLDivElement>();
const rowHeight = ref(0);

// 第一张卡高度 = 一行高度（grid 默认 stretch，首卡被拉到行高）；据此判断是否超过一行
function measure() {
  nextTick(() => {
    if (!wallEl.value) return;
    const cards = wallEl.value.querySelectorAll<HTMLElement>(".profile-card");
    if (!cards.length) return;
    rowHeight.value = cards[0].offsetHeight;
    const firstTop = cards[0].offsetTop;
    // 是否存在 offsetTop 大于首卡的卡片 = 是否有第二行（决定展开按钮显隐）
    emit("update:hasMore", Array.from(cards).some(c => c.offsetTop > firstTop));
  });
}

let raf = 0;
function onResize() {
  cancelAnimationFrame(raf);
  raf = requestAnimationFrame(measure);
}

onMounted(() => {
  measure();
  window.addEventListener("resize", onResize);
});
onBeforeUnmount(() => {
  cancelAnimationFrame(raf);
  window.removeEventListener("resize", onResize);
});

// 数据/筛选/展开态变化后重测（卡片渲染后）
watch(() => [props.rows, props.expanded], () => measure());

// 收起态用高度裁剪只露出第一行；展开态全显示
const collapsedStyle = computed(() =>
  props.expanded ? {} : { maxHeight: rowHeight.value ? `${rowHeight.value}px` : "none", overflow: "hidden" }
);
</script>

<template>
  <div v-if="rows.length" ref="wallEl" class="wall" :class="{ collapsed: !expanded }" :style="collapsedStyle">
    <ProfileCard v-for="r in rows" :key="r.user" :row="r" :persona="personas.get(r.user)!" :cats="cats" @select="emit('select', r.user)" />
  </div>
  <div v-else class="wall-empty"><n-empty description="暂无人员画像数据" size="small" /></div>
</template>

<style scoped>
.wall {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 14px;
}
/* 移动端：窄平板/大手机（480~768px）保持两列，避免提前缩成单列 */
@media (max-width: 768px) {
  .wall {
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
  }
}
/* 真正的窄屏（≤480px，主流手机竖屏）才单列 */
@media (max-width: 480px) {
  .wall {
    grid-template-columns: 1fr;
  }
}
/* 收起态：首行卡片 hover 不上浮，避免顶部描边被 overflow 裁掉 */
.wall.collapsed .profile-card:hover {
  transform: none;
}
.wall-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 160px;
  color: #a0a6b3;
  font-size: 14px;
}
</style>
