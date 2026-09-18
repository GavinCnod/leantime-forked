# bug-fix-0918 — PostgreSQL 报表采集失败修复

> 日期：2026-09-18
> 影响模块：Reports（报表）、Tickets、Install、Core/Db
> 相关文档：[ideas-0917 第 4 条](../ideas-0917/ideas.md)、[initial-0910/04-数据层](../initial-0910/04-数据层.md)、[initial-0910/10-领域模块地图](../initial-0910/10-领域模块地图.md)

---

## 1. 背景与现象

部署环境使用 **PostgreSQL**（`LEAN_DB_DEFAULT_CONNECTION=pgsql`），访问项目报表页 `/reports/show` 或运行每日报表定时任务时直接 500 / 任务中断，报错：

```
SQLSTATE[22P02]: invalid input syntax for type integer: "1,2,3"
CONTEXT: unnamed portal parameter $22 = '...'
  insert into "zp_stats" (... "tickets" ...) values (..., 1,2,3, ...)
```

MySQL 环境下不报错（隐式截断为第一个数字），因此长期未被发现。

---

## 2. 根因

报表每日采集由 `Reports\Controllers\Show::init()` → `Reports\Services\Reports::dailyIngestion()` → `Reports\Repositories\Reports::runTicketReport()` → `addReport()` 完成。

- `runTicketReport()` 用 `DatabaseHelper::stringAggregate()` 把任务 id 拼成逗号串，并以 `... AS tickets` 选出（`Reports.php` 原第 45/89 行）。
- `addReport()` 将该字符串写进 `zp_stats.tickets`（原第 195 行）。
- 该列类型不一致：
  - 全新安装由 `Install/Services/SchemaBuilder.php` 建为 `integer`；
  - 老安装由 `Install.php` 的原生 MySQL DDL 建为 `TEXT`。
- 在 PostgreSQL 上，`integer` 列收到 `"1,2,3"` → 类型错误，采集流程在 `addReport()` 处抛异常，且异常未被捕获，导致报表页 500、`cronDailyIngestion` 中断所有项目。

**关键结论**：`zp_stats.tickets` 是一个**死字段**——除 `getFullReport()` 里一次无意义的 `SUM(CAST(tickets AS INTEGER))` 外，没有任何模板 / JS / API / 业务逻辑读取它。这不是单纯的“PG 兼容”问题，而是一个写入即无用、且类型错误的设计缺陷。

**附带隐患**：空状态组被 `Tickets::getStatusListGroupedByType()` 表示为 MySQL 专有的 `IN(FALSE)`：
- `parseStatusGroups()` 的正则匹配不到该字面量 → 解析为 `[]` → 报表侧 `?: [0]` 回退成 `status IN (0)`，**静默算错**；
- `Goalcanvas` / `Blueprints` 直接把它注入 SQL，在 PG 上会报 `operator does not exist: integer = boolean`。

---

## 3. 功能调整（Behavior）

1. **报表采集不再写入任务 id 列表**：`runTicketReport()` 不再产生 `tickets` 字段，`addReport()` 不再写入该列，`getFullReport()` 不再做无意义的 `SUM(CAST(tickets ...))`。
2. **`zp_stats.tickets` 物理列保留但不再读写**：老库无需迁移、不做 DROP COLUMN，`dbVersion` 不变；`Reports\Models\Reports` 也移除了 `$tickets` 属性。
3. **空状态组语义修正**：`getStatusListGroupedByType()` 对空组返回可移植的 `IN (NULL)`；`parseStatusGroups()` 能将其（以及 `IN()`）解析为 `[]`；报表侧用 `1 = 0` 明确表达“匹配零行”，不再回退成 `status IN (0)`。
4. **删除死代码**：`Install\Repositories\Install::sqlPrep()`（从未被调用，内含过时的 MySQL 原生建表 DDL，包括 `tickets TEXT`）整体删除。
5. **可移植性小清理**：报表中的 `CAST(... AS DECIMAL(10,2))` 统一改走 `DatabaseHelper::castAs(..., 'decimal')`；日期别名 `AS date` 改用 `wrapColumn('date')`。

**对用户可见的变化**：PostgreSQL 下项目报表页与每日报表采集恢复正常；MySQL 下报表数值不变（`sum_todos` 等真实指标来源未变）；JSON-RPC `reports.getFullReport` 返回中不再含 `tickets` 键（无实际消费者）。

---

## 4. 业务描述

- **报表是项目健康度的入口**：sprint burndown、backlog 燃尽、任务状态历史都依赖每日采集写入 `zp_stats`。此前 PG 部署下采集全线失败，报表长期为空/断档。
- **数据正确性**：修复前，自定义状态导致某状态组为空时，统计会把它错误地计入 `status = 0`（“已完成”）；修复后空组正确计为 0，避免报表数字被污染。
- **风险控制**：修复不影响既有报表历史数据的口径，只停止写入一个从未被消费的列。
- **范围**：本次仅处理 PostgreSQL 报表采集 bug（ideas 第 4 条）；ideas 第 1/2/3/5 条（移动端、跨项目视图插件、艾森豪威尔矩阵）不在本次范围。

---

## 5. 技术描述

### 5.1 改动文件

| 文件 | 改动 |
|---|---|
| `app/Domain/Reports/Repositories/Reports.php` | 删除 `stringAggregate`、`... AS tickets` 选择、`addReport` 的 `'tickets'` 写入、`getFullReport` 的 `tickets` 聚合；新增 `$statusIn()` 闭包（空组 → `1 = 0`，否则 `status IN (...)`）；`DECIMAL`/别名改走 `DatabaseHelper` |
| `app/Domain/Reports/Models/Reports.php` | 移除 `public $tickets;` |
| `app/Domain/Tickets/Repositories/Tickets.php` | 空状态组由 `IN(FALSE)` 改为 `IN (NULL)`（4 处） |
| `app/Core/Db/DatabaseHelper.php` | `parseStatusGroups()` 正则兼容 `IN (NULL)` / `IN()` 并返回 `[]` |
| `app/Domain/Install/Repositories/Install.php` | 删除死方法 `sqlPrep()`（556 行） |
| `tests/Unit/app/Core/Db/DatabaseHelperTest.php` | 新增：数字分组、空组（`IN (NULL)`/`IN()`）、无法识别片段 → `[]` |

### 5.2 关键技术点

- **`x IN (NULL)` 的可移植性**：在 MySQL / PostgreSQL / MS SQL Server 上，`x IN (NULL)` 均返回 `NULL`（视为不匹配），且是合法的 `IN` 语法，可直接拼在列名后用于 `SUM(CASE WHEN ...)` 与原生注入点。避免了 `IN(FALSE)`（MySQL 专有）与 `1=0`（需要替换整个谓词）各自的局限。
- **兼容两类历史 schema**：因为不再读写 `tickets`，无论老库该列是 `TEXT` 还是新装库是 `integer`，都不再触发类型转换错误。
- **无迁移、无版本号变更**：遵循“保留物理列”的决策（方案 B'），`$dbUpdates` / `dbVersion` 均未改动。
- **权限/鉴权不受影响**：报表服务层的 `#[RequiresPermission(reports.view, ...)]` 与仓库层项目/迭代作用域未改。

### 5.3 验证

**静态与单元（Docker / MySQL 8.4）**
- PHPStan：`No errors`（970 文件）。
- 单元测试：`921 tests, 2284 assertions, 8 skipped, 0 failures`；新增 `DatabaseHelperTest` 3 tests / 8 assertions 通过。
- Pint：本次改动文件相对 `HEAD` 基线**无新增**风格问题（全仓 `line_ending` 告警来自 `core.autocrlf=true` 的 CRLF 环境）。

**PostgreSQL 端到端（真实 `postgres:16-alpine` 容器）**
- 用 Leantime 自带的 `SchemaBuilder::createAllTables()` 在 PG 建全套表，插入测试 ticket，跑完整采集流程。
- **修复前（HEAD）**：复现 `invalid input syntax for type integer: "1,2,3"`（`addReport` 处）。
- **修复后**：
  ```
  tickets key present=no
  runTicketReport OK  sum_todos=3 open=1 prog=1 closed=1
  addReport OK
  zp_stats row: sum_todos=3 open=1 prog=1 closed=1
  getFullReport rows=1
  getRealtimeReport=array
  statusGroups: {"DONE":"IN(0,-1)","INPROGRESS":"IN (NULL)","NEW":"IN(3)","ALLOPEN":"IN(3)"}
  runTicketReport with empty group OK
  ```

### 5.4 遗留与后续

- `zp_stats.tickets` 物理列仍在（新装由 `SchemaBuilder` 仍会创建 `integer` 列，但因不再读写而无影响）。若后续希望彻底清理，可另起一个 `update_sql_*` 迁移 DROP 该列并同步 `SchemaBuilder`。
- `IN(FALSE)` 仅有 `getStatusListGroupedByType()` 一处产生，已全部改为 `IN (NULL)`；`Goalcanvas`/`Blueprints` 的注入点因此同时获得 PG 兼容性。
- 本次未改动 `Reports` 的采集口径与图表逻辑。
