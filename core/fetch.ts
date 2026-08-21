#!/usr/bin/env bun
// DeepSeek 平台私有 API 直查：拉 token 用量 + 成本金额
// 端点：/api/v0/usage/by_api_key/amount 与 /api/v0/usage/by_api_key/cost
// 认证：Authorization: Bearer <userToken>

const BASE = "https://platform.deepseek.com";
const WAF_HEADERS = {
  "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
  "Referer": "https://platform.deepseek.com/usage",
  "Origin": "https://platform.deepseek.com",
  "Accept-Language": "zh-CN,zh;q=0.9",
};

export interface AmountRow { n: string; d: string; m: string; ty: string; pr: number; amt: number }
export interface CostRow { d: string; cost: number }

// unix 秒（UTC）→ YYYYMMDD
function toDate(sec: number): string {
  const dt = new Date(sec * 1000);
  return `${dt.getUTCFullYear()}${pad(dt.getUTCMonth() + 1)}${pad(dt.getUTCDate())}`;
}
const pad = (n: number) => String(n).padStart(2, "0");

async function apiGet(token: string, path: string, query: Record<string, string | number>) {
  const qs = Object.entries(query).map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`).join("&");
  const res = await fetch(`${BASE}${path}?${qs}`, {
    headers: { ...WAF_HEADERS, Authorization: `Bearer ${token}` },
  });
  if (res.status === 401 || res.status === 403) throw new Error("DeepSeek userToken 失效或无权限，请更新 ~/.deepseek-report.json 的 api.userToken（或 DS_REPORT_TOKEN 环境变量）后重试");
  if (res.status === 429) throw new Error("DeepSeek 返回 429（WAF 拦截），稍后重试或检查网络/代理");
  if (!res.ok) throw new Error(`DeepSeek API 请求失败: HTTP ${res.status} ${path}`);
  const j = await res.json() as any;
  if (j.code !== 0 || j.data?.biz_code !== 0) {
    const msg = j.msg || j.data?.msg || j.data?.message || "";
    const isAuth = /token|login|auth|expired|invalid|credential|过期|登录|失效|鉴权|凭证|未授权/i.test(msg);
    if (isAuth) throw new Error("DeepSeek userToken 失效或无权限，请更新 ~/.deepseek-report.json 的 api.userToken（或 DS_REPORT_TOKEN 环境变量）后重试");
    throw new Error(`DeepSeek API 业务错误: ${JSON.stringify(j)}`);
  }
  return j.data.biz_data;
}

export async function fetchUsage(token: string, startSec: number, endSec: number, tz: number): Promise<{
  amountRows: AmountRow[];
  costRows: CostRow[];
  costTotal: number;
}> {
  const amt = await apiGet(token, "/api/v0/usage/by_api_key/amount", { start: startSec, end: endSec, tz });
  const cost = await apiGet(token, "/api/v0/usage/by_api_key/cost", { start: startSec, end: endSec, tz });

  // 1. 汇总 cost：按 (用户|模型|天) → 成本，用于构造平均单价 pr
  const costMap = new Map<string, number>();
  const costRows: CostRow[] = [];
  let costTotal = 0;
  for (const block of cost.data ?? []) {
    for (const series of block.series ?? []) {
      const name = series.api_key?.name ?? "未知";
      const model = series.model ?? "";
      for (const b of series.buckets ?? []) {
        const c = parseFloat(b.cost) || 0;
        costTotal += c;
        const d = toDate(b.time);
        costRows.push({ d, cost: c });
        const key = `${name}|${model}|${d}`;
        costMap.set(key, (costMap.get(key) ?? 0) + c);
      }
    }
  }

  // 2. 汇总 amount token：按 (用户|模型|天) → 总 token，并展开成行
  const tokenMap = new Map<string, number>();
  const rawRows: { n: string; d: string; m: string; ty: string; amt: number }[] = [];
  for (const series of amt.series ?? []) {
    const name = series.api_key?.name ?? "未知";
    const model = series.model ?? "";
    for (const b of series.buckets ?? []) {
      const d = toDate(b.time);
      const u = b.usage ?? {};
      const ch = u.PROMPT_CACHE_HIT_TOKEN ?? 0;
      const cm = u.PROMPT_CACHE_MISS_TOKEN ?? 0;
      const out = u.RESPONSE_TOKEN ?? 0;
      const total = ch + cm + out; // REQUEST 是请求次数非 token，忽略
      if (total > 0) {
        const key = `${name}|${model}|${d}`;
        tokenMap.set(key, (tokenMap.get(key) ?? 0) + total);
      }
      if (ch > 0) rawRows.push({ n: name, d, m: model, ty: "input_cache_hit_tokens", amt: ch });
      if (cm > 0) rawRows.push({ n: name, d, m: model, ty: "input_cache_miss_tokens", amt: cm });
      if (out > 0) rawRows.push({ n: name, d, m: model, ty: "output_tokens", amt: out });
    }
  }

  // 3. 构造 AmountRow：pr = 该(用户|模型|天)组 cost / 总 token，使 amt×pr 累加 = 组成本（估算成本=实际扣费）
  const amountRows: AmountRow[] = rawRows.map(r => {
    const key = `${r.n}|${r.m}|${r.d}`;
    const tot = tokenMap.get(key) ?? 0;
    const c = costMap.get(key) ?? 0;
    return { ...r, pr: tot > 0 ? c / tot : 0 };
  });

  return { amountRows, costRows, costTotal };
}
