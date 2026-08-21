<script setup lang="ts">
import { NPopover } from "naive-ui";

defineProps<{ title: string; help?: string }>();
</script>

<template>
  <n-popover v-if="help" trigger="hover" placement="top">
    <template #trigger>
      <span class="card-title">
        {{ title }}
        <span class="help-icon">?</span>
      </span>
    </template>
    <div class="help-desc">{{ help }}</div>
  </n-popover>
  <span v-else class="card-title">{{ title }}</span>
</template>

<style scoped>
.card-title {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 16px;
  font-weight: 600;
  color: #303133;
  white-space: nowrap;
}
/* popover 浮层 teleport 到 body，scoped 命中不了，用 :global（与排行表头问号同款） */
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
  max-width: 260px;
  font-size: 12px;
  color: #4a5064;
  line-height: 1.6;
  white-space: pre-line;
}
</style>
