# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 项目定位

公司共用 DeepSeek 企业账号，官方后台没有「按人排行」视角。本服务实时直查 DeepSeek 平台私有用量 API 并聚合，浏览器看排行/趋势/画像。**零数据库、零缓存落盘，每次请求都是实时数据。**

## 结构：后端一份，前端一份

```
deepseek_report/
├── site/        # 唯一部署包 = 前端构建产物 + 后端 api/usage.php；覆盖上传到宝塔站点根
└── frontend/    # 前端源码（Vue3 + Vite），构建产物合入 site/
```

- **后端源只有 `site/api/usage.php` 一份**，改后端直接改它。不要再引入第二份副本——之前 `php/usage.php` 与 `site/api/usage.php` 双份维护导致过漂移。
- **`site/` 是宝塔站点根的 1:1 镜像**，部署就是把它里面的文件覆盖上传。改完前端要重新 build 并合入 `site/`，改完后端 `site/api/usage.php` 本身就在包里，直接上传即可。
- 配置也在后端文件里：`site/api/usage.php` 顶部的 `DS_TZ` / `$GLOBALS['MODELS']` / `$GLOBALS['COST_UNIT']` 常量，不是外部配置文件。

## 数据模型的两个反直觉点

**1. 单价 `pr` 是反推出来的。** DeepSeek 的 amount 接口给 token、cost 接口给金额，都按 (用户|模型|天) 分桶。代码把 `pr = 该组 cost ÷ 该组总 token`，于是 `Σ(amt × pr)` 恰好等于该组实际扣费——所以「估算成本」在分组层面等于实际扣费，差额只来自四舍五入。别把 `pr` 当成官方定价表里的单价。

**2. Pro/Flash 是二分，不是双向匹配。** 判定只跑 `isPro()`：模型名（小写）含 `proKeywords` 任一关键词 → Pro，**否则一律归 Flash**。`$GLOBALS['MODELS']['flashKeywords']` 声明了但**从未被读取**——是死配置。新增第三类模型需要改的是判定函数本身，不是加关键词。

其他口径：`REQUEST` 字段是请求次数不是 token，被忽略；命中率/输出占比在分母为 0 时返回 `null`，前端渲染成 `—`；日期分桶用 UTC（`gmdate`），查询参数带 `tz=28800` 交给服务端偏移。

## 日期范围是中文自然语言，且是 API 契约

前端把中文字符串原样塞进 `?range=`，后端 `parse_range()` 解析。支持：`今天` / `本周` / `上周` / `本月` / `上月` / `近N天` / `8月份` / `2026年8月` / `8月1~5号` / `7月25~8月5` / `1~5`。分隔符 `~ ～ 到 至 - —` 都认。

前端日期选择器（`RangeBar.vue` 的 `toRangeText`）也是把时间戳格式化回中文再发出去，只负责生成后端认得的字符串。

默认值不一致，别踩：前端首屏 `range = "今天"`，而后端缺省参数是 `近30天`。

## 前端

Vue 3 + Vite + naive-ui + ECharts。`App.vue`（546 行）是唯一的容器，持有全部状态，其余是纯展示组件。

- **模型/人员筛选是客户端过滤**，但 `onModelChange`/`onUserChange` 仍会触发 `query()` 重新请求整份数据（为了转圈反馈）。后端不接收筛选参数。
- `persona.ts` 的画像分档（重度/轻度、性价比、生成倾向）走**全团队分位**，不随筛选变化——刻意如此，避免只看某人时标签乱跳。
- 打包产物是根路径引用（`/assets/...`、`/api/usage.php`），**必须部署在域名根，不能放子目录**。
- dev 联调：由 start 域统一管理——`svc start dsreport` 后台隐藏起双服务（PHP 8000 + Vite 3210），`svc stop dsreport` 停止，`svc status` 看状态。也可用 `start-dev.bat` 一键起（会弹两个可见窗口）。`vite.config.ts` 已把 `/api` 代理到 `127.0.0.1:8000`，联调无需改配置；首次运行前先 `cd frontend && npm install --registry=https://registry.npmmirror.com`。

## 构建与部署

```bash
cd frontend
bun install                # 需代理：HTTPS_PROXY=http://127.0.0.1:7897
bun run build              # → frontend/dist/
```

然后把 `dist/` 内容覆盖进 `site/`（`index.html` + `assets/`），`api/` 已在 `site/` 中不要动。注意：

- `site/` 是**提交进 git 的构建产物**，asset 文件名带 hash（`index-lVSjEgXy.css` 等）。重新构建会产生新 hash，**必须删掉 `site/assets/` 里的旧文件**并更新 `site/index.html` 的引用，否则站点根会堆积孤儿文件。

无 lint、无 typecheck、无测试。`package.json` 只有 `dev` 和 `build`。

## 验证改动

没有测试套件，验证靠打接口。本地 phpstudy 把 `site/` 设为站点根后：

```bash
curl -s 'http://<host>/api/usage.php'                # 期望 {"ok":true,...
curl -s 'http://<host>/api/usage.php?range=近7天'     # 换范围
curl -s 'http://<host>/api/usage.php?range=乱写'      # 期望 {"ok":false,"error":"无法识别的日期范围..."}
```

改了聚合口径时，对同一 range 比对改动前后的 `meta.totalTokens` / `meta.estimatedCost` / `rankTotal[0]`，确认变化符合预期。

## token 与安全

token 是公司账号凭证，不进 git（`.env` 已在 `.gitignore`）。读取顺序（`site/api/usage.php` 的 `load_token()`）：

```
环境变量 DS_REPORT_TOKEN
  → .env（usage.php 同目录 → 站点根 → 上一级）
  → $HOME/.deepseek-report.json（Windows 回退 USERPROFILE）
  → /root/.deepseek-report.json
  → 站点根/.ds_report_token.json
```

**为什么必须有 `.env`：** Apache/PHP-FPM 服务进程没有 HOME/USERPROFILE 环境变量，读不到 `~/.deepseek-report.json`。这是真实踩坑，不是理论问题。

**nginx 部署必须手加 deny 规则。** `site/api/.htaccess` 只在 Apache 生效；nginx 下不加下面这条，公网可直接下载 `api/.env` 拿到 token（曾真实发生）：

```nginx
location ~ /\.(?!well-known) { deny all; }
```

这条 deny 规则只能加在宝塔面板的站点配置文件里，仓库里没有对应可上传的文件。

## 注释语言

代码注释、错误信息、提交信息全部中文。新增代码沿用。
