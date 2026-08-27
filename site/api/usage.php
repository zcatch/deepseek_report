<?php
// DeepSeek 用量可视化 - PHP 后端（宝塔 nginx + PHP 部署版）
// 等价移植自 server.ts + core/*.ts：实时直查 DeepSeek API + 聚合 → 返回与前端一致的 JSON
// 依赖：PHP 7.4+（需启用 curl、json、mbstring 可无）
// 调用：GET /usage.php?range=近30天
// 注意：token 读取优先级见 load_token()，推荐放站点根目录上一级 .ds_report_token.json（web 访问不到）

date_default_timezone_set('Asia/Shanghai');

// ── 配置（与 web/config.json 对齐，部署时可改） ──
define('DS_API_BASE', 'https://platform.deepseek.com');
define('DS_TZ', 28800);                                    // UTC+8 偏移秒
// 模型 → 类别映射表（精确完整名匹配，忽略大小写/首尾空格）。
// 新增类别：在此加一类 + 下方 MODEL_LABELS 加一个展示名即可，前端自动遍历。
// 未命中映射的模型归「other」类，前端在「其他」里暴露，补一行模型名即可收敛。
$GLOBALS['MODEL_MAP'] = [
  'pro'    => ['deepseek-v4-pro'],
  'flash'  => ['deepseek-v4-flash'],
  'vision' => ['deepseek-v4-flash-vision-exp'],
];
$GLOBALS['MODEL_LABELS'] = [
  'pro' => 'Pro', 'flash' => 'Flash', 'vision' => 'Vision', 'other' => '其他',
];
$GLOBALS['COST_UNIT'] = 'CNY';
$GLOBALS['COST_PRECISION'] = 2;

// ── .env 解析：只读 DS_REPORT_TOKEN（支持引号包裹、# 注释） ──
function load_env_token(): string {
  // 与 usage.php 同目录 → 站点根 → 站点上一级
  foreach ([__DIR__ . '/.env', __DIR__ . '/../.env', __DIR__ . '/../../.env'] as $p) {
    if (!is_file($p)) continue;
    foreach (file($p, FILE_IGNORE_NEW_LINES) as $line) {
      $line = trim($line);
      if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
      list($k, $v) = explode('=', $line, 2);
      if (trim($k) !== 'DS_REPORT_TOKEN') continue;
      $v = trim($v);
      if (strlen($v) >= 2) {
        $c = $v[0];
        if (($c === '"' || $c === "'") && substr($v, -1) === $c) $v = substr($v, 1, -1);
      }
      if ($v !== '') return $v;
    }
  }
  return '';
}

// ── token：环境变量 → .env → HOME/.deepseek-report.json → /root → 站点 .ds_report_token.json ──
function load_token(): string {
  $env = getenv('DS_REPORT_TOKEN');
  if ($env) return $env;
  $t = load_env_token();
  if ($t !== '') return $t;
  // Windows 本地无 HOME 时用 USERPROFILE（如 C:\Users\86157），生产 Linux 走 HOME
  $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/root');
  $candidates = [
    $home . '/.deepseek-report.json',
    '/root/.deepseek-report.json',
    __DIR__ . '/../.ds_report_token.json',
  ];
  foreach ($candidates as $p) {
    if (is_file($p)) {
      $j = json_decode((string)file_get_contents($p), true);
      if (is_array($j)) {
        $t = (string)($j['api']['userToken'] ?? '');
        if ($t !== '') return $t;
      }
    }
  }
  return '';
}

// ── 数值/展示工具（与 TS toFixed/round 对齐） ──
function round2($v) { return round($v * 100) / 100; }
function hitRate($ch, $cm) { return ($ch + $cm > 0) ? sprintf('%.1f', $ch / ($ch + $cm) * 100) : null; }
function outRatio($output, $total) { return ($total > 0) ? sprintf('%.1f', $output / $total * 100) : null; }
function cny($v) {
  $sym = ['CNY' => '¥', 'USD' => '$'][$GLOBALS['COST_UNIT']] ?? $GLOBALS['COST_UNIT'];
  return $sym . number_format($v, $GLOBALS['COST_PRECISION'], '.', '');
}
function fd($d) { return substr($d, 4, 2) . '-' . substr($d, 6, 2); }

// ── DeepSeek API 直查（等价 core/fetch.ts，带 WAF 头） ──
// 本机 php.ini 的 curl.cainfo 指向不存在文件时（如 phpstudy 默认 D:\cacert.pem），
// 回退到本机常见 CA bundle，避免 HTTPS 调用失败。生产环境配置正常则不触发。
function ds_ensure_cainfo($ch) {
  $ini = (string)ini_get('curl.cainfo');
  if ($ini !== '' && !is_file($ini)) {
    foreach (['C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt'] as $p) {
      if (is_file($p)) { curl_setopt($ch, CURLOPT_CAINFO, $p); break; }
    }
  }
}

function ds_http_get($token, $path, $query) {
  $url = DS_API_BASE . $path . '?' . http_build_query($query);
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  ds_ensure_cainfo($ch);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    'Referer: https://platform.deepseek.com/usage',
    'Origin: https://platform.deepseek.com',
    'Accept-Language: zh-CN,zh;q=0.9',
    'Authorization: Bearer ' . $token,
  ]);
  $resp = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($resp === false || $err !== '') throw new Exception('cURL 请求失败: ' . $err);
  if ($status === 401 || $status === 403) throw new Exception('DeepSeek userToken 失效或无权限，请更新 token 后重试');
  if ($status === 429) throw new Exception('DeepSeek 返回 429（WAF 拦截），稍后重试或检查网络/代理');
  if ($status < 200 || $status >= 300) throw new Exception("DeepSeek API 请求失败: HTTP {$status} {$path}");
  $j = json_decode($resp, true);
  if (!is_array($j)) throw new Exception('DeepSeek API 响应解析失败');
  $code = $j['code'] ?? null;
  $biz = $j['data']['biz_code'] ?? null;
  if ($code !== 0 || $biz !== 0) {
    $msg = (string)($j['msg'] ?? ($j['data']['msg'] ?? ($j['data']['message'] ?? '')));
    $isAuth = (bool)preg_match('/token|login|auth|expired|invalid|credential|过期|登录|失效|鉴权|凭证|未授权/i', $msg);
    if ($isAuth) throw new Exception('DeepSeek userToken 失效或无权限，请更新 token 后重试');
    throw new Exception('DeepSeek API 业务错误: ' . json_encode($j, JSON_UNESCAPED_UNICODE));
  }
  return $j['data']['biz_data'];
}

function toDate($sec) { return gmdate('Ymd', (int)$sec); }

function fetch_usage($token, $startSec, $endSec, $tz) {
  $amt = ds_http_get($token, '/api/v0/usage/by_api_key/amount', ['start' => $startSec, 'end' => $endSec, 'tz' => $tz]);
  $cost = ds_http_get($token, '/api/v0/usage/by_api_key/cost', ['start' => $startSec, 'end' => $endSec, 'tz' => $tz]);

  // cost：按 (用户|模型|天) 汇总成本 + 平铺行
  $costMap = []; $costRows = []; $costTotal = 0.0;
  foreach (($cost['data'] ?? []) as $block) {
    foreach (($block['series'] ?? []) as $series) {
      $name = $series['api_key']['name'] ?? '未知';
      $model = (string)($series['model'] ?? '');
      foreach (($series['buckets'] ?? []) as $b) {
        $c = isset($b['cost']) && is_numeric($b['cost']) ? (float)$b['cost'] : 0.0;
        $costTotal += $c;
        $d = toDate($b['time']);
        $costRows[] = ['d' => $d, 'cost' => $c];
        $key = $name . '|' . $model . '|' . $d;
        $costMap[$key] = ($costMap[$key] ?? 0.0) + $c;
      }
    }
  }

  // amount：按 (用户|模型|天) 汇总 token + 展开 ch/cm/out 行
  $tokenMap = []; $rawRows = [];
  foreach (($amt['series'] ?? []) as $series) {
    $name = $series['api_key']['name'] ?? '未知';
    $model = (string)($series['model'] ?? '');
    foreach (($series['buckets'] ?? []) as $b) {
      $d = toDate($b['time']);
      $u = $b['usage'] ?? [];
      $ch = (float)($u['PROMPT_CACHE_HIT_TOKEN'] ?? 0);
      $cm = (float)($u['PROMPT_CACHE_MISS_TOKEN'] ?? 0);
      $out = (float)($u['RESPONSE_TOKEN'] ?? 0);
      $total = $ch + $cm + $out;
      if ($total > 0) {
        $key = $name . '|' . $model . '|' . $d;
        $tokenMap[$key] = ($tokenMap[$key] ?? 0.0) + $total;
      }
      if ($ch > 0) $rawRows[] = ['n' => $name, 'd' => $d, 'm' => $model, 'ty' => 'input_cache_hit_tokens', 'amt' => $ch];
      if ($cm > 0) $rawRows[] = ['n' => $name, 'd' => $d, 'm' => $model, 'ty' => 'input_cache_miss_tokens', 'amt' => $cm];
      if ($out > 0) $rawRows[] = ['n' => $name, 'd' => $d, 'm' => $model, 'ty' => 'output_tokens', 'amt' => $out];
    }
  }

  // 构造 AmountRow：pr = 该(用户|模型|天)组 cost / 总 token
  $amountRows = [];
  foreach ($rawRows as $r) {
    $key = $r['n'] . '|' . $r['m'] . '|' . $r['d'];
    $tot = $tokenMap[$key] ?? 0.0;
    $c = $costMap[$key] ?? 0.0;
    $amountRows[] = array_merge($r, ['pr' => $tot > 0 ? $c / $tot : 0.0]);
  }

  return ['amountRows' => $amountRows, 'costRows' => $costRows, 'costTotal' => $costTotal];
}

// ── 自然语言日期解析（等价 core/daterange.ts） ──
function iso($y, $m, $d) { return sprintf('%04d-%02d-%02d', $y, $m, $d); }
function md($m, $d) { return sprintf('%02d-%02d', $m, $d); }
function current_ymd($now) {
  return ['y' => (int)date('Y', $now), 'm' => (int)date('n', $now), 'd' => (int)date('j', $now)];
}
function addDays($a, $delta) {
  $t = gmmktime(0, 0, 0, $a['m'], $a['d'] + $delta, $a['y']);
  return ['y' => (int)gmdate('Y', $t), 'm' => (int)gmdate('n', $t), 'd' => (int)gmdate('j', $t)];
}
function daysInMonth($y, $m) { return (int)gmdate('j', gmmktime(0, 0, 0, $m + 1, 0, $y)); }
function build($start, $end) {
  $sameMonth = $start['y'] === $end['y'] && $start['m'] === $end['m'];
  return [
    'startSec' => gmmktime(0, 0, 0, $start['m'], $start['d'], $start['y']),
    'endSec' => gmmktime(0, 0, 0, $end['m'], $end['d'], $end['y']) + 86400,
    'startIso' => iso($start['y'], $start['m'], $start['d']),
    'endIso' => iso($end['y'], $end['m'], $end['d']),
    'label' => $sameMonth
      ? md($start['m'], $start['d']) . '~' . md($end['m'], $end['d'])
      : iso($start['y'], $start['m'], $start['d']) . '~' . iso($end['y'], $end['m'], $end['d']),
    'sameMonth' => $sameMonth,
  ];
}

function parse_range($input, $now = null) {
  if ($now === null) $now = time();
  $s = preg_replace('/\s+/', '', trim($input));
  if ($s === '') throw new Exception('日期范围为空');
  $cur = current_ymd($now);
  $m = [];

  if (preg_match('/^近(\d{1,3})天$/u', $s, $m)) {
    $n = (int)$m[1];
    if ($n < 1) throw new Exception("近{$n}天：天数至少为 1");
    return build(addDays($cur, -($n - 1)), $cur);
  }
  $dow = (int)date('w', $now);
  $sinceMon = $dow === 0 ? 6 : $dow - 1;
  if ($s === '上周') { $lastMon = addDays(addDays($cur, -$sinceMon), -7); return build($lastMon, addDays($lastMon, 6)); }
  if ($s === '本周') return build(addDays($cur, -$sinceMon), $cur);
  if ($s === '今天') return build($cur, $cur);
  if ($s === '本月') return build(['y' => $cur['y'], 'm' => $cur['m'], 'd' => 1], $cur);
  if ($s === '上月') {
    $ly = $cur['m'] === 1 ? $cur['y'] - 1 : $cur['y'];
    $lm = $cur['m'] === 1 ? 12 : $cur['m'] - 1;
    return build(['y' => $ly, 'm' => $lm, 'd' => 1], ['y' => $ly, 'm' => $lm, 'd' => daysInMonth($ly, $lm)]);
  }
  if (preg_match('/^(?:(\d{4})年)?(\d{1,2})月(?:份)?$/u', $s, $m)) {
    $y = isset($m[1]) && $m[1] !== '' ? (int)$m[1] : $cur['y'];
    $mo = (int)$m[2];
    if ($mo < 1 || $mo > 12) throw new Exception("月份无效: {$mo}");
    return build(['y' => $y, 'm' => $mo, 'd' => 1], ['y' => $y, 'm' => $mo, 'd' => daysInMonth($y, $mo)]);
  }

  $SEP = '(?:~|～|到|至|-|—)';
  if (preg_match('/^(\d{1,2})月(\d{1,2})(?:号|日)?' . $SEP . '(\d{1,2})月(\d{1,2})(?:号|日)?$/u', $s, $m)) {
    $m1 = (int)$m[1]; $d1 = (int)$m[2]; $m2 = (int)$m[3]; $d2 = (int)$m[4];
    if ($m1 < 1 || $m1 > 12 || $m2 < 1 || $m2 > 12) throw new Exception("月份无效: {$m[1]}月~{$m[3]}月");
    $endY = $m1 > $m2 ? $cur['y'] + 1 : $cur['y'];
    return build(['y' => $cur['y'], 'm' => $m1, 'd' => $d1], ['y' => $endY, 'm' => $m2, 'd' => $d2]);
  }
  if (preg_match('/^(\d{1,2})月(\d{1,2})(?:号|日)?' . $SEP . '(\d{1,2})(?:号|日)?$/u', $s, $m)) {
    $mo = (int)$m[1]; $d1 = (int)$m[2]; $d2 = (int)$m[3];
    if ($mo < 1 || $mo > 12) throw new Exception("月份无效: {$mo}");
    return build(['y' => $cur['y'], 'm' => $mo, 'd' => $d1], ['y' => $cur['y'], 'm' => $mo, 'd' => $d2]);
  }
  if (preg_match('/^(\d{1,2})(?:号|日)?' . $SEP . '(\d{1,2})(?:号|日)?$/u', $s, $m)) {
    return build(['y' => $cur['y'], 'm' => $cur['m'], 'd' => (int)$m[1]], ['y' => $cur['y'], 'm' => $cur['m'], 'd' => (int)$m[2]]);
  }

  throw new Exception("无法识别的日期范围: \"{$input}\"（支持：今天 / 本月 / 上月 / 8月份 / 8月1~5号 / 7月25~8月5 / 近7天 / 上周 / 近30天）");
}

// ── 聚合（等价 core/aggregate.ts） ──
// 精确完整名匹配：模型名（忽略大小写/首尾空格）完全等于映射表里的名才命中，否则归 other
function categorize($model, $map) {
  $m = strtolower(trim((string)$model));
  foreach ($map as $cat => $names) {
    foreach ($names as $name) {
      if ($m === strtolower(trim((string)$name))) return $cat;
    }
  }
  return 'other';
}

function dateRangeFromRows($rows) {
  $ds = [];
  foreach ($rows as $r) if (isset($r['d']) && $r['d'] !== '') $ds[$r['d']] = true;
  $keys = array_keys($ds);
  sort($keys);
  return ['min' => $keys[0] ?? '', 'max' => count($keys) ? $keys[count($keys) - 1] : '', 'days' => count($keys)];
}

function aggregate($rows, $map) {
  $users = []; $dcM = []; $duL = [];
  foreach ($rows as $r) {
    $n = $r['n']; $d = $r['d']; $m = $r['m']; $ty = $r['ty'];
    $amt = (float)$r['amt']; $pr = (float)$r['pr'];
    $cat = categorize($m, $map);
    $e = $amt * $pr;
    $k = $ty === 'input_cache_hit_tokens' ? 'ch' : ($ty === 'input_cache_miss_tokens' ? 'cm' : 'out');

    if (!isset($users[$n])) $users[$n] = ['n' => $n, 'models' => []];
    if (!isset($users[$n]['models'][$cat])) $users[$n]['models'][$cat] = ['tokens' => 0.0, 'cost' => 0.0, 'ch' => 0.0, 'cm' => 0.0, 'out' => 0.0];
    $users[$n]['models'][$cat]['tokens'] += $amt;
    $users[$n]['models'][$cat]['cost'] += $e;
    $users[$n]['models'][$cat][$k] += $amt;

    if (!isset($dcM[$d])) $dcM[$d] = ['d' => $d, 'models' => []];
    if (!isset($dcM[$d]['models'][$cat])) $dcM[$d]['models'][$cat] = ['tokens' => 0.0, 'cost' => 0.0, 'ch' => 0.0, 'cm' => 0.0];
    $dcM[$d]['models'][$cat]['tokens'] += $amt;
    $dcM[$d]['models'][$cat]['cost'] += $e;
    if ($k === 'ch') $dcM[$d]['models'][$cat]['ch'] += $amt;
    elseif ($k === 'cm') $dcM[$d]['models'][$cat]['cm'] += $amt;

    $duL[] = ['n' => $n, 'd' => $d, 'cat' => $cat, 'tokens' => $amt, 'cost' => $e,
      'ch' => $k === 'ch' ? $amt : 0.0, 'cm' => $k === 'cm' ? $amt : 0.0];
  }
  return ['users' => array_values($users), 'dcM' => $dcM, 'duL' => $duL];
}

// ── 组装响应 JSON（等价 server.ts buildJson） ──
function buildJson($range) {
  $r = parse_range($range);
  $token = load_token();
  if ($token === '') throw new Exception('未找到 DeepSeek token（DS_REPORT_TOKEN / ~/.deepseek-report.json / 站点上一级 .ds_report_token.json）');
  $usage = fetch_usage($token, $r['startSec'], $r['endSec'], DS_TZ);
  $amountRows = $usage['amountRows']; $costRows = $usage['costRows']; $costTotal = $usage['costTotal'];

  if (count($amountRows) === 0) throw new Exception("范围「{$range}」无有效用量数据（可能是 API 无数据或 token 失效）");

  $agg = aggregate($amountRows, $GLOBALS['MODEL_MAP']);
  $dt = dateRangeFromRows($amountRows);
  $n = count($agg['users']); $d = $dt['days'];
  $labels = $GLOBALS['MODEL_LABELS'];

  // 类别：映射表定义顺序固定；「other」仅在确有未知模型时追加
  $catKeys = array_keys($GLOBALS['MODEL_MAP']);
  $hasOther = false;
  foreach ($agg['users'] as $u) if (isset($u['models']['other'])) { $hasOther = true; break; }
  if ($hasOther) $catKeys[] = 'other';
  $categories = [];
  foreach ($catKeys as $c) $categories[] = ['key' => $c, 'label' => $labels[$c] ?? $c];

  // 分类合计 + 全局合计
  $byModel = [];
  foreach ($catKeys as $c) $byModel[$c] = ['tokens' => 0.0, 'input' => 0.0, 'output' => 0.0, 'cost' => 0.0, 'ch' => 0.0, 'cm' => 0.0];
  $totalTokens = 0.0; $totalInput = 0.0; $totalOutput = 0.0; $totalEst = 0.0;
  foreach ($agg['users'] as $u) {
    foreach ($u['models'] as $cat => $mc) {
      if (!isset($byModel[$cat])) $byModel[$cat] = ['tokens' => 0.0, 'input' => 0.0, 'output' => 0.0, 'cost' => 0.0, 'ch' => 0.0, 'cm' => 0.0];
      $byModel[$cat]['tokens'] += $mc['tokens'];
      $byModel[$cat]['input'] += $mc['ch'] + $mc['cm'];
      $byModel[$cat]['output'] += $mc['out'];
      $byModel[$cat]['cost'] += $mc['cost'];
      $byModel[$cat]['ch'] += $mc['ch'];
      $byModel[$cat]['cm'] += $mc['cm'];
      $totalTokens += $mc['tokens'];
      $totalInput += $mc['ch'] + $mc['cm'];
      $totalOutput += $mc['out'];
      $totalEst += $mc['cost'];
    }
  }
  $metaByModel = [];
  foreach ($byModel as $cat => $b) {
    $metaByModel[$cat] = [
      'tokens' => $b['tokens'],
      'avg' => $n > 0 ? (int)round($b['tokens'] / $n) : 0,
      'input' => $b['input'],
      'output' => $b['output'],
      'cost' => round2($b['cost']),
      'hitRate' => hitRate($b['ch'], $b['cm']),
    ];
  }

  // rankTotal：每人一行（跨模型合计 + 分类 token/命中率）
  $rankTotal = [];
  $tmp = $agg['users'];
  usort($tmp, function ($a, $b) {
    $ta = 0.0; foreach ($a['models'] as $m) $ta += $m['tokens'];
    $tb = 0.0; foreach ($b['models'] as $m) $tb += $m['tokens'];
    return $tb - $ta;
  });
  foreach ($tmp as $i => $u) {
    $total = 0.0; $input = 0.0; $output = 0.0; $cost = 0.0; $chSum = 0.0; $cmSum = 0.0; $models = [];
    foreach ($u['models'] as $cat => $mc) {
      $total += $mc['tokens'];
      $input += $mc['ch'] + $mc['cm'];
      $output += $mc['out'];
      $cost += $mc['cost'];
      $chSum += $mc['ch']; $cmSum += $mc['cm'];
      $models[$cat] = ['tokens' => $mc['tokens'], 'hitRate' => hitRate($mc['ch'], $mc['cm'])];
    }
    $rankTotal[] = [
      'rank' => $i + 1, 'user' => $u['n'], 'total' => $total,
      'input' => $input, 'output' => $output, 'outputRatio' => outRatio($output, $total),
      'cost' => round2($cost), 'hitRate' => hitRate($chSum, $cmSum), 'models' => $models,
    ];
  }

  // rankByModel：每个类别一份排行
  $rankByModel = [];
  foreach ($catKeys as $cat) {
    $rows = array_values(array_filter($agg['users'], function ($u) use ($cat) { return isset($u['models'][$cat]) && $u['models'][$cat]['tokens'] > 0; }));
    usort($rows, function ($a, $b) use ($cat) { return $b['models'][$cat]['tokens'] - $a['models'][$cat]['tokens']; });
    $rankByModel[$cat] = [];
    foreach ($rows as $i => $u) {
      $mc = $u['models'][$cat];
      $rankByModel[$cat][] = ['rank' => $i + 1, 'user' => $u['n'], 'tokens' => $mc['tokens'], 'cost' => round2($mc['cost']),
        'cacheHit' => $mc['ch'], 'cacheMiss' => $mc['cm'], 'output' => $mc['out'],
        'hitRate' => hitRate($mc['ch'], $mc['cm'])];
    }
  }

  // trend：每日实际扣费 + 分类估算
  $cd = [];
  foreach ($costRows as $x) $cd[$x['d']] = ($cd[$x['d']] ?? 0.0) + $x['cost'];
  $trend = [];
  ksort($agg['dcM']);
  foreach ($agg['dcM'] as $day => $dc) {
    $est = []; $chSum = 0.0; $cmSum = 0.0;
    foreach ($dc['models'] as $cat => $mc) {
      $est[$cat] = round2($mc['cost']);
      $chSum += $mc['ch']; $cmSum += $mc['cm'];
    }
    $trend[] = ['day' => fd($day), 'est' => $est, 'actual' => round2($cd[$day] ?? 0.0), 'hitRate' => hitRate($chSum, $cmSum)];
  }

  // perUser：每人分类明细 + 每日明细
  $perUser = [];
  foreach ($agg['users'] as $u) {
    $total = 0.0; $input = 0.0; $output = 0.0; $cost = 0.0; $models = [];
    foreach ($u['models'] as $cat => $mc) {
      $total += $mc['tokens'];
      $input += $mc['ch'] + $mc['cm'];
      $output += $mc['out'];
      $cost += $mc['cost'];
      $models[$cat] = ['tokens' => $mc['tokens'], 'ch' => $mc['ch'], 'cm' => $mc['cm'], 'out' => $mc['out']];
    }
    $perUser[$u['n']] = ['total' => $total, 'input' => $input, 'output' => $output, 'cost' => round2($cost), 'models' => $models, 'daily' => []];
  }
  $dailyAgg = [];
  foreach ($agg['duL'] as $x) {
    $nm = $x['n'];
    if (!isset($dailyAgg[$nm])) $dailyAgg[$nm] = [];
    if (!isset($dailyAgg[$nm][$x['d']])) $dailyAgg[$nm][$x['d']] = ['models' => [], 'ch' => 0.0, 'cm' => 0.0, 'cost' => 0.0];
    $e = &$dailyAgg[$nm][$x['d']];
    if (!isset($e['models'][$x['cat']])) $e['models'][$x['cat']] = 0.0;
    $e['models'][$x['cat']] += $x['tokens'];
    $e['ch'] += $x['ch']; $e['cm'] += $x['cm'];
    $e['cost'] += $x['cost'];
    unset($e);
  }
  foreach ($dailyAgg as $name => $dm) {
    if (!isset($perUser[$name])) continue;
    ksort($dm);
    $daily = [];
    foreach ($dm as $day => $e) {
      $daily[] = ['day' => fd($day), 'models' => $e['models'], 'cost' => round2($e['cost']), 'hitRate' => hitRate($e['ch'], $e['cm'])];
    }
    $perUser[$name]['daily'] = $daily;
  }

  return [
    'ok' => true,
    'range' => $r['label'], 'startIso' => $r['startIso'], 'endIso' => $r['endIso'],
    'unit' => $GLOBALS['COST_UNIT'],
    'categories' => $categories,
    'meta' => [
      'users' => $n, 'days' => $d,
      'totalTokens' => $totalTokens, 'totalInput' => $totalInput, 'totalOutput' => $totalOutput,
      'avgTokens' => $n > 0 ? (int)round($totalTokens / $n) : 0,
      'estimatedCost' => round2($totalEst), 'actualCost' => round2($costTotal),
      'byModel' => $metaByModel,
      'estLabel' => cny($totalEst), 'actualLabel' => cny($costTotal),
    ],
    'rankTotal' => $rankTotal, 'rankByModel' => $rankByModel,
    'trend' => $trend, 'perUser' => $perUser,
  ];
}

// ── 入口 ──
$range = isset($_GET['range']) && $_GET['range'] !== '' ? $_GET['range'] : '近30天';
try {
  $data = buildJson($range);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
