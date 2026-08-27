// 类别颜色：按 categories 顺序循环取色（加第 N 类自动取第 N 色，超出循环复用）
export const CATEGORY_COLORS = [
  "#5470c6", // 蓝
  "#91cc75", // 绿
  "#ee6666", // 红
  "#fac858", // 黄
  "#73c0de", // 青
  "#fc8452", // 橙
  "#9a60b4", // 紫
  "#ea7ccc", // 粉
  "#48c9b0", // 青绿
  "#f7ba2a", // 金
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
