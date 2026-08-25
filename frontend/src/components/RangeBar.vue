<script setup lang="ts">
import { ref, watch, computed } from "vue";
import { NButton, NButtonGroup, NSelect, NDatePicker } from "naive-ui";

const PRESETS = ["今天", "近7天", "近30天", "本月", "上月"];

const props = defineProps<{
  model: string;
  showModel?: boolean;
  users?: string[];
  user?: string[];
  showUser?: boolean;
}>();

const emit = defineEmits<{
  (e: "query", range: string): void;
  (e: "update:model", v: string): void;
  (e: "update:user", v: string[]): void;
  (e: "reset"): void;
}>();

const active = ref("今天");
const dateRange = ref<any>(null);
// 移动端用原生 date 输入（开始/结束），弹系统日历，小屏友好
const mobileStart = ref("");
const mobileEnd = ref("");

const modelOptions = [
  { label: "全部模型", value: "all" },
  { label: "V4-Pro", value: "pro" },
  { label: "V4-Flash", value: "flash" },
];

const userOptions = computed(() => (props.users ?? []).map(u => ({ label: u, value: u })));

function onPreset(p: string) {
  active.value = p;
  dateRange.value = null;
  mobileStart.value = "";
  mobileEnd.value = "";
  emit("query", p);
}

watch(dateRange, v => {
  if (v && v.length === 2) {
    active.value = "";
    mobileStart.value = "";
    mobileEnd.value = "";
    emit("query", toRangeText(v));
  }
});

// 移动端原生日期：开始+结束都选了才触发；两个都清空 → 回落到默认预设
watch([mobileStart, mobileEnd], ([s, e]) => {
  if (s && e) {
    active.value = "";
    emit("query", toRangeText([new Date(s), new Date(e)]));
  } else if (!s && !e && active.value === "") {
    active.value = PRESETS[0];
    emit("query", PRESETS[0]);
  }
});

// 清空筛选：范围回默认预设 + 通知父组件重置模型（视角身份由右上角切换器单独控制）
function onReset() {
  active.value = PRESETS[0];
  dateRange.value = null;
  mobileStart.value = "";
  mobileEnd.value = "";
  emit("reset");
}

// 时间戳数组 → "8月1~20"；跨月 → "8月25~9月5"
function toRangeText([s, e]: [number, number]): string {
  const a = new Date(s), b = new Date(e);
  const sm = a.getMonth() + 1, sd = a.getDate(), sy = a.getFullYear();
  const em = b.getMonth() + 1, ed = b.getDate(), ey = b.getFullYear();
  if (sy === ey && sm === em) return `${sm}月${sd}~${ed}`;
  return `${sm}月${sd}~${em}月${ed}`;
}
</script>

<template>
  <div class="range-bar">
    <n-button-group class="presets">
      <n-button
        v-for="p in PRESETS"
        :key="p"
        :type="active === p ? 'primary' : 'default'"
        @click="onPreset(p)"
      >
        {{ p }}
      </n-button>
    </n-button-group>

    <!-- 桌面端：naive 日期选择器 -->
    <n-date-picker
      v-model:value="dateRange"
      type="daterange"
      value-format="yyyy-MM-dd"
      clearable
      placeholder="选择日期范围"
      class="date-picker desktop-only"
    />

    <!-- 移动端：原生 date 输入（开始/结束）。CSS 把日历图标铺满整个输入框，
         点击任意位置都弹系统日历；移动端 date 本身无法手输。
         min/max 约束：开始不能晚于结束、结束不能早于开始，从源头杜绝顺序错乱 -->
    <div class="date-native mobile-only">
      <div class="date-input-wrap">
        <input v-model="mobileStart" type="date" class="date-input" :class="{ 'is-empty': !mobileStart }" aria-label="开始日期" :max="mobileEnd || undefined" />
        <!-- 原生 date 的 placeholder 在多数手机上被忽略，用叠加层显示占位文字 -->
        <span v-if="!mobileStart" class="date-placeholder">开始日期</span>
      </div>
      <span class="date-sep">~</span>
      <div class="date-input-wrap">
        <input v-model="mobileEnd" type="date" class="date-input" :class="{ 'is-empty': !mobileEnd }" aria-label="结束日期" :min="mobileStart || undefined" />
        <span v-if="!mobileEnd" class="date-placeholder">结束日期</span>
      </div>
    </div>

    <div class="spacer" />

    <n-select
      :value="model"
      :options="modelOptions"
      class="select-model"
      @update:value="(v: string) => emit('update:model', v)"
    />

    <n-select
      v-if="showUser !== false && users && users.length"
      :value="user"
      :options="userOptions"
      multiple
      filterable
      clearable
      max-tag-count="responsive"
      placeholder="全部人员"
      class="select-user"
      @update:value="(v: string[]) => emit('update:user', v)"
    />

    <n-button size="small" quaternary class="reset-btn" title="清空筛选（范围/模型/人员回默认）" @click="onReset">
      清空筛选
    </n-button>
  </div>
</template>

<style scoped>
.range-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.date-picker {
  width: 260px;
}
.spacer {
  flex: 1;
}
.select-model,
.select-user {
  width: 200px;
}
/* 清空筛选：主题蓝毛玻璃，常态淡蓝、hover 加深、按下实底白字 */
.reset-btn {
  height: 34px;
  padding: 0 10px;
  background: rgba(79, 110, 247, 0.10);
  border: 1px solid rgba(79, 110, 247, 0.30);
  border-radius: 8px;
  color: #4f6ef7;
  flex-shrink: 0;
}
.reset-btn:hover {
  background: rgba(79, 110, 247, 0.18);
  border-color: rgba(79, 110, 247, 0.45);
  color: #3e5ce6;
}
.reset-btn:active {
  background: #4f6ef7 !important;
  border-color: #4f6ef7 !important;
  color: #fff !important;
}
/* 桌面端默认隐藏移动端控件 */
.mobile-only {
  display: none;
}
/* 移动端：预设一行整齐、原生日期全宽、模型/人员并排 */
@media (max-width: 768px) {
  .desktop-only {
    display: none;
  }
  .mobile-only {
    display: flex;
  }
  /* 桌面端的弹性占位 div 在移动端隐藏，避免抢占 flex 空间造成左侧空出 */
  .spacer {
    display: none;
  }
  .presets {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    white-space: nowrap;
    flex-wrap: nowrap;
    border-radius: 8px;
  }
  .presets :deep(.n-button) {
    flex-shrink: 0;
    font-size: 12px;
    padding: 0 10px;
    border-radius: 8px !important;
    margin: 0 2px;
  }
  .date-native {
    align-items: center;
    gap: 8px;
    width: 100%;
  }
  .date-input-wrap {
    position: relative;
    flex: 1;
    min-width: 0;
  }
  .date-input {
    position: relative;
    width: 100%;
    min-width: 0;
    max-width: 100%;
    box-sizing: border-box;
    height: 34px;
    padding: 0 10px;
    font-size: 14px;
    color: #303133;
    background: rgba(255, 255, 255, 0.62);
    border: 1px solid rgba(79, 110, 247, 0.25);
    border-radius: 8px;
    outline: none;
    -webkit-appearance: none;
    appearance: none;
  }
  /* 空值时隐藏浏览器自带的日期格式占位（各浏览器「年/月/日」「yyyy/mm/dd」各不相同，会和叠加占位重叠）——格式占位跟随 color，一并隐形；选中后恢复文字色 */
  .date-input.is-empty {
    color: transparent;
  }
  /* 占位文字层：叠在输入框上，空值显示，选中后移除；pointer-events 穿透不挡点击弹日历 */
  .date-placeholder {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: #8a8f99;
    font-size: 14px;
    pointer-events: none;
    white-space: nowrap;
  }
  .date-input:focus {
    border-color: #4f6ef7;
  }
  /* 日历图标铺满整个输入框：点击任意位置都弹系统日历（不依赖 showPicker 兼容性） */
  .date-input::-webkit-calendar-picker-indicator {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
  }
  .date-input::-ms-clear,
  .date-input::-ms-reveal {
    display: none;
  }
  .date-sep {
    color: #a0a6b3;
    font-size: 13px;
    flex-shrink: 0;
  }
  .select-model,
  .select-user {
    flex: 1;
    width: auto;
    min-width: 0;
  }
  /* 模型/人员下拉：统一玻璃风，与原生日期同高同边框，视觉一致 */
  .select-model :deep(.n-base-selection),
  .select-user :deep(.n-base-selection) {
    height: 34px;
    background: rgba(255, 255, 255, 0.62);
    border: 1px solid rgba(79, 110, 247, 0.25) !important;
    border-radius: 8px;
    box-shadow: none;
  }
  .select-model :deep(.n-base-selection-placeholder),
  .select-user :deep(.n-base-selection-placeholder) {
    color: #8a8f99;
  }
  /* 清空按钮：固定宽度，样式继承基础的主题蓝毛玻璃 */
  .reset-btn {
    flex: 0 0 auto;
    width: 76px;
  }
}
</style>
