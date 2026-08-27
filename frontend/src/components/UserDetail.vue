<script setup lang="ts">
import { formatToken, formatCost, formatHitRate, hitRateColor, formatPercent, outputRatioColor } from "../format";

defineProps<{
  detail: {
    user: string;
    rank: number;
    share: number;
    total: number;
    input: number;
    output: number;
    outputRatio: string | null;
    cost: number;
    hitRate: string | null;
    models: { label: string; tokens: number; hitRate: string | null }[];
  };
}>();
</script>

<template>
  <div class="user-detail">
    <div class="ud-head">
      <span class="ud-name">{{ detail.user }}</span>
      <span class="ud-rank">#{{ detail.rank }}</span>
    </div>
    <div class="ud-row"><span>占全员比例</span><span>{{ detail.share }}%</span></div>
    <div class="ud-row"><span>总 Token</span><span>{{ formatToken(detail.total) }}</span></div>
    <div v-for="mc in detail.models" :key="mc.label" class="ud-row"><span>{{ mc.label }} Token</span><span>{{ formatToken(mc.tokens) }}</span></div>
    <div class="ud-row"><span>输入 Token</span><span>{{ formatToken(detail.input) }}</span></div>
    <div class="ud-row"><span>输出 Token</span><span>{{ formatToken(detail.output) }}</span></div>
    <div class="ud-row"><span>输出占比</span><span :style="{ color: outputRatioColor(detail.outputRatio) }">{{ formatPercent(detail.outputRatio) }}</span></div>
    <div class="ud-row"><span>估算成本</span><span class="ud-cost">{{ formatCost(detail.cost) }}</span></div>
    <div class="ud-row"><span>综合命中率</span><span :style="{ color: hitRateColor(detail.hitRate) }">{{ formatHitRate(detail.hitRate) }}</span></div>
    <div v-for="mc in detail.models" :key="mc.label" class="ud-row"><span>{{ mc.label }} 命中率</span><span :style="{ color: hitRateColor(mc.hitRate) }">{{ formatHitRate(mc.hitRate) }}</span></div>
  </div>
</template>

<style scoped>
.user-detail {
  min-width: 180px;
  font-size: 13px;
}
.ud-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
  padding-bottom: 6px;
  border-bottom: 1px solid #ebeef5;
}
.ud-name {
  font-weight: 700;
  font-size: 14px;
  color: #303133;
}
.ud-rank {
  color: #18a058;
  font-weight: 700;
}
.ud-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  padding: 3px 0;
}
.ud-row > span:first-child {
  color: #8a8f99;
}
.ud-row > span:last-child {
  font-variant-numeric: tabular-nums;
  color: #303133;
}
.ud-cost {
  color: #d03050;
  font-weight: 600;
}
</style>
