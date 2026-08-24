<script setup lang="ts">
import { NCard, NPopover } from "naive-ui";

defineProps<{
  label: string;
  value: string;
  color?: string;
  desc?: string;
  // 次要指标：移动端弱化（小字号淡化、不显示说明），突出核心指标
  minor?: boolean;
}>();
</script>

<template>
  <n-popover trigger="hover" :disabled="!desc" :delay="100" placement="top">
    <template #trigger>
      <n-card :bordered="false" class="stat-card" :class="{ minor }" size="small" :style="{ '--bar-color': color ?? '#4f6ef7' }">
        <div class="stat-body">
          <div class="stat-label">{{ label }}</div>
          <div class="stat-value" :style="{ color: color ?? '#303133' }">{{ value }}</div>
        </div>
        <div v-if="desc" class="stat-desc-inline">{{ desc }}</div>
      </n-card>
    </template>
    <div class="stat-desc">{{ desc }}</div>
  </n-popover>
</template>

<style scoped>
.stat-card {
  --n-color: rgba(255, 255, 255, 0.92);
  position: relative;
  overflow: hidden;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.92);
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
/* 桌面端：标题在上、数值在下（column），行内顺序由 DOM（label→value）保证 */
.stat-body {
  display: flex;
  flex-direction: column;
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
/* 卡内说明：桌面端隐藏（hover 弹窗展示） */
.stat-desc-inline {
  display: none;
}
/* 移动端：KPI 卡「左值右标题」横排，单行不换行，值/标题超长省略号，高度统一 */
@media (max-width: 768px) {
  .stat-card {
    --n-padding: 9px 10px;
  }
  .stat-body {
    /* 值上题下：数值(大)在上、标题(小)在下，居中；单行一项宽度足够，不挤 */
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    min-height: 32px; /* 统一内容高度，避免个别卡因内容长短高矮不一 */
  }
  .stat-label {
    margin-bottom: 0;
    font-size: 12px; /* 与 PC 标题一致 */
    line-height: 1.2;
    text-align: center;
    max-width: 100%;
    /* 单行省略 */
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .stat-value {
    font-size: 15px;
    line-height: 1.2;
    max-width: 100%;
    /* 单行省略 */
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .stat-desc-inline {
    display: none;
  }
  /* 次要卡：标题与数值字号统一（与普通卡一致），仅颜色淡化区分主次 */
  .stat-card.minor .stat-value {
    font-size: 15px;
    color: #6b7280;
  }
  .stat-card.minor .stat-label {
    font-size: 12px;
    color: #a0a6b3;
  }
}
</style>
