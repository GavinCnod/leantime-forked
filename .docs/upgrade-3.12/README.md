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

- [ ] 5 个 fork 钩子存在且语义正确
- [ ] CostTracking / TeamDocs 全功能 QA 通过
- [ ] 单元测试无新增失败；验收失败集合 = 已知基线
- [ ] PHPStan 无新增错误；前端构建成功
- [ ] DB 3.5.29 → 3.5.33 在 MySQL/PostgreSQL 均成功且幂等
- [ ] 插件去重、权限同步、角色迁移无异常
- [ ] `composer audit` 运行时无未登记漏洞
- [ ] PHP 8.4 生产镜像可启动，`plugin_ensure.php` 可幂等启用插件
- [ ] zh-CN 可解析、无重复 key、3.12 新 key 覆盖完整
