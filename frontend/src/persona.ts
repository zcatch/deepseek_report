import type { RankTotal } from "./api";

// 人员画像：四维标签，全部由 rankTotal 现有字段推导，无后端改动
export type Scale = "重度" | "中度" | "轻度";
export type ModelPref = "Pro" | "Flash" | "混用";
export type UseMode = "读判" | "生成" | "均衡";
export type Efficiency = "省钱" | "持平" | "费钱";

export interface Persona {
  scale: Scale;
  model: ModelPref;
  mode: UseMode;
  eff: Efficiency;
  tagline: string; // 一句话画像：四维拼接
  energy: number; // 0~1，成本相对最大者的比例，驱动能量条长度
  radar: number[]; // 雷达图 5 轴 0~100，顺序与 RADAR_AXES 对齐
}

// 雷达图 5 轴名称（顺序即 radar 数组下标）
export const RADAR_AXES = ["用量", "生成", "命中", "性价比", "活跃"] as const;

const clamp100 = (v: number) => Math.max(0, Math.min(100, v));

// 一次性算全员画像（规模分档依赖全量成本排序）。
// activity：user -> 活跃天数；totalDays：统计天数，二者用于「活跃」轴。
export function computePersonas(
  rows: RankTotal[],
  activity?: Map<string, number>,
  totalDays?: number
): Map<string, Persona> {
  const map = new Map<string, Persona>();
  if (!rows.length) return map;

  const n = rows.length;
  const costs = rows.map(r => r.cost).sort((a, b) => b - a); // 降序
  const maxCost = costs[0] || 1;
  const days = totalDays && totalDays > 0 ? totalDays : 0;

  // 规模分位：前 1/3 重、后 1/3 轻、中间中；不足 3 人统一「中度」
  const hi = Math.floor(n / 3);
  const heavyTh = hi >= 1 ? costs[hi - 1] : 0;
  const lightTh = hi >= 1 ? costs[n - hi] : Infinity;

  // 生成倾向：outputRatio 绝对 0.2~8% 会塌缩，改算团队分位（最偏生成 = 100）
  const ratios = rows
    .map(r => (r.outputRatio != null ? parseFloat(r.outputRatio) : null))
    .filter((v): v is number => v != null)
    .sort((a, b) => a - b);
  const ratioPct = (v: number | null) => {
    if (v == null || ratios.length === 0) return 0;
    let less = 0;
    for (const x of ratios) if (x < v) less++;
    return (less / ratios.length) * 100;
  };

  // 性价比：total ÷ cost（每元 token 数），团队分位（越高越省）
  const values = rows.map(r => (r.cost > 0 ? r.total / r.cost : 0)).sort((a, b) => a - b);
  const valuePct = (v: number) => {
    if (values.length === 0) return 0;
    let less = 0;
    for (const x of values) if (x < v) less++;
    return (less / values.length) * 100;
  };

  for (const r of rows) {
    const proRatio = r.total > 0 ? r.pro / r.total : 0;
    const out = r.outputRatio != null ? parseFloat(r.outputRatio) : null;
    const hit = r.hitRate != null ? parseFloat(r.hitRate) : null;

    let scale: Scale = "中度";
    if (n >= 3) {
      if (r.cost >= heavyTh) scale = "重度";
      else if (r.cost <= lightTh) scale = "轻度";
    }

    let model: ModelPref;
    if (proRatio > 0.66) model = "Pro";
    else if (proRatio < 0.33) model = "Flash";
    else model = "混用";

    let mode: UseMode;
    if (out == null) mode = "均衡";
    else if (out < 0.5) mode = "读判";
    else if (out > 2) mode = "生成";
    else mode = "均衡";

    let eff: Efficiency;
    if (hit == null) eff = "持平";
    else if (hit > 95) eff = "省钱";
    else if (hit < 80) eff = "费钱";
    else eff = "持平";

    const energy = maxCost > 0 ? Math.max(0.05, Math.min(1, r.cost / maxCost)) : 0.05;
    const active = days > 0 && activity ? ((activity.get(r.user) ?? 0) / days) * 100 : 0;

    map.set(r.user, {
      scale,
      model,
      mode,
      eff,
      tagline: `${scale} · ${model} · ${mode} · ${eff}`,
      energy,
      radar: [
        clamp100(energy * 100),   // 用量：相对成本
        clamp100(ratioPct(out)),  // 生成：输出占比团队分位
        clamp100(hit ?? 0),       // 命中：缓存命中率（= 省钱）
        clamp100(valuePct(r.cost > 0 ? r.total / r.cost : 0)), // 性价比：每元 token（越高越省）
        clamp100(active),         // 活跃：活跃天数占比
      ],
    });
  }
  return map;
}
