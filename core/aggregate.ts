// 聚合核心：按人/模型/天聚合 DeepSeek 用量行（CSV 与 API 通用）
// 从 deepseek-report skill 的 build.ts 抽出，models 判定改为参数注入，保持逻辑等价
import type { AmountRow } from "./fetch";

export interface UserData {
  n: string;
  proT: number;
  flashT: number;
  proE: number;
  flashE: number;
  pro: { ch: number; cm: number; out: number };
  flash: { ch: number; cm: number; out: number };
}

export interface DayCell {
  d: string;
  proE: number;
  flashE: number;
  proT: number;
  flashT: number;
  proCh: number;
  proCm: number;
  flashCh: number;
  flashCm: number;
}

export interface AggregateResult {
  users: UserData[];
  dcM: Map<string, DayCell>;
  duL: { n: string; d: string; proT: number; flashT: number; proE: number; flashE: number; proCh: number; proCm: number; flashCh: number; flashCm: number }[];
  t: number; // 总 token
  pT: number; // Pro token
  fT: number; // Flash token
  pE: number; // Pro 估算成本
  fE: number; // Flash 估算成本
  tE: number; // 估算总成本
}

export interface Models {
  proKeywords: string[];
  flashKeywords: string[];
}

// 从行数据算日期范围（YYYYMMDD 最小/最大 + 天数）
export function dateRangeFromRows(rows: AmountRow[]) {
  const ds = new Set<string>();
  for (const r of rows) if (r.d) ds.add(r.d);
  const s = [...ds].sort();
  return { min: s[0] ?? "", max: s[s.length - 1] ?? "", days: ds.size };
}

// 按 api_key_name 分组，模型判定：命中 pro → Pro；否则 → Flash（与 build.ts 原逻辑等价）
export function aggregate(rows: AmountRow[], models: Models): AggregateResult {
  const m = new Map<string, UserData>();
  const dcM = new Map<string, DayCell>();
  const duL: { n: string; d: string; proT: number; flashT: number; proE: number; flashE: number; proCh: number; proCm: number; flashCh: number; flashCm: number }[] = [];

  const isPro = (model: string) => models.proKeywords.some(k => model.toLowerCase().includes(k));

  for (const r of rows) {
    if (!m.has(r.n)) {
      m.set(r.n, {
        n: r.n, proT: 0, flashT: 0, proE: 0, flashE: 0,
        pro: { ch: 0, cm: 0, out: 0 }, flash: { ch: 0, cm: 0, out: 0 },
      });
    }
    const u = m.get(r.n)!;
    const pro = isPro(r.m);
    const e = r.amt * r.pr;
    const k = r.ty === "input_cache_hit_tokens" ? "ch" : r.ty === "input_cache_miss_tokens" ? "cm" : "out";

    if (pro) {
      u.proT += r.amt;
      u.proE += e;
      (u.pro as any)[k] += r.amt;
    } else {
      u.flashT += r.amt;
      u.flashE += e;
      (u.flash as any)[k] += r.amt;
    }

    if (!dcM.has(r.d)) dcM.set(r.d, { d: r.d, proE: 0, flashE: 0, proT: 0, flashT: 0, proCh: 0, proCm: 0, flashCh: 0, flashCm: 0 });
    const dc = dcM.get(r.d)!;
    if (pro) {
      dc.proE += e;
      dc.proT += r.amt;
      if (k === "ch") dc.proCh += r.amt;
      else if (k === "cm") dc.proCm += r.amt;
    } else {
      dc.flashE += e;
      dc.flashT += r.amt;
      if (k === "ch") dc.flashCh += r.amt;
      else if (k === "cm") dc.flashCm += r.amt;
    }
    duL.push({
      n: r.n, d: r.d,
      proT: pro ? r.amt : 0, flashT: pro ? 0 : r.amt,
      proE: pro ? e : 0, flashE: pro ? 0 : e,
      proCh: pro && k === "ch" ? r.amt : 0,
      proCm: pro && k === "cm" ? r.amt : 0,
      flashCh: !pro && k === "ch" ? r.amt : 0,
      flashCm: !pro && k === "cm" ? r.amt : 0,
    });
  }

  const users = [...m.values()];
  const sum = (f: (u: UserData) => number) => users.reduce((s, u) => s + f(u), 0);
  return {
    users,
    dcM,
    duL,
    t: sum(u => u.proT + u.flashT),
    pT: sum(u => u.proT),
    fT: sum(u => u.flashT),
    pE: sum(u => u.proE),
    fE: sum(u => u.flashE),
    tE: sum(u => u.proE + u.flashE),
  };
}
