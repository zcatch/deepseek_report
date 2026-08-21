// 数字格式化：token 量大，用 万/亿 单位；成本用 ¥
export function formatToken(n: number): string {
  if (n >= 1e8) return (n / 1e8).toFixed(2) + " 亿";
  if (n >= 1e4) return (n / 1e4).toFixed(1) + " 万";
  return n.toLocaleString("en-US");
}

export function formatInt(n: number): string {
  return n.toLocaleString("en-US");
}

export function formatCost(n: number, unit = "¥"): string {
  return unit + n.toFixed(2);
}

// 百分比："15.4" → "15.4%"；null（无数据）→ "—"
export function formatPercent(v: string | null): string {
  return v ? v + "%" : "—";
}

// 命中率：复用百分比格式化
export function formatHitRate(v: string | null): string {
  return formatPercent(v);
}

// 命中率颜色：<80 红、>95 绿、中间黄、无数据灰
export function hitRateColor(v: string | null): string {
  if (!v) return "#909399";
  const n = parseFloat(v);
  if (n < 80) return "#d03050";
  if (n > 95) return "#18a058";
  return "#f0a020";
}

// 输出占比颜色（输出/总 token，实测长尾 0.2%~8%）：<0.5 蓝(读判型)、0.5~2 黄(少量输出)、>2 橙(生成型)、无数据灰
export function outputRatioColor(v: string | null): string {
  if (!v) return "#909399";
  const n = parseFloat(v);
  if (n < 0.5) return "#409eff";
  if (n > 2) return "#ff7d00";
  return "#f0a020";
}
