<script setup lang="ts">
import { computed } from "vue";
import { RADAR_AXES } from "../persona";

// 纯 SVG 五边形雷达图：避免每张卡一个 ECharts 实例（31 卡 × canvas 太重）
const props = defineProps<{ values: number[] }>();

const CX = 58;
const CY = 58;
const R = 36;

function pt(ratio: number, i: number): [number, number] {
  const angle = (Math.PI * 2 * i) / 5 - Math.PI / 2; // 顶部轴起
  const r = R * ratio;
  return [CX + r * Math.cos(angle), CY + r * Math.sin(angle)];
}

// 4 层同心五边形网格
const grid = [0.25, 0.5, 0.75, 1].map(ratio =>
  [0, 1, 2, 3, 4].map(i => pt(ratio, i).map(v => v.toFixed(2)).join(",")).join(" ")
);

// 轴线 + 标签（标签外移到 1.32 倍半径，viewBox 已留足边距）
const axes = RADAR_AXES.map((label, i) => {
  const [x2, y2] = pt(1, i);
  const [lx, ly] = pt(1.32, i);
  return { label, x2, y2, lx, ly };
});

// 数据五边形顶点
const dataPts = computed(() =>
  RADAR_AXES.map((_, i) => {
    const v = Math.max(0, Math.min(100, props.values[i] ?? 0));
    return pt(v / 100, i);
  })
);
const dataPoints = computed(() => dataPts.value.map(p => p.map(v => v.toFixed(2)).join(",")).join(" "));
</script>

<template>
  <svg viewBox="0 0 116 116" class="radar-mini">
    <polygon v-for="(g, i) in grid" :key="'g' + i" :points="g" class="grid" />
    <line v-for="(a, i) in axes" :key="'ax' + i" :x1="CX" :y1="CY" :x2="a.x2" :y2="a.y2" class="axis" />
    <polygon :points="dataPoints" class="data-fill" />
    <polygon :points="dataPoints" class="data-stroke" />
    <circle v-for="(p, i) in dataPts" :key="'d' + i" :cx="p[0]" :cy="p[1]" r="1.5" class="dot" />
    <text v-for="(a, i) in axes" :key="'l' + i" :x="a.lx" :y="a.ly" class="axis-label" text-anchor="middle" dominant-baseline="middle">{{ a.label }}</text>
  </svg>
</template>

<style scoped>
.radar-mini {
  width: 100%;
  height: 170px;
  display: block;
}
.grid {
  fill: none;
  stroke: #e3e7f5;
  stroke-width: 0.5;
}
.axis {
  stroke: #e6e9f4;
  stroke-width: 0.5;
}
.data-fill {
  fill: rgba(79, 110, 247, 0.20);
}
.data-stroke {
  fill: none;
  stroke: #4f6ef7;
  stroke-width: 1;
  stroke-linejoin: round;
  filter: drop-shadow(0 0 4px rgba(79, 110, 247, 0.7));
}
.dot {
  fill: #7c5cff;
}
.axis-label {
  font-size: 9px;
  fill: #5a6070;
}
</style>
