---
title: DeepSeek 用量可视化服务
date: 2026-08-21
tags:
  - deepseek
  - 可视化
  - php
  - 宝塔
aliases:
  - DS用量看板
  - 用量看板
status: active
---

# DeepSeek 用量可视化服务

> 公司共用 DeepSeek 企业账号，官方后台无「按人排行」视角。本服务实时直查用量 API，浏览器看「按人排行 + 每日趋势 + 人均成本」。**前端纯静态 + PHP 单文件后端，零数据库、零第三方依赖**。

## 架构

- **前端**：Vue3 + Vite 构建的纯静态（`site/index.html` + `assets/`），默认加载「今天」实时数据
- **后端**：`php/usage.php` 单文件——实时直查 DeepSeek API + 聚合，返回与前端一致的 JSON
- 无数据库、无缓存落盘，每次查询都是实时数据

## 目录结构

```
deepseek_report/
├── site/             # 部署包（前端产物 + api/usage.php + api/.env）
├── frontend/         # 前端源码（改完需重新构建）
├── php/usage.php     # 后端源文件（改完需同步到 site/api/usage.php）
├── core/             # 聚合逻辑 TS（移植来源）
├── server.ts         # 原 bun 后端（已弃用，仅留档）
└── config.json       # bun 版遗留（PHP 版配置在 usage.php 顶部常量，改配置直接改那里）
```

## 部署到测试环境（宝塔 nginx + PHP）

> 已部署到测试环境（宝塔 nginx + PHP 7.4+，实测全通，零数据库）

### 0. 前置（建站）

宝塔建站：nginx + **PHP 7.4+**（代码 7.0+ 兼容，8.x 也可），域名解析到测试机，SSL 按需开启。**无需数据库**，建站向导里数据库那步跳过即可。

### 1. 上传

把 `site/` 内容传进宝塔站点根：

```
站点根/
├── index.html
├── assets/index-*.js / .css
└── api/
    ├── usage.php
    ├── .env        # token 配置
    └── .htaccess   # Apache 用（nginx 下无效，无害）
```

> [!warning] `.env` 是隐藏文件，手动上传容易漏，务必确认已上传

### 2. 配置 token（api/.env）

```env
DS_REPORT_TOKEN=你的 userToken
```

本地包已填公司可用 token；换账号直接改这里。读取顺序见「配置说明」。

### 3. nginx 必须加 deny 规则（防 .env 泄露）

> [!warning] 关键
> `.htaccess` 对 nginx 无效。不加这条规则，公网可直接下载 `api/.env` 拿到 token（曾真实发生）。

宝塔 → 网站 → 站点 → 设置 → 配置文件，`server {}` 内加：

```nginx
# 禁止访问敏感隐藏文件（放行 .well-known 保 SSL 续期）
location ~ /\.(?!well-known) {
    deny all;
}
```

### 4. 验证

```bash
curl -s 'https://<域名>/api/usage.php'                            # {"ok":true,...
curl -s 'https://<域名>/api/usage.php?range=近7天'                  # HTTP 200
curl -s -o /dev/null -w '%{http_code}' 'https://<域名>/api/.env'   # 期望 403/404
# 浏览器打开首页，默认「今天」
```

### 5. 访问控制（公网必做）

测试环境公网可达，建议宝塔站点加 **Basic Auth** 或 **IP 白名单**，避免外部看到公司用量数据。

## 本地预览（phpstudy）

1. 把 `site/` 设为 phpstudy 站点根（PHP 7.4+）
2. `api/.env` 放同目录，token 自动读取
3. 浏览器访问即可

> 注意：本地 phpstudy 是 Apache 环境，`.htaccess` 生效；宝塔 nginx 需靠上面的 deny 规则。

## 配置说明

**token 读取顺序**（`load_token()`）：

```
环境变量 DS_REPORT_TOKEN
  → api/.env（与 usage.php 同目录，部署推荐）
  → 站点根/.env → 站点上一级/.env
  → ~/.deepseek-report.json（本机）
  → /root/.deepseek-report.json
  → 站点根/.ds_report_token.json（usage.php 上一级）
```

> 为何要 .env：Apache/PHP-FPM 服务进程没有 HOME/USERPROFILE 环境变量，读不到 `~/.deepseek-report.json`，必须靠 .env（真实踩坑）。

## 常见问题

| 现象 | 原因 | 解决 |
|------|------|------|
| 500 空 body | token 未找到（业务错误可能被 ErrorDocument 吞掉 body）| 配好 api/.env 即可；仍 500 时查 PHP 错误日志 |
| `api/.env` 能被下载 | nginx 无 deny 规则 | 加 `location ~ /\.(?!well-known)` |
| cURL 报 CA 错误 | php.ini `curl.cainfo` 指向不存在文件 | 代码已兜底（`ds_ensure_cainfo`）|
| WAF 429 | 直连被拦 | 已带 User-Agent/Referer/Origin 头 |

## 构建前端（改源码后）

```bash
cd frontend
bun install      # 需代理：HTTPS_PROXY=http://127.0.0.1:7897
bun run build    # 产物在 dist/
# 把 dist/ 内容 + api/ 重新组成 site/ 部署包
```

> 注意：asset 是根路径引用（`/assets/...`、`/api/usage.php`），**必须部署在域名根**，不能放子目录。
