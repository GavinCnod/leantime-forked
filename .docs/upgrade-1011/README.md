# upgrade-1011 — Leantime 3.11.0 升级合并完整记录

> 日期：2026-10-11
> 分支：`upgrade/3.11.0`（基于 `dev-self-hosted` @ `130d4c7c4`）
> 合并来源：`upstream/master` @ `b0268c95b`（= v3.11.0 标签 + 10 个提交）
> 基线：`056835f1c`（fork 分叉点，≈3.10.0 发布前；appVersion 3.9.8 / dbVersion 3.5.26）
> 目标版本：appVersion 3.11.0 / dbVersion 3.5.29

---

## 1. 背景与范围

fork 长期停留在 3.10.0 分叉点（49 个 fork 独有提交）。上游自 v3.11.0 带来大量变更：
Search 域（全新）、Webhooks、TicketHistory、组件化（chips/dropdown/select/view-tabs）、
原生 select 替换 slimselect、健康检查端点、VerifyRequestOrigin/TrustedAppUrl/OutboundUrlGuard
安全加固、限流与会话中间件重做、console 下插件加载、`Format::number()`（无 intl 回退）等。

本次将 `upstream/master` 合并进独立分支 `upgrade/3.11.0`，完整验证后再由负责人确认是否合入
`dev-self-hosted`。合并跨度：**75 个提交、657 个文件、+41302/−12504**。

机械预判（`git merge-tree` 干跑）冲突 **10 处**，实测一致（见 §6）。

## 2. 决策记录

| 决策点 | 结论 |
|---|---|
| PG 端到端验证环境 | 新建 `postgres:16-alpine` 容器（`leantime-pg-test`，`leantime_internal` 网络）自建端到端 |
| `format_number()` 处置 | 迁移到上游 `Format::number()`，删除 fork 助手及专属测试 |
| zh-CN 新增文案（en-US +205 行） | 本次不翻，记入 §15 遗留清单后续单独补 |
| 分支归宿 | 验证通过后推 `origin upgrade/3.11.0` 备份；合入 `dev-self-hosted` 待负责人确认 |

## 3. 环境

| 用途 | 方式 |
|---|---|
| 单元/验收测试 | `.dev/docker-compose.yaml` + `.dev/docker-compose.tests.yaml`（MySQL 8.4，`leantime-dev`/`leantime-db`） |
| PG 端到端 | `postgres:16-alpine`，容器名 `leantime-pg-test`（user/pass/db = leantime/leantime/leantime） |
| CLI 运行（install/update） | `docker run --rm --network leantime_internal -v E:/GhRepo/leantime-forked:/var/www/html -e LEAN_DB_* ... dev-leantime-dev:latest php bin/leantime db:migrate` |
| 依赖/构建 | host node v24 + npm 11（npx mix）；composer 通过 `composer:2` 镜像 |

## 4. P0 基线记录（`dev-self-hosted` @ `130d4c7c4`）

| 项 | 命令 | 结果 |
|---|---|---|
| 单元测试 | `codecept run Unit --steps` | **954 tests, 2384 assertions, 0 failures, 8 skipped**（6m14s） |
| PHPStan | `phpstan analyse -c .phpstan/phpstan.neon`（v1.x） | **No errors（971 文件）** |
| Pint | `pint --test --config .pint/pint.json` | exit 1：全仓 `line_ending`（CRLF 环境，已知基线）+ `tests/.../PgmPro/.../ResourceStructureServiceTest.php` 既存风格问题 |
| 验收测试 | `codecept clean && run Acceptance --steps` | 41 tests, 156 assertions, **1 error + 1 failure**, 5 skipped（17m30s）——均在 `TimesheetCest`，见附录 A |
| PG 旧库安装 | 基线代码 `bin/leantime db:migrate`（pgsql） | 38 张表；`zp_settings.db-version = 3.5.26`；admin 用户 + 默认项目已建 |

> 注：PG 首次安装进程在收尾阶段曾抛异常退出（栈来自 ConsoleKernel），但库状态完整；重跑
> `db:migrate` 输出 `Database Successfully Migrated`。已记录为观察项，不影响后续升级验证。

## 5. P1 合并记录

```text
git switch -c upgrade/3.11.0 dev-self-hosted
git merge upstream/master --no-edit
```

- 合并提交：`0cdb62935`（`chore(upgrade): merge upstream v3.11.0 (b0268c95b) into fork`）
- 冲突实测 **10 处**，与 `git merge-tree` 干跑完全一致
- `app/Plugins` 结构冲突（fork 删除子模块 gitlink vs upstream 更新 gitlink）：
  `git rm --cached app/Plugins~upstream_master`，保留 fork 的普通目录布局
  （CostTracking 子模块 + TeamDocs），`.gitmodules` 自动合并为仅 CostTracking

## 6. P2 冲突解决明细

| # | 文件 | 冲突内容 | 解决 |
|---|---|---|---|
| 1 | `Menu/Templates/headMenu.blade.php` | upstream 用 `<x-global::actions.dropdown>` 重写帮助菜单；fork 在旧结构插 `insideHelpMenu` 锚点 | 取 upstream（`--theirs`），在 submit_bug `</li>` 与 community 标题之间重插 `{{-- Plugins may add <li> entries to the Help dropdown here. --}} @dispatchEvent('insideHelpMenu')` |
| 2-5 | `Reports/Templates/partials/{goalTable, milestoneList, needsAttention, projectReportBody}.blade.php` | fork `format_number()` vs upstream `Format::number()`（同一问题的两种解法） | 取 upstream（`--theirs`） |
| 6 | `Views/Templates/components/statTile.blade.php` | 同上（delta 行） | 取 upstream（`--theirs`） |
| 7 | `Tickets/Templates/showAll.blade.php` | fork `afterHeadCell` 锚点 vs upstream 新增 `description-col` 表头（同一插入点） | 取 upstream，在 `description-col` th **之后**重插 `@dispatchEvent('allTicketsTable.afterHeadCell', ...)`（body 端 description td 紧邻 `beforeRowEnd`，插件 2 个 td 落行尾，18 列上下对齐） |
| 8 | `app/Plugins`（gitlink） | fork 删除子模块 vs upstream 更新 gitlink | 保留 fork：移除 `app/Plugins~upstream_master` 冲突项，`git ls-files app/Plugins` 共 12 项（CostTracking gitlink + TeamDocs 11 文件） |
| 9 | `composer.lock` | 双方各自更新 | 取 upstream，后置重锁：`composer update league/commonmark --with-all-dependencies`（`composer:2` 镜像，`--ignore-platform-reqs`） |
| 10 | `public/assets/less/main.less` | fork 两行 markdown import vs upstream `search.css` import | 手工合并：保留 `markdown.css`、`markdown-modal.less`、`search.css` 全部三行 |

解决后检查：`git diff --diff-filter=U` 为空；全仓无残留冲突标记（`git grep -E "^<<<<<<< |^>>>>>>> "` 无命中）。

## 7. P3 自动合并锚点复核

4 处 fork 锚点（位于 upstream 重写过的模板）自动合并后逐一核对语义位置，**均无需调整**：

| 锚点 | 位置 | 判定 |
|---|---|---|
| `ticketCard.meta` | `Tickets/Templates/partials/ticketCard.blade.php:71` | 卡片容器内、meta 区之后、卡片收尾之前 ✅ |
| `ticketCard.meta` | `Tickets/Templates/showKanban.blade.php:194` | 卡片正文之后、日期行之前 ✅ |
| `allTicketsTable.afterTitleCell` | `Tickets/Templates/showList.blade.php:98` | 标题 `<td>` 内、`<a>` 之后（行内角标语义）✅ |
| `projectCard.afterProgress` | `Projects/Templates/partials/projectCard.blade.php:54` | 进度块之后、卡片收尾之前 ✅ |

功能验证见 §12（P8 QA）。

## 8. P4 format_number 迁移

- 5 处模板（4 报表 partial + statTile）在冲突解决时已由 upstream 版接管（`Format::number`）
- 删除 `app/helpers.php` 中的 `format_number()` 定义；删除 `tests/Unit/app/FormatNumberHelperTest.php`
- `StatTileDeltaFormattingTest` 保留（纯渲染断言不变），更新类注释指向 `Format::number`
- 提交：`fa2969f69`（`refactor(core): retire fork format_number() in favor of upstream Format::number()`）
- 残留检查：`git grep format_number -- app tests` 无命中（仅 `.docs/` 历史文档保留叙述）

## 9. P5 构建与静态检查

| 项 | 命令 | 结果 |
|---|---|---|
| 依赖重锁 | `composer update league/commonmark --with-all-dependencies`（composer:2） | lock 含 `league/commonmark ^2.8` + `phpstan/phpstan ^2.1`；vendor 同步（`composer show phpstan/phpstan` 正常） |
| 前端依赖 | `npm ci` | 1393 packages（44s） |
| 前端构建 | `npx mix --production` + `node generateBlocklist.mjs` | 编译成功（45.23s，1 child warning） |
| 缓存清理 | `bootstrap/cache/*.php`、`storage/framework/{cache,sessions,views,viewPaths.php,composerPaths.php}` | 完成 |
| PHPStan | `phpstan analyse -c .phpstan/phpstan.neon`（v2.1） | **No errors（1070 文件）**（基线 971 → +99） |
| Pint | `pint --test --config .pint/pint.json` | exit 1（与基线同类：CRLF `line_ending`；告警文件 766 → 916，增量 = upstream 新增文件；无"基线干净文件"新增告警） |

> 操作注意：清缓存后直接跑 phpstan 会因 `.phpstan/bootstrap.php` require 缺失的
> `bootstrap/cache/packages.php` 而报 severe error；先执行一次 `php bin/leantime list`
> 引导应用重建缓存即可。

## 10. P6 自动化测试（升级后）

**单元测试**：`1858 tests, 4954 assertions, 8 skipped`，1 处失败 → 已修复后全绿：

- 唯一失败 `LanguageFilesParseTest`：`zh-CN.ini` 解析失败。根因是 fork 在 `130d4c7c4`
  加入的注释行 `#fork:补全缺失键(不与上游冲突,追加在此)` 含括号，PHP INI 扫描器报
  `syntax error, unexpected '('`，`parse_ini_file()` 对**整个文件**返回 false。
  **这是 dev-self-hosted 上的潜伏 bug**（运行时 zh-CN 加载同样受影响，被 7 天语言缓存
  掩盖），由 upstream 3.11 新增的 `LanguageFilesParseTest` 首次暴露。
  修复：改为 `;` 注释（INI 标准注释，任意字符安全），文件恢复解析（2196 keys），
  单测 `OK (1 test, 99 assertions)`。修复提交：`3e1222670`。
- 运行观察（操作性问题，非代码问题）：首轮全量单测因工具中止导致 stdout 管道失去读取端，
  进程写满管道后阻塞（`Ds` 状态）；改为容器内 `nohup` 后台运行 + 轮询日志后正常完成。

**验收测试**：`codecept clean && run Acceptance --steps`（结果见下方补充）。

## 11. P7 数据库升级端到端（PG / MySQL）

### PostgreSQL（生产同构，`postgres:16-alpine`）

| 步骤 | 结果 |
|---|---|
| 基线代码安装旧库 | 38 表；`db-version=3.5.26` |
| 升级分支代码 `db:migrate`（updateDB） | `Database Successfully Migrated`（exit 0） |
| 版本 | **3.5.29** |
| 30527 队列索引 | `idx_queue_channel_thedate_msghash` 已建（`pg_indexes` 可见） |
| 30528 权限重播 | `zp_roles=6 / zp_permissions=62 / zp_role_permissions=239` |
| 30529（MySQL 全文索引） | PG 上为 no-op —— 与 schema 快照 diff 一致 |
| schema 快照 diff（before/after 各 ~65KB） | 唯一增量 = `idx_queue_channel_thedate_msghash` |
| 幂等复跑 | 再次 `db:migrate` 成功，无额外变更 |
| `permissions:sync` | `[OK] 62 permissions synced` |
| 应用引导冒烟 | `php bin/leantime list` 正常 |

### MySQL（8.4，dev 栈 `leantime-db`，独立库 `leantime_upgrade_test`）

| 步骤 | 结果 |
|---|---|
| 基线代码安装旧库（git worktree `lt-fork-baseline` + vendor 附加卷） | 38 表；`db-version=3.5.26` |
| 升级分支 `db:migrate` | 成功 → **3.5.29** |
| 30529 FULLTEXT 索引 | `zp_tickets_search_ft`、`zp_canvas_items_search_ft`、`zp_comment_search_ft` 已建 |
| 权限重播 | `6 / 62 / 239` |
| 幂等复跑 | 成功 |

### 观察项（基线既有行为，与本次升级无关）

CLI 对**空库**执行 `db:migrate`：`setupDB()` 建库成功后 `updateDB()` 会从版本 0 起执行全部
历史 update，引用旧表名（如 `zp_wiki_articles`）导致报错 `exit 1`；但 schema/账号/版本均已
正确创建，**再次运行即正常**。PG/MySQL、基线代码均复现。web 安装流程（验收 `InstallCest`）
不受影响；生产升级走 `/install/update`（同一 `updateDB()`），本 E2E 已验证该路径成功。

## 12. P8 浏览器 QA

（待填）

## 13. P9 文档与部署配置同步

| 文件 | 变更 |
|---|---|
| `CLAUDE.md` | 版本 3.9.8 → 3.11.0（并注明 `dev-self-hosted` 在合入前仍是 3.9.8）；分支策略改为"自 2026-10-11 起通过 merge 跟进 upstream"；`format_number()` 三处引用（deploy 段、独立小节、helpers 段）改为 `Format::number()` 说明 |
| `deploy/.env.prod.example` | `LEAN_APP_URL` 增加"受信 URL"机制说明（找回密码/邀请邮件依赖）；`LEAN_TRUSTED_PROXIES` 更新为 3.11 语义（留空=默认私有网段；显式设置=访问白名单；勿用 `*`/公网 `REMOTE_ADDR`）；新增 `LEAN_SOFT_RELOAD`、`LEAN_ALLOW_TELEMETRY`（默认 false）、`LEAN_EMAIL_FROM_NAME`、`LEAN_CSRF_ORIGIN_CHECK=true`、限流两项（注释形式） |
| `.docs/upgrade-1011/zh-cn-missing-keys.txt` | zh-CN 全量缺失 key 清单（938） |
| `.docs/upgrade-1011/zh-cn-missing-new-311.txt` | 3.11 新增且 zh-CN 缺失的 key 清单（171） |

> OIDC 相关新变量（`LEAN_OIDC_REQUIRE_VERIFIED_EMAIL`、`LEAN_OIDC_SKIP_TLS_VERIFY`）未加入
> deploy 模板：当前部署未使用 OIDC，按需参照 `config/sample.env` 添加。

## 14. P10 收尾与提交清单

（待填）

## 15. 遗留事项

- [ ] **zh-CN 翻译缺口**：当前 zh-CN 相对 en-US 共缺 **938 个 key**（完整清单见
      [zh-cn-missing-keys.txt](zh-cn-missing-keys.txt)）；其中 **171 个是本次 3.11 新增**
      （en-US 合并区间 2906 → 3079，+175；清单见 [zh-cn-missing-new-311.txt](zh-cn-missing-new-311.txt)）。
      本次不翻，作为独立后续任务。
- [ ] `zh-CN.ini` 解析修复（提交 `3e1222670`）随本分支合并进入 `dev-self-hosted`；若本分支
      最终不合入，需将该修复 cherry-pick 回 `dev-self-hosted`（该 bug 存在于 `130d4c7c4`）。
- [ ] `upstream/master` 之后的新提交（v3.11.x 补丁）跟进策略待定（本次已建立 merge 血缘，
      后续可继续以 merge 方式跟进）。
- [ ] 本地 tag 未推送 origin（仅 `master` 分支已同步；`v3.10.x`/`v3.11.0` tag 已在本地 fetch）。

## 附录 A — 基线失败用例（升级后对比基准，不得新增）

| 套件 | 用例 | 类型 | 说明 |
|---|---|---|---|
| Acceptance | `TimesheetCest::showAllEditsTimesheet` | error | WebDriver `TimeoutException`：`#allTimesheetsTable_wrapper` 未出现 |
| Acceptance | `TimesheetCest::deleteTimesheet` | failure | 删除确认弹窗文案未出现 |
