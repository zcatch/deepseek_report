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
$GLOBALS['MODELS'] = ['proKeywords' => ['pro'], 'flashKeywords' => ['flash']];
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
function isPro($model, $models) {
  $lm = strtolower((string)$model);
  foreach ($models['proKeywords'] as $kw) if (strpos($lm, strtolower($kw)) !== false) return true;
  return false;
}

function dateRangeFromRows($rows) {
  $ds = [];
  foreach ($rows as $r) if (isset($r['d']) && $r['d'] !== '') $ds[$r['d']] = true;
  $keys = array_keys($ds);
  sort($keys);
  return ['min' => $keys[0] ?? '', 'max' => count($keys) ? $keys[count($keys) - 1] : '', 'days' => count($keys)];
}

function aggregate($rows, $models) {
  $users = []; $dcM = []; $duL = [];
  foreach ($rows as $r) {
    $n = $r['n']; $d = $r['d']; $m = $r['m']; $ty = $r['ty'];
    $amt = (float)$r['amt']; $pr = (float)$r['pr'];
    if (!isset($users[$n])) {
      $users[$n] = ['n' => $n, 'proT' => 0.0, 'flashT' => 0.0, 'proE' => 0.0, 'flashE' => 0.0,
        'pro' => ['ch' => 0.0, 'cm' => 0.0, 'out' => 0.0], 'flash' => ['ch' => 0.0, 'cm' => 0.0, 'out' => 0.0]];
    }
    $pro = isPro($m, $models);
    $e = $amt * $pr;
    $k = $ty === 'input_cache_hit_tokens' ? 'ch' : ($ty === 'input_cache_miss_tokens' ? 'cm' : 'out');
    if ($pro) { $users[$n]['proT'] += $amt; $users[$n]['proE'] += $e; $users[$n]['pro'][$k] += $amt; }
    else { $users[$n]['flashT'] += $amt; $users[$n]['flashE'] += $e; $users[$n]['flash'][$k] += $amt; }

    if (!isset($dcM[$d])) {
      $dcM[$d] = ['d' => $d, 'proE' => 0.0, 'flashE' => 0.0, 'proT' => 0.0, 'flashT' => 0.0,
        'proCh' => 0.0, 'proCm' => 0.0, 'flashCh' => 0.0, 'flashCm' => 0.0];
    }
    if ($pro) {
      $dcM[$d]['proE'] += $e; $dcM[$d]['proT'] += $amt;
      if ($k === 'ch') $dcM[$d]['proCh'] += $amt; elseif ($k === 'cm') $dcM[$d]['proCm'] += $amt;
    } else {
      $dcM[$d]['flashE'] += $e; $dcM[$d]['flashT'] += $amt;
      if ($k === 'ch') $dcM[$d]['flashCh'] += $amt; elseif ($k === 'cm') $dcM[$d]['flashCm'] += $amt;
    }
    $duL[] = [
      'n' => $n, 'd' => $d,
      'proT' => $pro ? $amt : 0, 'flashT' => $pro ? 0 : $amt,
      'proE' => $pro ? $e : 0, 'flashE' => $pro ? 0 : $e,
      'proCh' => $pro && $k === 'ch' ? $amt : 0,
      'proCm' => $pro && $k === 'cm' ? $amt : 0,
      'flashCh' => !$pro && $k === 'ch' ? $amt : 0,
      'flashCm' => !$pro && $k === 'cm' ? $amt : 0,
    ];
  }
  $t = 0.0; $pT = 0.0; $fT = 0.0; $pE = 0.0; $fE = 0.0;
  foreach ($users as $u) {
    $t += $u['proT'] + $u['flashT']; $pT += $u['proT']; $fT += $u['flashT'];
    $pE += $u['proE']; $fE += $u['flashE'];
  }
  return ['users' => array_values($users), 'dcM' => $dcM, 'duL' => $duL,
    't' => $t, 'pT' => $pT, 'fT' => $fT, 'pE' => $pE, 'fE' => $fE, 'tE' => $pE + $fE];
}

// ── 组装响应 JSON（等价 server.ts buildJson） ──
function buildJson($range) {
  $r = parse_range($range);
  $token = load_token();
  if ($token === '') throw new Exception('未找到 DeepSeek token（DS_REPORT_TOKEN / ~/.deepseek-report.json / 站点上一级 .ds_report_token.json）');
  $usage = fetch_usage($token, $r['startSec'], $r['endSec'], DS_TZ);
  $amountRows = $usage['amountRows']; $costRows = $usage['costRows']; $costTotal = $usage['costTotal'];

  if (count($amountRows) === 0) throw new Exception("范围「{$range}」无有效用量数据（可能是 API 无数据或 token 失效）");

  $agg = aggregate($amountRows, $GLOBALS['MODELS']);
  $dt = dateRangeFromRows($amountRows);
  $n = count($agg['users']); $d = $dt['days'];
  $apT = (int)round($agg['pT'] / $n); $afT = (int)round($agg['fT'] / $n); $atT = (int)round($agg['t'] / $n);

  $rankTotal = [];
  $tmp = $agg['users'];
  usort($tmp, function ($a, $b) { return ($b['proT'] + $b['flashT']) - ($a['proT'] + $a['flashT']); });
  foreach ($tmp as $i => $u) {
    $total = $u['proT'] + $u['flashT'];
    $input = $u['pro']['ch'] + $u['pro']['cm'] + $u['flash']['ch'] + $u['flash']['cm'];
    $output = $u['pro']['out'] + $u['flash']['out'];
    $rankTotal[] = [
      'rank' => $i + 1, 'user' => $u['n'], 'total' => $total, 'pro' => $u['proT'], 'flash' => $u['flashT'],
      'input' => $input, 'output' => $output, 'outputRatio' => outRatio($output, $total),
      'cost' => round2($u['proE'] + $u['flashE']),
      'hitRate' => hitRate($u['pro']['ch'] + $u['flash']['ch'], $u['pro']['cm'] + $u['flash']['cm']),
      'proHitRate' => hitRate($u['pro']['ch'], $u['pro']['cm']),
      'flashHitRate' => hitRate($u['flash']['ch'], $u['flash']['cm']),
    ];
  }

  $rankPro = [];
  $tmpP = array_values(array_filter($agg['users'], function ($u) { return $u['proT'] > 0; }));
  usort($tmpP, function ($a, $b) { return $b['proT'] - $a['proT']; });
  foreach ($tmpP as $i => $u) {
    $rankPro[] = ['rank' => $i + 1, 'user' => $u['n'], 'tokens' => $u['proT'], 'cost' => round2($u['proE']),
      'cacheHit' => $u['pro']['ch'], 'cacheMiss' => $u['pro']['cm'], 'output' => $u['pro']['out'],
      'hitRate' => hitRate($u['pro']['ch'], $u['pro']['cm'])];
  }
  $rankFlash = [];
  $tmpF = array_values(array_filter($agg['users'], function ($u) { return $u['flashT'] > 0; }));
  usort($tmpF, function ($a, $b) { return $b['flashT'] - $a['flashT']; });
  foreach ($tmpF as $i => $u) {
    $rankFlash[] = ['rank' => $i + 1, 'user' => $u['n'], 'tokens' => $u['flashT'], 'cost' => round2($u['flashE']),
      'cacheHit' => $u['flash']['ch'], 'cacheMiss' => $u['flash']['cm'], 'output' => $u['flash']['out'],
      'hitRate' => hitRate($u['flash']['ch'], $u['flash']['cm'])];
  }

  $cd = [];
  foreach ($costRows as $x) $cd[$x['d']] = ($cd[$x['d']] ?? 0.0) + $x['cost'];
  $trend = [];
  ksort($agg['dcM']);
  foreach ($agg['dcM'] as $day => $dc) {
    $trend[] = ['day' => fd($day), 'proEst' => round2($dc['proE']), 'flashEst' => round2($dc['flashE']),
      'actual' => round2($cd[$day] ?? 0.0),
      'hitRate' => hitRate($dc['proCh'] + $dc['flashCh'], $dc['proCm'] + $dc['flashCm'])];
  }

  $tpi = 0.0; $tfi = 0.0; $proChSum = 0.0; $flashChSum = 0.0; $totalOutput = 0.0;
  foreach ($agg['users'] as $u) {
    $tpi += $u['pro']['ch'] + $u['pro']['cm'];
    $tfi += $u['flash']['ch'] + $u['flash']['cm'];
    $proChSum += $u['pro']['ch'];
    $flashChSum += $u['flash']['ch'];
    $totalOutput += $u['pro']['out'] + $u['flash']['out'];
  }
  $proHit = $tpi > 0 ? sprintf('%.1f', $proChSum / $tpi * 100) : null;
  $flashHit = $tfi > 0 ? sprintf('%.1f', $flashChSum / $tfi * 100) : null;

  $perUser = [];
  foreach ($agg['users'] as $u) {
    $perUser[$u['n']] = [
      'total' => $u['proT'] + $u['flashT'], 'pro' => $u['proT'], 'flash' => $u['flashT'],
      'input' => $u['pro']['ch'] + $u['pro']['cm'] + $u['flash']['ch'] + $u['flash']['cm'],
      'output' => $u['pro']['out'] + $u['flash']['out'],
      'cost' => round2($u['proE'] + $u['flashE']),
      'proCh' => $u['pro']['ch'], 'proCm' => $u['pro']['cm'],
      'flashCh' => $u['flash']['ch'], 'flashCm' => $u['flash']['cm'],
      'daily' => [],
    ];
  }
  $dailyAgg = [];
  foreach ($agg['duL'] as $x) {
    $nm = $x['n'];
    if (!isset($dailyAgg[$nm])) $dailyAgg[$nm] = [];
    if (!isset($dailyAgg[$nm][$x['d']])) {
      $dailyAgg[$nm][$x['d']] = ['pro' => 0.0, 'flash' => 0.0, 'proCh' => 0.0, 'proCm' => 0.0, 'flashCh' => 0.0, 'flashCm' => 0.0, 'cost' => 0.0];
    }
    $e = &$dailyAgg[$nm][$x['d']];
    $e['pro'] += $x['proT']; $e['flash'] += $x['flashT'];
    $e['proCh'] += $x['proCh']; $e['proCm'] += $x['proCm'];
    $e['flashCh'] += $x['flashCh']; $e['flashCm'] += $x['flashCm'];
    $e['cost'] += $x['proE'] + $x['flashE'];
    unset($e);
  }
  foreach ($dailyAgg as $name => $dm) {
    if (!isset($perUser[$name])) continue;
    ksort($dm);
    $daily = [];
    foreach ($dm as $day => $e) {
      $daily[] = ['day' => fd($day), 'pro' => $e['pro'], 'flash' => $e['flash'], 'cost' => round2($e['cost']),
        'hitRate' => hitRate($e['proCh'] + $e['flashCh'], $e['proCm'] + $e['flashCm'])];
    }
    $perUser[$name]['daily'] = $daily;
  }

  return [
    'ok' => true,
    'range' => $r['label'], 'startIso' => $r['startIso'], 'endIso' => $r['endIso'],
    'unit' => $GLOBALS['COST_UNIT'],
    'meta' => [
      'users' => $n, 'days' => $d,
      'totalTokens' => $agg['t'], 'proTokens' => $agg['pT'], 'flashTokens' => $agg['fT'],
      'totalInput' => $tpi + $tfi, 'totalOutput' => $totalOutput,
      'avgTokens' => $atT, 'avgPro' => $apT, 'avgFlash' => $afT,
      'estimatedCost' => round2($agg['tE']), 'actualCost' => round2($costTotal),
      'proCacheHitRate' => $proHit, 'flashCacheHitRate' => $flashHit,
      'estLabel' => cny($agg['tE']), 'actualLabel' => cny($costTotal),
    ],
    'rankTotal' => $rankTotal, 'rankPro' => $rankPro, 'rankFlash' => $rankFlash,
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
