<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from "vue";
import type { RankTotal } from "../api";
import type { Persona } from "../persona";
import { RADAR_AXES } from "../persona";
import { formatToken } from "../format";

// 全息投影面板（彩蛋）：点击画像卡展开，数字滚动 + 属性条 + 扫描线
const props = defineProps<{ row: RankTotal; persona: Persona; cats?: { key: string; label: string; color: string }[] }>();

const initial = props.row.user.trim()[0]?.toUpperCase() ?? "?";

// count-up 数字滚动（ease-out cubic，约 0.9s）
const costVal = ref(0);
const hitVal = ref(0);
let raf = 0;

function animate() {
  const start = performance.now();
  const dur = 900;
  const costEnd = props.row.cost;
  const hitEnd = props.row.hitRate ? parseFloat(props.row.hitRate) : 0;
  function step(now: number) {
    const t = Math.min(1, (now - start) / dur);
    const e = 1 - Math.pow(1 - t, 3);
    costVal.value = costEnd * e;
    hitVal.value = hitEnd * e;
    if (t < 1) raf = requestAnimationFrame(step);
    else {
      costVal.value = costEnd;
      hitVal.value = hitEnd;
    }
  }
  raf = requestAnimationFrame(step);
}
onMounted(animate);
onBeforeUnmount(() => cancelAnimationFrame(raf));

const bars = RADAR_AXES.map((label, i) => ({
  label,
  v: Math.max(0, Math.min(100, props.persona.radar[i] ?? 0)),
}));
</script>

<template>
  <div class="holo">
    <div class="holo-scan"></div>

    <div class="holo-head">
      <div class="holo-avatar">{{ initial }}</div>
      <div class="holo-id">
        <div class="holo-name">{{ row.user }}</div>
        <div class="holo-tag">{{ persona.tagline }}</div>
      </div>
    </div>

    <div class="holo-big">
      <div class="big">
        <div class="big-v">{{ costVal.toFixed(2) }}<span class="big-u">¥</span></div>
        <div class="big-k">估算成本</div>
      </div>
      <div class="big">
        <div class="big-v">{{ hitVal.toFixed(1) }}<span class="big-u">%</span></div>
        <div class="big-k">缓存命中率</div>
      </div>
    </div>

    <div class="holo-bars">
      <div v-for="b in bars" :key="b.label" class="hbar">
        <span class="hbar-k">{{ b.label }}</span>
        <div class="hbar-track"><div class="hbar-fill" :style="{ width: b.v + '%' }"></div></div>
        <span class="hbar-v">{{ b.v.toFixed(0) }}</span>
      </div>
    </div>

    <div class="holo-meta">
      <div class="meta-cell"><span class="meta-k">总量</span><span class="meta-v">{{ formatToken(row.total) }}</span></div>
      <div v-for="c in cats" :key="c.key" class="meta-cell"><span class="meta-k">{{ c.label }}</span><span class="meta-v">{{ formatToken(row.models[c.key]?.tokens ?? 0) }}</span></div>
    </div>
  </div>
</template>

<style scoped>
.holo {
  position: relative;
  overflow: hidden;
  border-radius: 16px;
  padding: 18px;
  color: #cfe0ff;
  background: linear-gradient(160deg, rgba(16, 22, 44, 0.97), rgba(28, 36, 68, 0.97));
  border: 1px solid rgba(124, 92, 255, 0.45);
  box-shadow: 0 0 40px rgba(79, 110, 247, 0.35), inset 0 0 60px rgba(79, 110, 247, 0.12);
  animation: holoFlip 0.8s cubic-bezier(0.22, 1, 0.36, 1);
}
@keyframes holoFlip {
  from {
    transform: rotateY(0deg) scale(0.85);
    opacity: 0;
  }
  to {
    transform: rotateY(360deg) scale(1);
    opacity: 1;
  }
}
.holo-scan {
  position: absolute;
  left: 0;
  right: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(124, 92, 255, 0.9), transparent);
  animation: scan 2.4s linear infinite;
}
@keyframes scan {
  0% { top: 0; }
  100% { top: 100%; }
}
.holo-head {
  display: flex;
  align-items: center;
  gap: 14px;
}
.holo-avatar {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 22px;
  font-weight: 700;
  background: linear-gradient(135deg, #7c5cff, #3e5ce6);
  box-shadow: 0 0 20px rgba(124, 92, 255, 0.7);
  flex-shrink: 0;
}
.holo-name {
  font-size: 18px;
  font-weight: 700;
  color: #fff;
}
.holo-tag {
  font-size: 13px;
  color: #8fa6e8;
  margin-top: 2px;
}
.holo-big {
  display: flex;
  gap: 16px;
  margin: 20px 0;
}
.big {
  flex: 1;
  text-align: center;
}
.big-v {
  font-size: 26px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: #fff;
  text-shadow: 0 0 16px rgba(79, 110, 247, 0.8);
}
.big-u {
  font-size: 15px;
  color: #8fa6e8;
  margin-left: 2px;
}
.big-k {
  font-size: 12px;
  color: #8fa6e8;
  margin-top: 3px;
}
.holo-bars {
  margin-bottom: 6px;
}
.hbar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}
.hbar-k {
  width: 42px;
  font-size: 12px;
  color: #a8b8e8;
  text-align: right;
  flex-shrink: 0;
  white-space: nowrap;
}
.hbar-track {
  flex: 1;
  height: 8px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  overflow: hidden;
}
.hbar-fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, #4f6ef7, #7c5cff);
  box-shadow: 0 0 8px rgba(124, 92, 255, 0.8);
  transition: width 0.6s ease;
}
.hbar-v {
  width: 30px;
  font-size: 12px;
  color: #cfe0ff;
  font-variant-numeric: tabular-nums;
  text-align: right;
}
.holo-meta {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px 18px;
  font-size: 11px;
  color: #8fa6e8;
  border-top: 1px solid rgba(124, 92, 255, 0.2);
  padding-top: 14px;
  margin-top: 8px;
}
.meta-cell {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  white-space: nowrap;
}
.meta-k {
  color: #8fa6e8;
}
.meta-v {
  color: #cfe0ff;
  font-variant-numeric: tabular-nums;
}
</style>
