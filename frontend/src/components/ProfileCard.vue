<script setup lang="ts">
import { computed } from "vue";
import { NPopover } from "naive-ui";
import type { RankTotal } from "../api";
import type { Persona } from "../persona";
import { formatCost, formatToken, formatHitRate, hitRateColor } from "../format";
import RadarMini from "./RadarMini.vue";

const props = defineProps<{ row: RankTotal; persona: Persona }>();
const emit = defineEmits<{ (e: "select"): void }>();

const initial = computed(() => (props.row.user.trim()[0] ?? "?").toUpperCase());

// 头像渐变按规模分档
const avatarBg = computed(() => {
  switch (props.persona.scale) {
    case "重度": return "linear-gradient(135deg, #7c5cff, #3e5ce6)";
    case "中度": return "linear-gradient(135deg, #6a85fa, #4f6ef7)";
    default: return "linear-gradient(135deg, #a3b6ff, #7c9bff)";
  }
});

const cost = computed(() => formatCost(props.row.cost));
const hit = computed(() => formatHitRate(props.row.hitRate));
const hitColor = computed(() => hitRateColor(props.row.hitRate));
const pro = computed(() => formatToken(props.row.pro));
const flash = computed(() => formatToken(props.row.flash));

// 四个标签 chip：颜色与 format.ts 的语义色保持一致
const chips = computed(() => {
  const p = props.persona;
  const scaleColor = p.scale === "重度" ? "#7c5cff" : p.scale === "中度" ? "#4f6ef7" : "#a3b6ff";
  const modelColor = p.model === "Pro 党" ? "#3e5ce6" : p.model === "Flash 党" ? "#0ea5a0" : "#909399";
  const modeColor = p.mode === "读判型" ? "#409eff" : p.mode === "生成型" ? "#ff7d00" : "#909399";
  const effColor = p.eff === "省钱" ? "#18a058" : p.eff === "费" ? "#d03050" : "#f0a020";
  return [
    { label: p.scale, color: scaleColor },
    { label: p.model, color: modelColor },
    { label: p.mode, color: modeColor },
    { label: p.eff, color: effColor },
  ];
});
</script>

<template>
  <n-popover trigger="hover" placement="top" :delay="120">
    <template #trigger>
      <div class="profile-card" @click="emit('select')">
        <div class="pc-head">
          <div class="avatar" :style="{ background: avatarBg }">{{ initial }}</div>
          <div class="pc-id">
            <div class="pc-name">{{ row.user }}</div>
            <div class="pc-rank">#{{ row.rank }}</div>
          </div>
        </div>
        <div class="pc-tagline">{{ persona.tagline }}</div>
        <div class="pc-chips">
          <span v-for="c in chips" :key="c.label" class="chip" :style="{ color: c.color, background: c.color + '1f', borderColor: c.color + '59' }">{{ c.label }}</span>
        </div>
        <div class="pc-stats">
          <div class="pc-stat"><span class="k">成本</span><span class="v">{{ cost }}</span></div>
          <div class="pc-stat"><span class="k">命中率</span><span class="v" :style="{ color: hitColor }">{{ hit }}</span></div>
        </div>
        <RadarMini :values="persona.radar" class="pc-radar" />
        <div class="pc-bar"><div class="pc-bar-fill" :style="{ width: (persona.energy * 100).toFixed(1) + '%' }"></div></div>
      </div>
    </template>
    <div class="profile-tip">
      成本：{{ cost }}<br />
      命中率：{{ hit }}<br />
      Pro：{{ pro }}<br />
      Flash：{{ flash }}
    </div>
  </n-popover>
</template>

<style scoped>
.profile-card {
  position: relative;
  overflow: hidden;
  border-radius: 16px;
  padding: 16px 16px 14px;
  background: rgba(255, 255, 255, 0.62);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border: 1px solid rgba(255, 255, 255, 0.75);
  box-shadow: 0 6px 24px rgba(79, 110, 247, 0.08);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
  cursor: pointer;
}
/* 渐变描边：mask 只留 1px 边框 */
.profile-card::before {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: 16px;
  padding: 1px;
  background: linear-gradient(135deg, rgba(79, 110, 247, 0.55), rgba(124, 92, 255, 0.12) 40%, rgba(79, 110, 247, 0.45));
  -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
  -webkit-mask-composite: xor;
  mask-composite: exclude;
  pointer-events: none;
}
/* hover 微光扫过 */
.profile-card::after {
  content: "";
  position: absolute;
  top: 0;
  left: -80%;
  width: 50%;
  height: 100%;
  background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.55), transparent);
  transform: skewX(-18deg);
  transition: left 0.5s ease;
  pointer-events: none;
}
.profile-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 14px 40px rgba(79, 110, 247, 0.22);
}
.profile-card:hover::after {
  left: 130%;
}
.pc-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}
.avatar {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 18px;
  font-weight: 700;
  flex-shrink: 0;
  box-shadow: 0 4px 12px rgba(79, 110, 247, 0.35);
}
.pc-id {
  min-width: 0;
  flex: 1;
}
.pc-name {
  font-size: 15px;
  font-weight: 700;
  color: #303133;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pc-rank {
  font-size: 12px;
  color: #a0a6b3;
  font-variant-numeric: tabular-nums;
}
.pc-tagline {
  font-size: 13px;
  font-weight: 600;
  color: #4a5064;
  margin-bottom: 10px;
  letter-spacing: 0.2px;
}
.pc-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
}
.chip {
  font-size: 11px;
  line-height: 1;
  padding: 4px 9px;
  border-radius: 999px;
  border: 1px solid transparent;
  white-space: nowrap;
}
.pc-stats {
  display: flex;
  justify-content: space-between;
  margin-bottom: 10px;
}
.pc-stat {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.pc-stat .k {
  font-size: 11px;
  color: #a0a6b3;
}
.pc-stat .v {
  font-size: 16px;
  font-weight: 700;
  color: #303133;
  font-variant-numeric: tabular-nums;
}
.pc-radar {
  margin: 6px 0 10px;
}
.pc-bar {
  height: 4px;
  border-radius: 999px;
  background: rgba(79, 110, 247, 0.10);
  overflow: hidden;
}
.pc-bar-fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, #4f6ef7, #7c5cff);
  transition: width 0.4s ease;
}
/* tooltip 浮层 teleport 到 body，scoped 命中不了 */
:global(.profile-tip) {
  font-size: 12px;
  color: #4a5064;
  line-height: 1.7;
}
</style>
