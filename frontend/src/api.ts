// 类别颜色：按 categories 顺序循环取色（加第 N 类自动取第 N 色，超出循环复用）
export const CATEGORY_COLORS = [
  "#5b8df0", // 浅蓝（Pro）
  "#4bc899", // 薄荷（Flash）
  "#ee8cbd", // 浅粉（Vision）
  "#c4b5fd", // 浅紫
  "#fde68a", // 浅黄
  "#fda4af", // 浅玫瑰
  "#a5f3fc", // 浅青
  "#fbcfe8", // 浅粉紫
  "#fed7aa", // 浅橙
  "#b9e4c9", // 浅绿
];

// 每个类别对应的双色渐变（左→右），用于条形图等需要渐变的场景
export const CATEGORY_GRADIENTS: [string, string][] = [
  ["#5b8df0", "#8bb4e8"], // Pro 浅蓝→更浅蓝
  ["#4bc899", "#7bd0ac"], // Flash 薄荷→更浅薄荷
  ["#ee8cbd", "#f0aed0"], // Vision 浅粉→更浅粉
  ["#c4b5fd", "#ddd6fe"],
  ["#fde68a", "#fef3c7"],
  ["#fda4af", "#fecdd3"],
  ["#a5f3fc", "#cffafe"],
  ["#fbcfe8", "#fce7f3"],
  ["#fed7aa", "#ffedd5"],
  ["#b9e4c9", "#d1fae5"],
];

export interface Category {
  key: string;
  label: string;
}

export interface ModelMeta {
  tokens: number;
  avg: number;
  input: number;
  output: number;
  cost: number;
  hitRate: string | null;
}

export interface RankTotal {
  rank: number;
  user: string;
  total: number;
  input: number;
  output: number;
  outputRatio: string | null;
  cost: number;
  hitRate: string | null;
  models: Record<string, { tokens: number; hitRate: string | null }>;
}

export interface RankModel {
  rank: number;
  user: string;
  tokens: number;
  cost: number;
  cacheHit: number;
  cacheMiss: number;
  output: number;
  hitRate: string | null;
}

export interface TrendPoint {
  day: string;
  est: Record<string, number>;
  actual: number;
  hitRate: string | null;
}

export interface PerUserDaily {
  day: string;
  models: Record<string, number>;
  modelCh: Record<string, number>;
  modelCm: Record<string, number>;
  cost: number;
  hitRate: string | null;
}

export interface PerUserModel {
  tokens: number;
  ch: number;
  cm: number;
  out: number;
}

export interface PerUser {
  total: number;
  input: number;
  output: number;
  cost: number;
  models: Record<string, PerUserModel>;
  daily: PerUserDaily[];
}

export interface UsageData {
  ok: boolean;
  range: string;
  startIso: string;
  endIso: string;
  unit: string;
  categories: Category[];
  meta: {
    users: number;
    days: number;
    totalTokens: number;
    totalInput: number;
    totalOutput: number;
    avgTokens: number;
    estimatedCost: number;
    actualCost: number;
    byModel: Record<string, ModelMeta>;
    estLabel: string;
    actualLabel: string;
  };
  rankTotal: RankTotal[];
  rankByModel: Record<string, RankModel[]>;
  trend: TrendPoint[];
  perUser: Record<string, PerUser>;
}

export async function fetchUsage(range: string): Promise<UsageData> {
  const res = await fetch(`/api/usage.php?range=${encodeURIComponent(range)}`);
  return await res.json();
}
