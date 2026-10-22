# upgrade-3.12 — Leantime 3.12.0 升级合并记录

> 日期：2026-10-22
> 分支：`upgrade/3.12.0`（基于 `dev-self-hosted` @ `413b7d0eb`）
> 合并来源：`master` @ `8464a2eac`（= v3.12.0 + 33 个提交）
> 基线版本：appVersion 3.11.0 / dbVersion 3.5.29
> 目标版本：appVersion 3.12.0 / dbVersion 3.5.33

---

## 1. 目标与原则

- 在独立分支完成合并与验证，验收通过后再合入 `dev-self-hosted`。
- 保证 fork 二开功能不受影响：
  - CostTracking 成本插件
  - TeamDocs 帮助菜单文档插件
  - PostgreSQL 报表/工单修复
  - zh-CN 中文翻译
  - 生产部署工具链（`deploy/`）
- 不直接改写 CostTracking 子模块内容；如需适配，在插件仓库修改后 bump 指针。

## 2. 环境

| 用途 | 方式 |
|---|---|
| 单元/验收测试 | `.dev/docker-compose.yaml` + `.dev/docker-compose.tests.yaml` |
| 开发容器 PHP | PHP 8.2.33（基线分支 `.dev/dockerfile` 为 `php:8.2-apache`） |
| 数据库 | MySQL 8.4（测试库 `leantime_test`） |
| Docker | 25.0.3 |
| 生产镜像 | 当前 `deploy/Dockerfile.prod` 为 `php:8.3-fpm-alpine`；升级后计划升到 8.4 |

## 3. P0 基线记录（`dev-self-hosted` @ `413b7d0eb`）

| 项 | 命令 | 结果 |
|---|---|---|
| 单元测试 | `codecept run Unit --steps` | **1858 tests, 5053 assertions, 8 skipped, 0 failures**（15m21s） |
| PHPStan | `phpstan analyse -c .phpstan/phpstan.neon --memory-limit 2G` | **No errors（1070 文件）** |
| Pint | `pint --test --config .pint/pint.json` | exit 1：**934 个文件**告警，绝大多数为 CRLF 环境下的 `line_ending`，另有少量既存风格问题 |
| 验收测试 | `codecept clean && codecept run Acceptance --steps` | **49 tests, 171 assertions, 6 skipped, 2 failures**：`TimesheetCest::logTimeOnTicketTimesheet`、`TimesheetCest::deleteTimesheet` |
| 验收复跑 | `TimesheetCest` 单独复跑 | 运行中，结果待补 |

> 上次 3.11 升级的基线验收已知失败：`TimesheetCest::showAllEditsTimesheet`、`TimesheetCest::deleteTimesheet`
> 为环境既存失败；`changeTimezone`、`logTimeOnTicketTimesheet` 为慢环境/抖动。本次对比沿用该基线。
> 本次 `logTimeOnTicketTimesheet` 同样表现为 `finalSum` 未到 11 的慢环境抖动，`deleteTimesheet` 为既存失败。

## 4. 冲突预判（`git merge-tree --write-tree --name-only dev-self-hosted 8464a2eac`）

内容冲突：

1. `CLAUDE.md`
2. `app/Domain/Install/Repositories/Install.php`
3. `app/Domain/Tickets/Templates/partials/ticketCard.blade.php`
4. `app/Domain/Tickets/Templates/showList.blade.php`
5. `app/Language/zh-CN.ini`
6. `composer.lock`

文件/目录冲突：

7. `app/Plugins`：上游为 submodule gitlink，fork 为普通目录（含 CostTracking 子模块 + TeamDocs）

自动合并但必须做语义复核：

- `app/Domain/Menu/Templates/headMenu.blade.php`（`insideHelpMenu`）
- `app/Domain/Projects/Templates/partials/projectCard.blade.php`（`projectCard.afterProgress`）
- `app/Domain/Tickets/Templates/showAll.blade.php`（`allTicketsTable.afterHeadCell`）
- `app/Domain/Tickets/Templates/showKanban.blade.php`（`ticketCard.meta`）
- `app/Domain/Plugins/Services/Plugins.php`（`installdate` 修复 + 3.12 插件守卫）
- `app/Domain/Reports/Repositories/ReportEngine.php`（PG 修复 + Laravel 12）
- `app/Domain/Tickets/Repositories/Tickets.php`（PG 修复 + Laravel 12）
- `public/assets/less/main.less`（markdown 样式 + 3.12 新组件样式）

## 5. 决策记录

| 决策点 | 结论 |
|---|---|
| 合并方式 | 新建 `upgrade/3.12.0`，验收通过后再合 `dev-self-hosted` |
| zh-CN 合并 | 以上游 machine translation 为底，保留 fork 人工翻译；按 key 覆盖合并 |
| `app/Plugins` | 保留 fork 布局（CostTracking 子模块 + TeamDocs 普通目录） |
| `Install.php` | 取上游；fork 删除的 `sqlPrep()` 为未使用代码，可后续单独清理 |
| PHP 运行时 | 代码最低 8.2；升级后开发/生产镜像统一到 8.4 |
| 依赖安全 | 合并后跑 `composer audit`，至少清掉 jmespath、phpseclib；php-jwt 评估后记录 |

## 6. 后续阶段

- P1：合并 master 并解决 7 处冲突，恢复 5 个 fork 钩子
- P2：插件兼容、部署镜像 PHP 8.4、zh-CN 合并、文档同步
- P3：composer/npm 依赖与安全清尾
- P4：单元/验收/PHPStan/Pint、MySQL+PostgreSQL 迁移、浏览器 QA、部署冒烟
- P5：写入最终记录，合入 `dev-self-hosted`，推送并打 tag

## 7. 验收门槛

- [x] 5 个 fork 钩子存在且语义正确
- [x] CostTracking / TeamDocs 全功能 QA 通过
- [x] 单元测试无新增失败；验收失败集合 = 已知基线
- [x] PHPStan 无新增错误；前端构建成功
- [x] DB 3.5.29 → 3.5.33 在 MySQL/PostgreSQL 均成功且幂等
- [x] 插件去重、权限同步、角色迁移无异常
- [x] `composer audit` 运行时仅剩已登记的 php-jwt low
- [x] PHP 8.4 生产镜像可启动，`plugin_ensure.php` 可幂等启用插件
- [x] zh-CN 可解析、无重复 key、3.12 新 key 覆盖完整

## 8. P4 验证记录

### 合并后静态与单元测试（PHP 8.4.27）

| 项 | 结果 |
|---|---|
| 单元测试 | **2044 tests, 5576 assertions, 8 skipped, 0 failures**（1h08m，PHP 8.4 + Xdebug） |
| PHPStan | **No errors（1111 文件）** |
| Pint | exit 1：**974 个文件**告警；除 `LeantimeResponseInterface.php` 的 `class_definition` 外均为 `line_ending`（CRLF 环境既存问题） |
| zh-CN 解析 | `parse_ini_file()` 成功，3674 keys；合并后 3633 行 key，保留 fork 人工翻译 2151 处 |
| PHP 语法检查 | 478 个变更 PHP 文件 `php -l` 全部通过 |

### 依赖安全

| 项 | 结果 |
|---|---|
| `composer audit` | 仅剩 **1 条 low**：`firebase/php-jwt <7.0.0`（被 `laravel/socialite ^6.4` 约束，CVE-2025-45769） |
| 已清除 | Laravel 11 的 3 条、Guzzle 9 条、CommonMark 12 条、Flysystem 1 条、jmespath 代码注入、phpseclib SSRF/X25519、symfony/yaml 递归、PHPCS 工具链 2 条 |
| 版本 | `laravel/framework` v12.70.0；`phpseclib` 3.0.57；`jmespath` 2.9.2；`symfony/yaml` v7.4.21 |

### Acceptance（重建前端资源后）

| 项 | 结果 |
|---|---|
| 全量验收 | **51 tests, 168 assertions, 8 skipped, 2 errors + 2 failures** |
| 失败用例 | 全部在 `TimesheetCest`：`changeTimezone`（20s 等待超时，失败产物显示 `America/Los_Angeles selected="selected"`，功能已保存）、`logTimeOnTicketTimesheet`（慢环境抖动）、`showAllEditsTimesheet`（基线既存 WebDriver 超时）、`deleteTimesheet`（基线既存确认弹窗问题） |
| 登录/API | 资源重建后 `LoginCest`、`ApiCest`、`MCP` 均通过 |

> 首轮验收失败于登录后的 `.welcome-widget`，根因是合并后仍在使用 3.11 的 `public/dist` 资源；
> `npm ci && npx mix && node generateBlocklist.mjs` 重建后恢复。生产构建不受影响。

### 数据库迁移端到端

| 数据库 | 步骤 | 结果 |
|---|---|---|
| MySQL 8.4 | 3.12 schema → 回退到 3.5.29（删 `metadata`、`roleKey`、`baseRole`，删新权限，插入重复插件行）→ `db:migrate` | 3.5.33；`metadata`/`roleKey` 列恢复；2 个新权限恢复；插件重复行合并为 1 条（enabled，1.0.1）；幂等复跑成功 |
| PostgreSQL 16 | 同上（quoted camelCase 标识符） | 3.5.33；全部迁移项恢复；插件去重；幂等复跑成功 |

### 浏览器/HTML QA

| 检查项 | 结果 |
|---|---|
| TeamDocs 帮助菜单 | 3 项：`记账规矩`、`Leantime 中文操作指南`、`Leantime 中文术语表`；直接请求 htmx 文档返回 `md-body`、TOC、标题正常 |
| CostTracking 工单表单 | `name="costtracking_cost"`、`name="costtracking_actual_cost"` 均存在 |
| CostTracking 列表 | 2 个 `th.ct-col-cost`、20 个 `td.ct-col-cost`、合计块存在；无 500 |
| CostTracking 看板 | `ct-badge` / `ticketCard.meta` 渲染正常 |
| CostTracking 项目页 | Cost tab、`Project Cost Summary`、`Planned Cost`、`Actual Cost`、`Costed tasks` 正常 |
| zh-CN | 页面无 `label.*` 原始 key；登录、dashboard、项目、工单页均为中文 |
| 3.12 模块 | 项目设置 Modules 页 / hx 接口返回 7 个模块开关（goalcanvas/ideas/blueprints/wiki/files/reports/timesheets） |
| 3.12 背景/账户页 | `/users/editOwn` 正常渲染，无错误 |
| 插件页 | CostTracking / TeamDocs 已注册且无 “Not compatible” / 500 |
| 生产镜像 | `deploy/Dockerfile.prod` PHP 8.4 构建成功；容器 entrypoint 同步并启用两个插件；nginx/php-fpm/scheduler 启动；`/auth/login` 返回 200 |

### 部署脚本修复

Windows 工作区检出的 `deploy/*.sh` / `.docker/*.sh` 为 CRLF，导致生产镜像 `/entrypoint.sh` 报
`No such file or directory`。已新增 `.gitattributes`（`*.sh text eol=lf`），并在
`deploy/Dockerfile.prod` 的 chmod 步骤前加 `sed -i 's/\r$//'` 兜底。

## 9. P5 收尾

### 分支提交

| 提交 | 说明 |
|---|---|
| `b835df20d` | `docs(upgrade): record 3.12.0 P0 baseline and conflict prediction` |
| `0cba51c3f` | `chore(upgrade): merge upstream v3.12.0 (8464a2eac) into fork`（7 处冲突 + 5 个锚点恢复 + zh-CN key 合并） |
| `e5d8f74c5` | `chore(upgrade): target PHP 8.4 and clear runtime advisories` |
| 本次 | 生产镜像 CRLF 修复、P4 验证记录、最终文档 |

### 合入前检查

- [x] `git diff --diff-filter=U` 为空
- [x] 全仓无冲突标记
- [x] 5 个 fork 钩子存在：`insideHelpMenu`、`projectCard.afterProgress`、`ticketCard.meta`、`allTicketsTable.afterHeadCell`、`allTicketsTable.afterTitleCell`
- [x] `app/Plugins` 保持 fork 布局（CostTracking submodule + TeamDocs 普通目录），`.gitmodules` 仅 CostTracking
- [x] `composer.json` 保留 `league/commonmark`，加入 `composer/semver`
- [x] zh-CN `parse_ini_file()` 成功
- [x] PHP 8.4 生产镜像可启动

### 回滚

- 代码：`git reset --hard pre-3.12.0` 或 `git revert -m 1 0cba51c3f`
- 数据库：升级前备份恢复；本次迁移为增量（新增列/权限/插件去重），生产升级前先 `php bin/leantime db:backup`
- 生产：部署新镜像后通过 `/install/update` 或 `php bin/leantime db:migrate` 完成 3.5.33
