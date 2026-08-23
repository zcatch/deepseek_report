export interface RankTotal {
  rank: number;
  user: string;
  total: number;
  pro: number;
  flash: number;
  input: number;
  output: number;
  outputRatio: string | null;
  cost: number;
  hitRate: string | null;
  proHitRate: string | null;
  flashHitRate: string | null;
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
  proEst: number;
  flashEst: number;
  actual: number;
  hitRate: string | null;
}

export interface PerUserDaily {
  day: string;
  pro: number;
  flash: number;
  cost: number;
  hitRate: string | null;
}

export interface PerUser {
  total: number;
  pro: number;
  flash: number;
  input: number;
  output: number;
  cost: number;
  proCh: number;
  proCm: number;
  flashCh: number;
  flashCm: number;
  daily: PerUserDaily[];
}

export interface UsageData {
  ok: boolean;
  range: string;
  startIso: string;
  endIso: string;
  unit: string;
  meta: {
    users: number;
    days: number;
    totalTokens: number;
    proTokens: number;
    flashTokens: number;
    totalInput: number;
    totalOutput: number;
    avgTokens: number;
    avgPro: number;
    avgFlash: number;
    estimatedCost: number;
    actualCost: number;
    proCacheHitRate: string | null;
    flashCacheHitRate: string | null;
    estLabel: string;
    actualLabel: string;
  };
  rankTotal: RankTotal[];
  rankPro: RankModel[];
  rankFlash: RankModel[];
  trend: TrendPoint[];
  perUser: Record<string, PerUser>;
}

export async function fetchUsage(range: string): Promise<UsageData> {
  const res = await fetch(`/api/usage.php?range=${encodeURIComponent(range)}`);
  return await res.json();
}
