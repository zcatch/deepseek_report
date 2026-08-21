#!/usr/bin/env bun
// DeepSeek 用量可视化服务：局域网访问，实时直查 DeepSeek API + 聚合 + 托管前端静态产物
// 用法: bun server.ts   （监听 0.0.0.0:config.server.port）
// 纯脚本运行，零 agent、零 Claude token。数据实时来自 API，无本地缓存落盘。
import { readFileSync, existsSync } from "node:fs";
import { join, dirname, resolve } from "node:path";
import { homedir } from "node:os";
import { fileURLToPath } from "node:url";
import { parseRange } from "./core/daterange";
import { fetchUsage } from "./core/fetch";
import { aggregate, dateRangeFromRows, type Models } from "./core/aggregate";

const __dirname = dirname(fileURLToPath(import.meta.url));
const cfg = JSON.parse(readFileSync(join(__dirname, "config.json"), "utf-8"));

// ── token：复用本机私有 ~/.deepseek-report.json（与 deepseek-report skill 同一机制） ──
function loadToken(): string {
  if (process.env.DS_REPORT_TOKEN) return process.env.DS_REPORT_TOKEN;
  const p = join(homedir(), ".deepseek-report.json");
  if (existsSync(p)) {
    try {
      return JSON.parse(readFileSync(p, "utf-8")).api?.userToken ?? "";
    } catch {
      return "";
    }
  }
  return "";
}
const TOKEN = loadToken();
if (!TOKEN) console.warn("[warn] 未找到 DeepSeek token（~/.deepseek-report.json 或 DS_REPORT_TOKEN），API 直查将失败");

// ── 日期/天工具 ──
const pad = (n: number) => String(n).padStart(2, "0");
function dayToSec(day: string): number {
  const y = +day.slice(0, 4), m = +day.slice(4, 6), d = +day.slice(6, 8);
  return Math.floor(Date.UTC(y, m - 1, d) / 1000);
}
// "2026-08-01"~"2026-08-31" → ["20260801", ...]
function daysBetween(startIso: string, endIso: string): string[] {
  const out: string[] = [];
  const [sy, sm, sd] = startIso.split("-").map(Number);
  const [ey, em, ed] = endIso.split("-").map(Number);
  let t = Date.UTC(sy, sm - 1, sd);
  const end = Date.UTC(ey, em - 1, ed);
  while (t <= end) {
    const dt = new Date(t);
    out.push(`${dt.getUTCFullYear()}${pad(dt.getUTCMonth() + 1)}${pad(dt.getUTCDate())}`);
    t += 86400000;
  }
  return out;
}
// ── 聚合 → 前端 JSON ──
const round2 = (v: number) => Math.round(v * 100) / 100;
// 缓存命中率 = 命中 / (命中 + 未命中)，无输入数据时为 null（前端显示 —）
const hitRate = (ch: number, cm: number) => (ch + cm > 0 ? ((ch / (ch + cm)) * 100).toFixed(1) : null);
// 输出占比 = 输出 / 总 token，无数据时为 null（前端显示 —）
const outRatio = (output: number, total: number) => (total > 0 ? ((output / total) * 100).toFixed(1) : null);
const SYMBOL: Record<string, string> = { CNY: "¥", USD: "$" };
const cny = (v: number) => (SYMBOL[cfg.cost.unit] ?? cfg.cost.unit) + v.toFixed(cfg.cost.precision);
const fd = (d: string) => d.slice(4, 6) + "-" + d.slice(6, 8);

async function buildJson(range: string) {
  const r = parseRange(range);
  const targetDays = daysBetween(r.startIso, r.endIso);
  const startSec = dayToSec(targetDays[0]);
  const endSec = dayToSec(targetDays[targetDays.length - 1]) + 86400;
  const { amountRows, costRows, costTotal } = await fetchUsage(TOKEN, startSec, endSec, cfg.api.tz ?? 28800);

  if (amountRows.length === 0) {
    throw new Error(`范围「${range}」无有效用量数据（可能是 API 无数据或 token 失效）`);
  }

  const agg = aggregate(amountRows, cfg.models as Models);
  const dt = dateRangeFromRows(amountRows);
  const n = agg.users.length, d = dt.days;
  const apT = Math.round(agg.pT / n), afT = Math.round(agg.fT / n), atT = Math.round(agg.t / n);

  const rankTotal = [...agg.users]
    .sort((a, b) => (b.proT + b.flashT) - (a.proT + a.flashT))
    .map((u, i) => {
      const total = u.proT + u.flashT;
      const input = u.pro.ch + u.pro.cm + u.flash.ch + u.flash.cm;
      const output = u.pro.out + u.flash.out;
      return { rank: i + 1, user: u.n, total, pro: u.proT, flash: u.flashT, input, output, outputRatio: outRatio(output, total), cost: round2(u.proE + u.flashE), hitRate: hitRate(u.pro.ch + u.flash.ch, u.pro.cm + u.flash.cm), proHitRate: hitRate(u.pro.ch, u.pro.cm), flashHitRate: hitRate(u.flash.ch, u.flash.cm) };
    });
  const rankPro = agg.users.filter(u => u.proT > 0).sort((a, b) => b.proT - a.proT)
    .map((u, i) => ({ rank: i + 1, user: u.n, tokens: u.proT, cost: round2(u.proE), cacheHit: u.pro.ch, cacheMiss: u.pro.cm, output: u.pro.out, hitRate: hitRate(u.pro.ch, u.pro.cm) }));
  const rankFlash = agg.users.filter(u => u.flashT > 0).sort((a, b) => b.flashT - a.flashT)
    .map((u, i) => ({ rank: i + 1, user: u.n, tokens: u.flashT, cost: round2(u.flashE), cacheHit: u.flash.ch, cacheMiss: u.flash.cm, output: u.flash.out, hitRate: hitRate(u.flash.ch, u.flash.cm) }));

  const cd = new Map<string, number>();
  for (const x of costRows) cd.set(x.d, (cd.get(x.d) || 0) + x.cost);
  const trend = [...agg.dcM.values()].sort((a, b) => a.d.localeCompare(b.d))
    .map(dc => ({ day: fd(dc.d), proEst: round2(dc.proE), flashEst: round2(dc.flashE), actual: round2(cd.get(dc.d) || 0), hitRate: hitRate(dc.proCh + dc.flashCh, dc.proCm + dc.flashCm) }));

  const tpi = agg.users.reduce((s, u) => s + u.pro.ch + u.pro.cm, 0);
  const tfi = agg.users.reduce((s, u) => s + u.flash.ch + u.flash.cm, 0);
  const proHit = tpi > 0 ? (agg.users.reduce((s, u) => s + u.pro.ch, 0) / tpi * 100).toFixed(1) : null;
  const flashHit = tfi > 0 ? (agg.users.reduce((s, u) => s + u.flash.ch, 0) / tfi * 100).toFixed(1) : null;

  // 按人聚合：汇总 + 每日 token 趋势（成本用估算 proE+flashE，因 costRows 无按人拆分）
  const perUser: Record<string, { total: number; pro: number; flash: number; input: number; output: number; cost: number; proCh: number; proCm: number; flashCh: number; flashCm: number; daily: { day: string; pro: number; flash: number; cost: number; hitRate: string | null }[] }> = {};
  for (const u of agg.users) {
    perUser[u.n] = { total: u.proT + u.flashT, pro: u.proT, flash: u.flashT, input: u.pro.ch + u.pro.cm + u.flash.ch + u.flash.cm, output: u.pro.out + u.flash.out, cost: round2(u.proE + u.flashE), proCh: u.pro.ch, proCm: u.pro.cm, flashCh: u.flash.ch, flashCm: u.flash.cm, daily: [] };
  }
  const dailyAgg = new Map<string, Map<string, { pro: number; flash: number; proCh: number; proCm: number; flashCh: number; flashCm: number; cost: number }>>();
  for (const x of agg.duL) {
    if (!dailyAgg.has(x.n)) dailyAgg.set(x.n, new Map());
    const dm = dailyAgg.get(x.n)!;
    const e = dm.get(x.d) ?? { pro: 0, flash: 0, proCh: 0, proCm: 0, flashCh: 0, flashCm: 0, cost: 0 };
    e.pro += x.proT; e.flash += x.flashT;
    e.proCh += x.proCh; e.proCm += x.proCm; e.flashCh += x.flashCh; e.flashCm += x.flashCm;
    e.cost += x.proE + x.flashE;
    dm.set(x.d, e);
  }
  for (const [name, dm] of dailyAgg) {
    if (!perUser[name]) continue;
    perUser[name].daily = [...dm.entries()].sort((a, b) => a[0].localeCompare(b[0]))
      .map(([d, e]) => ({ day: fd(d), pro: e.pro, flash: e.flash, cost: round2(e.cost), hitRate: hitRate(e.proCh + e.flashCh, e.proCm + e.flashCm) }));
  }

  return {
    ok: true,
    range: r.label,
    startIso: r.startIso,
    endIso: r.endIso,
    unit: cfg.cost.unit,
    meta: {
      users: n, days: d,
      totalTokens: agg.t, proTokens: agg.pT, flashTokens: agg.fT,
      totalInput: tpi + tfi,
      totalOutput: agg.users.reduce((s, u) => s + u.pro.out + u.flash.out, 0),
      avgTokens: atT, avgPro: apT, avgFlash: afT,
      estimatedCost: round2(agg.tE), actualCost: round2(costTotal),
      proCacheHitRate: proHit, flashCacheHitRate: flashHit,
      estLabel: cny(agg.tE), actualLabel: cny(costTotal),
    },
    rankTotal, rankPro, rankFlash, trend, perUser,
  };
}

// ── 静态托管前端 dist ──
const DIST_DIR = join(__dirname, "frontend", "dist");
function serveStatic(url: URL): Response {
  let pathname = decodeURIComponent(url.pathname);
  if (pathname === "/") pathname = "/index.html";
  const file = resolve(DIST_DIR, "." + pathname);
  if (!file.startsWith(resolve(DIST_DIR))) return new Response("Forbidden", { status: 403 });
  if (existsSync(file)) return new Response(Bun.file(file));
  const idx = join(DIST_DIR, "index.html");
  if (existsSync(idx)) return new Response(Bun.file(idx)); // SPA fallback
  return new Response("前端未构建：请先 cd frontend && bun install && bun run build", { status: 404 });
}

// ── 路由 ──
const server = Bun.serve({
  hostname: cfg.server.host,
  port: cfg.server.port,
  async fetch(req) {
    const url = new URL(req.url);
    try {
      if (url.pathname === "/api/usage") {
        const range = url.searchParams.get("range") || "近30天";
        return Response.json(await buildJson(range));
      }
      return serveStatic(url);
    } catch (e: any) {
      return Response.json({ ok: false, error: e?.message ?? String(e) }, { status: 500 });
    }
  },
});

console.log(`DeepSeek 用量可视化已启动`);
console.log(`  本机访问: http://localhost:${server.port}`);
console.log(`  局域网访问: http://<本机局域网IP>:${server.port}`);
