#!/usr/bin/env bun
// 自然语言日期范围解析 → DeepSeek API 查询范围（unix 秒，UTC 对齐，左闭右开）

export interface DateRange {
  startSec: number;   // 起始日 UTC 00:00
  endSec: number;     // 结束日次日 UTC 00:00（左闭右开）
  startIso: string;   // "2026-08-01"
  endIso: string;     // "2026-08-31"
  label: string;      // "08-01~08-31" 或 "2026-07-25~2026-08-05"
  sameMonth: boolean; // 同月 → 落 YYYY-MM 目录；跨月/跨年 → 落根目录
}

const DAY = 86400;
const pad = (n: number) => String(n).padStart(2, "0");
const iso = (y: number, m: number, d: number) => `${y}-${pad(m)}-${pad(d)}`;
const md = (m: number, d: number) => `${pad(m)}-${pad(d)}`;
const utcSec = (y: number, m: number, d: number) => Math.floor(Date.UTC(y, m - 1, d) / 1000);

interface YMD { y: number; m: number; d: number }

function addDays(a: YMD, delta: number): YMD {
  const dt = new Date(Date.UTC(a.y, a.m - 1, a.d + delta));
  return { y: dt.getUTCFullYear(), m: dt.getUTCMonth() + 1, d: dt.getUTCDate() };
}

function daysInMonth(y: number, m: number): number {
  return new Date(Date.UTC(y, m, 0)).getUTCDate();
}

function build(start: YMD, end: YMD): DateRange {
  return {
    startSec: utcSec(start.y, start.m, start.d),
    endSec: utcSec(end.y, end.m, end.d) + DAY,
    startIso: iso(start.y, start.m, start.d),
    endIso: iso(end.y, end.m, end.d),
    label: (start.y === end.y && start.m === end.m) ? `${md(start.m, start.d)}~${md(end.m, end.d)}` : `${iso(start.y, start.m, start.d)}~${iso(end.y, end.m, end.d)}`,
    sameMonth: start.y === end.y && start.m === end.m,
  };
}

// 当前年月日（本地时区，即北京时间）
export function currentYMD(now = new Date()): YMD {
  return { y: now.getFullYear(), m: now.getMonth() + 1, d: now.getDate() };
}

export function parseRange(input: string, now = new Date()): DateRange {
  const s = input.trim().replace(/\s+/g, "");
  if (!s) throw new Error("日期范围为空");
  const cur = currentYMD(now);
  let m: RegExpMatchArray | null;

  // 1. 相对范围：近N天
  if ((m = s.match(/^近(\d{1,3})天$/))) {
    const n = parseInt(m[1], 10);
    if (n < 1) throw new Error(`近${n}天：天数至少为 1`);
    return build(addDays(cur, -(n - 1)), cur);
  }
  // 上周 / 本周
  const dow = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getDay();
  const sinceMon = dow === 0 ? 6 : dow - 1;
  if (s === "上周") {
    const lastMon = addDays(addDays(cur, -sinceMon), -7);
    return build(lastMon, addDays(lastMon, 6));
  }
  if (s === "本周") {
    return build(addDays(cur, -sinceMon), cur);
  }

  // 今天 / 本月（1号~今天）/ 上月（完整月）
  if (s === "今天") return build(cur, cur);
  if (s === "本月") return build({ y: cur.y, m: cur.m, d: 1 }, cur);
  if (s === "上月") {
    const ly = cur.m === 1 ? cur.y - 1 : cur.y;
    const lm = cur.m === 1 ? 12 : cur.m - 1;
    return build({ y: ly, m: lm, d: 1 }, { y: ly, m: lm, d: daysInMonth(ly, lm) });
  }

  // 2. 整月：8月 / 8月份 / 2026年8月
  if ((m = s.match(/^(?:(\d{4})年)?(\d{1,2})月(?:份)?$/))) {
    const y = m[1] ? parseInt(m[1], 10) : cur.y;
    const mo = parseInt(m[2], 10);
    if (mo < 1 || mo > 12) throw new Error(`月份无效: ${mo}`);
    return build({ y, m: mo, d: 1 }, { y, m: mo, d: daysInMonth(y, mo) });
  }

  const SEP = "(?:~|～|到|至|-|—)";
  // 3. 跨月：7月25~8月5（先于同月匹配）
  if ((m = s.match(new RegExp(`^(\\d{1,2})月(\\d{1,2})(?:号|日)?${SEP}(\\d{1,2})月(\\d{1,2})(?:号|日)?$`)))) {
    const m1 = parseInt(m[1], 10), d1 = parseInt(m[2], 10), m2 = parseInt(m[3], 10), d2 = parseInt(m[4], 10);
    if (m1 < 1 || m1 > 12 || m2 < 1 || m2 > 12) throw new Error(`月份无效: ${m[1]}月~${m[3]}月`);
    return build({ y: cur.y, m: m1, d: d1 }, { y: m1 > m2 ? cur.y + 1 : cur.y, m: m2, d: d2 });
  }
  // 4. 同月：8月1~5
  if ((m = s.match(new RegExp(`^(\\d{1,2})月(\\d{1,2})(?:号|日)?${SEP}(\\d{1,2})(?:号|日)?$`)))) {
    const mo = parseInt(m[1], 10), d1 = parseInt(m[2], 10), d2 = parseInt(m[3], 10);
    if (mo < 1 || mo > 12) throw new Error(`月份无效: ${mo}`);
    return build({ y: cur.y, m: mo, d: d1 }, { y: cur.y, m: mo, d: d2 });
  }
  // 5. 纯天数：1~5（默认当前月）
  if ((m = s.match(new RegExp(`^(\\d{1,2})(?:号|日)?${SEP}(\\d{1,2})(?:号|日)?$`)))) {
    return build({ y: cur.y, m: cur.m, d: parseInt(m[1], 10) }, { y: cur.y, m: cur.m, d: parseInt(m[2], 10) });
  }

  throw new Error(`无法识别的日期范围: "${input}"（支持：今天 / 本月 / 上月 / 8月份 / 8月1~5号 / 7月25~8月5 / 近7天 / 上周 / 近30天）`);
}
