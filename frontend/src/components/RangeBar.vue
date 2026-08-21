<script setup lang="ts">
import { ref, computed, watch } from "vue";
import { NButton, NButtonGroup, NSelect, NDatePicker } from "naive-ui";

const PRESETS = ["今天", "近7天", "近30天", "本月", "上月"];

const props = defineProps<{
  users: string[];
  model: string;
  user: string[];
}>();

const emit = defineEmits<{
  (e: "query", range: string): void;
  (e: "update:model", v: string): void;
  (e: "update:user", v: string[]): void;
}>();

const active = ref("今天");
const dateRange = ref<any>(null);

const modelOptions = [
  { label: "全部模型", value: "all" },
  { label: "V4-Pro", value: "pro" },
  { label: "V4-Flash", value: "flash" },
];

const userOptions = computed(() => props.users.map(u => ({ label: u, value: u })));

function onPreset(p: string) {
  active.value = p;
  dateRange.value = null;
  emit("query", p);
}

watch(dateRange, v => {
  if (v && v.length === 2) {
    active.value = "";
    emit("query", toRangeText(v));
  }
});

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
    <n-button-group>
      <n-button
        v-for="p in PRESETS"
        :key="p"
        :type="active === p ? 'primary' : 'default'"
        @click="onPreset(p)"
      >
        {{ p }}
      </n-button>
    </n-button-group>

    <n-date-picker
      v-model:value="dateRange"
      type="daterange"
      value-format="yyyy-MM-dd"
      clearable
      placeholder="选择日期范围"
      class="date-picker"
    />

    <div class="spacer" />

    <n-select
      :value="model"
      :options="modelOptions"
      class="select-model"
      @update:value="(v: string) => emit('update:model', v)"
    />

    <n-select
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
.select-model {
  width: 140px;
}
.select-user {
  width: 220px;
}
</style>
