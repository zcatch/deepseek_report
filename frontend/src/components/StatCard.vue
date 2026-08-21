<script setup lang="ts">
import { NCard, NPopover } from "naive-ui";

defineProps<{
  label: string;
  value: string;
  color?: string;
  desc?: string;
}>();
</script>

<template>
  <n-popover trigger="hover" :disabled="!desc" :delay="100" placement="top">
    <template #trigger>
      <n-card :bordered="false" class="stat-card" size="small" :style="{ '--bar-color': color ?? '#4f6ef7' }">
        <div class="stat-label">{{ label }}</div>
        <div class="stat-value" :style="{ color: color ?? '#303133' }">{{ value }}</div>
      </n-card>
    </template>
    <div class="stat-desc">{{ desc }}</div>
  </n-popover>
</template>

<style scoped>
.stat-card {
  --n-color: rgba(255, 255, 255, 0.62);
  position: relative;
  overflow: hidden;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.62);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border: 1px solid rgba(255, 255, 255, 0.75);
  box-shadow: 0 6px 24px rgba(79, 110, 247, 0.08);
  transition: transform 0.18s ease, box-shadow 0.18s ease;
  cursor: default;
}
.stat-card::before {
  content: "";
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 3px;
  background: linear-gradient(180deg, var(--bar-color), rgba(79, 110, 247, 0.15));
}
.stat-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 12px 32px rgba(79, 110, 247, 0.16);
}
.stat-label {
  font-size: 12px;
  color: #8a8f99;
  margin-bottom: 4px;
}
.stat-value {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.25;
  font-variant-numeric: tabular-nums;
}
.stat-desc {
  max-width: 220px;
  font-size: 12px;
  color: #4a5064;
  line-height: 1.5;
}
</style>
