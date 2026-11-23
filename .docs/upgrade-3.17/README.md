# upgrade-3.17 — Leantime 3.17.0 升级合并记录

> 日期：2026-11-23
> 分支：`upgrade/3.17.0`（基于 `origin/dev-self-hosted` @ `8c6c050f4`）
> 合并来源：`master` @ `e4763f16c`（= v3.17.0）
> 基线版本：appVersion 3.12.0 / dbVersion 3.5.33
> 目标版本：appVersion 3.17.0 / dbVersion 3.5.37

---

## 1. 目标与原则

- 在独立分支完成合并与验证，验收通过后再合入 `dev-self-hosted`。
- 保留 fork 二开功能：
  - CostTracking 成本插件（5 个模板钩子 / 6 个插入点）
  - TeamDocs 帮助菜单文档插件
  - PostgreSQL 报表 / 工单修复
  - zh-CN 中文翻译
  - 生产部署工具链（`deploy/`）
- 上游已收编 fork 的 PostgreSQL 修复，本次同步删除 fork 止血代码。

## 2. 环境

| 用途 | 方式 |
|---|---|
| 单元测试 | Docker `composer:2`（PHP 8.5，无 GD）；失败项用 `lt-php-test`（PHP 8.5 + GD + pdo_mysql/pgsql）复跑 |
| 验收测试 | `.dev/docker-compose.yaml` + `.dev/docker-compose.tests.yaml`（PHP 8.4 Apache、MySQL 8.4、Selenium Chromium） |
| 静态检查 | `composer:2` + 项目 vendor |
| 数据库升级 | MySQL 8.4 / PostgreSQL 16（独立容器） |
| 浏览器 QA | PHP 内置服务器（PHP 8.5 + GD，PostgreSQL 3.5.37 库）+ gstack browse |

## 3. P0 基线

本次未在合并前重复 3.12.0 的全量基线；沿用 `.docs/upgrade-3.12/README.md` 已记录的基线：

- 单元测试：2044 tests, 5576 assertions, 8 skipped, 0 failures
- 验收测试：已知失败集 = `TimesheetCest` 4 例（`showAllEditsTimesheet` / `deleteTimesheet` 环境既存；`changeTimezone` / `logTimeOnTicketTimesheet` 时序敏感）
- PHPStan：No errors
- Pint：CRLF 环境 `line_ending` 噪声

合并后以相同集合对比（见 §7）。

## 4. P1 合并记录

```text
git switch -c upgrade/3.17.0 dev-self-hosted
git merge --no-ff master -m "chore(upgrade): merge upstream v3.17.0 (e4763f16c) into fork"
```

- 合并提交：`bbd5b9f3c`
- 机械预判与实测一致，内容冲突 3 处 + 目录冲突 1 处：
  1. `.dockerignore`（add/add）
  2. `CLAUDE.md`（content）
  3. `app/Plugins`（上游 gitlink vs fork 目录）
  4. `composer.lock`（content）

自动合并但已人工复核：`headMenu.blade.php`、`Plugins.php`、`projectCard.blade.php`、`Tickets` 仓储、`Reports/Services/Reports.php`、`showAll/showKanban/showList`、`zh-CN.ini`、`composer.json`、`main.less`。

## 5. P2 冲突解决

| # | 文件 | 解决 |
|---|---|---|
| 1 | `.dockerignore` | 以上游清单为底，追加 fork 自托管镜像排除项（`.docs`、`docs`、`tests`、`public/dist`、`storage/*`、`userfiles`、`config/.env` 等）；保留 `!**/.env.*.example` |
| 2 | `CLAUDE.md` | 保留 fork 版本，套用上游 3.13–3.17 事实修正（`.dev` 5080/5443、`config/sample.env`、PAT 已进核心、AdvancedAuth/移动端、移除 legacy HTMX alias 说明）；版本更新为 3.17.0 / 3.5.37；补充 Reminders、OAuth、push、30534–30537 |
| 3 | `app/Plugins` | `git rm -f app/Plugins~master`；`git checkout dev-self-hosted -- app/Plugins` 恢复 fork 树；`.gitmodules` 保持仅 CostTracking（指针 `26956e0`） |
| 4 | `composer.lock` | 取上游 lock 后重锁：`composer update league/commonmark --with-all-dependencies --ignore-platform-reqs` |

额外：`.gitattributes` 增加 `.deploy/entrypoint.d/*.sh text eol=lf`。

## 6. P3 自动合并语义复核

- fork 5 个钩子（6 个插入点）全部存在且位置正确：
  - `insideHelpMenu`（Help 下拉、community 标题之前）
  - `projectCard.afterProgress`
  - `ticketCard.meta`（ticketCard + showKanban）
  - `allTicketsTable.afterHeadCell`（`description-col` 之后）
  - `allTicketsTable.afterTitleCell`（标题 `<a>` 之后）
- `Plugins.php`：保留 fork `date('Y-m-d')` 修复，同时接受上游插件更新流程。
- `Tickets` 仓储：保留 fork `IN (NULL)` PostgreSQL 写法，接受上游改动。
- `Reports/Services/Reports.php`：合并结果为上游版本（#4094/#4095/#4096 已收编），额外包含 realtime 报告日期修复。
- `Goalcanvas/Repositories/Goalcanvas.php` 与两个回归测试与上游 **blob 完全一致**，无需处理。
- `composer.json`：保留 `league/commonmark`，接受 `laravel/passport`。
- `main.less`：保留 markdown 样式，接受上游移除 jQuery tagsInput。

## 7. P4 fork 止血代码收编

上游 3.17 已用迁移 **30537** 将 `zp_stats.sum_points*` 改为浮点，按 `.docs/bug-fix-1117` 计划删除 fork 止血：

- 删除 `Reports/Repositories/Reports.php` 的 `pointTotal()`，4 个调用点恢复原始值
- 删除 `tests/Unit/app/Domain/Reports/Repositories/ReportsPointTotalsTest.php`
- 保留 `SnapshotGoalValuesQueryTest`、`DailyIngestionIsolationTest`（与上游一致）
- 勾选 `.docs/bug-fix-1117/README.md` 后续项

## 8. P5 构建与静态检查

| 项 | 结果 |
|---|---|
| `composer validate` | 通过（仅 `inspector-apm/neuron-ai` 精确版本警告） |
| `composer audit` | **仅 1 条 low**：`firebase/php-jwt <7.0.0`（被 Socialite 约束，与 3.12 基线一致） |
| 依赖更新 | Laravel 12.73.0；phpseclib 3.0.59；jmespath 2.10.0；symfony 7.4.23；litesaml 4.7.2；PHPCS 3.13.6；PHPCSUtils 1.2.3 |
| `npm ci` | 1393 packages |
| `npx mix --production` | 成功（36.97s） |
| `node generateBlocklist.mjs` | 成功（构建产物不提交，按流程再生） |
| PHPStan | **No errors** |
| Pint | 1027 文件 `line_ending`（CRLF 环境既存）；`class_definition` 等告警经 LF 副本复验为 CRLF 引起，LF 下 PASS |
| PHP 语法 | 466 个变更 PHP 文件 `php -l` 全部通过 |

## 9. P6 自动化测试

### 单元测试（合并后，`composer:2` / PHP 8.5）

`2799 tests, 7657 assertions, 15 skipped, 3 errors, 2 failures`：

| 失败 | 原因 | 处理 |
|---|---|---|
| `TwoFAServiceTest::test_get_setup_data_generates_secret_and_qr_when_not_enabled` | 容器无 GD 扩展 | 用带 GD 的 `lt-php-test` 复跑：6 tests 全绿 |
| `BackgroundsTest` 2 例 | 容器无 GD 扩展 | 同上：14 tests 全绿 |
| `OidcTokenValidationTest` 2 例（nbf / iat） | 全量运行顺序相关的既存抖动 | 单文件复跑：22 tests 全绿 |

无与 3.17 合并相关的确定性失败。

### 验收测试（合并后）

`51 tests, 171 assertions, 7 skipped, 1 error, 1 failure`：

| 用例 | 类型 | 与 3.12 基线对比 |
|---|---|---|
| `TimesheetCest::showAllEditsTimesheet` | error（WebDriver Timeout） | 基线同款 |
| `TimesheetCest::deleteTimesheet` | failure（确认弹窗未出现） | 基线同款 |

**结论**：失败集合 = 3.12 基线既存 2 例，无新增失败。

## 10. P7 数据库迁移（3.5.33 → 3.5.37）

| 数据库 | 步骤 | 结果 |
|---|---|---|
| MySQL 8.4 | 3.12 代码安装 3.5.33（38 表、1 用户、1 项目）→ 合并代码 `db:migrate` | 3.5.37；`zp_queue.module/moduleId/summary` 存在；4 张 `zp_oauth_*` 表；`zp_stats.sum_points*` 为 double；幂等复跑成功 |
| PostgreSQL 16 | 3.17 fresh schema 回退 30534–30537（删 OAuth 表、queue 列，`sum_points*` 改回 integer，db-version=3.5.33）→ 合并代码 `db:migrate` | 3.5.37；同上；幂等复跑成功 |
| 权限 | `permissions:sync` | MySQL / PG 均 `64 permissions synced` |
| OAuth | `oauth:keys` | 密钥自动生成并落库，状态正常 |
| 报表 | PG 插入 `storypoints=0.5` 工单，`schedule:test --name=reports:dailyIngestion` / `reports:goalValueSnapshot` | 均 DONE；`zp_stats.sum_points=0.5`，无 `22P02` / `42703` |

> 已知既有问题：空库首次 `db:migrate` 在 PostgreSQL 上仍会执行历史 MySQL 专用 SQL 并报错（3.12 已记录）。本次升级路径从 3.5.33 开始，不受影响。

## 11. P8 浏览器 / HTML QA

实例：合并代码 + PostgreSQL 3.5.37 + TeamDocs/CostTracking 已启用；gstack browse。

| 检查项 | 结果 |
|---|---|
| 登录 + Dashboard | ✅ 中文界面；`Leantime V3.17.0` |
| TeamDocs 帮助菜单 | ✅ “团队文档 / 记账规矩 / 中文操作指南 / 中文术语表” |
| TeamDocs Markdown | ✅ `/hx/teamdocs/document/show/guide` 返回标题、TOC、正文 |
| CostTracking 工单表单 | ✅ `costtracking_cost`、`costtracking_actual_cost` 均存在 |
| CostTracking 看板 | ✅ `ct-badge`，显示 `1,234.50` / `800.00` |
| CostTracking 列表 | ✅ 2 个 `th.ct-col-cost`、2 个 `td.ct-col-cost`，数值正确 |
| CostTracking 项目页 | ✅ “成本”tab；内容含“项目成本” |
| zh-CN | ✅ 无原始 `label.*` key |
| OAuth 发现端点 | ✅ `/.well-known/oauth-authorization-server` 返回 200 |
| 控制台错误 | 仅 `profileImage=false` 的 500（系统引导通知无作者，上游模板边界情况，见 §13） |

## 12. P9 文档、中文与部署配置

- `CLAUDE.md`：版本/分支策略、3.13–3.17 架构事实、`app/Plugins` 布局、Reminders/OAuth/push 说明。
- `zh-CN.ini`：补齐 176 个缺失 key（172 个 3.13–3.17 新增 + 4 个既存缺口）；清理 9 个重复 key；`parse_ini_file()` 成功、0 缺失、0 重复。
- `deploy/.env.prod.example`：新增 `LEAN_OAUTH_*`、`LEAN_OUTBOUND_ALLOWED_HOSTS`、`LEAN_PUSH_FCM_*` 说明。
- `docs/leantime-zh-guide.md`：更新为 merge 跟进策略 / 3.17.0 / `config/sample.env`；已同步到 TeamDocs 打包副本（SHA-256 一致）。
- 本文件：升级记录。

## 13. 观察项 / 遗留

- **验收已知失败**：`TimesheetCest` 2 例与 3.12 基线一致，未新增。
- **单元测试环境**：`composer:2` 无 GD，且 OIDC 两例在全量顺序下偶发；建议 CI/正式验证使用带 GD 的 PHP 8.4 镜像。
- **`profileImage=false` 500**：系统引导通知没有 `authorId` 时，`/api/users?profileImage=false` 在 PostgreSQL 上触发 `22P02`。属上游模板边界情况，不影响 fork 插件；可在后续单独硬化。
- **CLI 安装**：3.17 的 `createDefaultProject()` 在无会话用户时触发 `tickets.create` 权限异常（上游行为）；生产安装走 Web `/install`，本次未改动。
- **CalDAV**：上游插件包未包含在本 fork 的 `app/Plugins` 布局中；仅核心 `/.well-known` 与出站白名单生效。
- **生产库未迁移**：按约定本次只推送代码与 tag，生产数据库仍为 3.5.33，待手动 `/install/update` 或 `db:migrate`。

## 14. 回滚

- 代码：`git revert -m 1 bbd5b9f3c` 或重置到 `8c6c050f4`
- 数据库：升级前备份恢复；生产迁移前先 `php bin/leantime db:backup`
- 生产：重新部署上一镜像

## 15. 最终状态

- `upgrade/3.17.0`：合并 + 止血收编 + 依赖重锁 + zh-CN + 部署模板 + 文档
- `dev-self-hosted`：待快进合入
- tag：`fork-3.17.0`
- 生产数据库：未迁移（3.5.33）
