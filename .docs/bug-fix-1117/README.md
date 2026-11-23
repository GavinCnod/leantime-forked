# bug-fix-1117 — 每日报表日批在 PostgreSQL 上的两个失败（zp_stats 点数 / goal_history 标识符）

> 日期：2026-11-17
> 影响模块：Reports（日批采集 + 报表仓储）、Goalcanvas（目标值快照）、Install（仅上游 PR）
> 相关文档：[bug-fix-0918](../bug-fix-0918/README.md)（同类 PG 兼容问题：`zp_stats.tickets` 死字段 + 采集中断）
>
> 本文记录 2026-11-06 起持续约 11 天的生产事故：每天 00:00（Asia/Shanghai）`reports:dailyIngestion`
> 与 `reports:goalValueSnapshot` 各报一个 `SQLSTATE` 错误。两个错误互相独立，都由 **PostgreSQL 的
> 严格类型 / 大小写折叠**暴露了上游代码里 MySQL 兜得住的问题。

---

## 1. 现象

Railway 生产环境（`leantime-project-mgt` / production / `leantime-forked`，部署 `8e63b8b5`，代码为
`e7db23b2f`）应用日志每天 00:00:02 出现：

```
[2026-11-15 16:00:02] production.ERROR: SQLSTATE[22P02]: Invalid text representation:
  7 ERROR: invalid input syntax for type integer: "0.5"
  insert into "zp_stats" (... "sum_points", "sum_points_done", ...) values (..., $11, ...)
  CONTEXT: unnamed portal parameter $11 = '0.5'   (projectId=4, sprintId=-1)

[2026-11-16 00:00:02] production.ERROR: PDOException: SQLSTATE[42703]: Undefined column:
  7 ERROR: column "itemid" does not exist
  HINT: Perhaps you meant to reference the column "zp_goal_history.itemId".
  STATEMENT: select "zp_goal_history"."itemId", ... from "zp_goal_history"
             inner join (select itemId, MAX(dateRecorded) as maxDate ...) as "latest" ...
```

调度器同秒仍打印 `Running [reports:dailyIngestion] ... DONE`，**DONE 只代表任务结束，不代表业务成功**。
`reports:goalValueSnapshot` 有 try/catch，只记日志；`reports:dailyIngestion` 没有，异常会中断整轮循环。

> 用户侧影响：自 11-06 起每天缺失 `zp_stats` 行；失败项目之后的项目也被跳过。历史缺口无法回填
> （只能按当天工单状态重算，过去状态已丢）。

---

## 2. 根因

### 2.1 `zp_stats.sum_points*` 是 INT，但点数合计可以是 0.5

- `zp_tickets.storypoints` 是 **float**（`Install/Services/SchemaBuilder.php`）。
- 产品自带的 effort 选项包含 `0.5 => '< 2min'`（`Tickets/Repositories/Tickets.php`），因此
  `SUM(storypoints)` 经常是小数。
- `zp_stats` 的四个列 `sum_points` / `sum_points_done` / `sum_points_progress` / `sum_points_open`
  声明为 **integer**。
- MySQL 插入时静默舍入；PostgreSQL 直接 22P02。异常从 `Reports::addReport()` 抛出。
- `cronDailyIngestion()` 是一个 foreach 且无逐项目 try/catch，**第一个失败项目会阻断其后的所有项目**。

### 2.2 `snapshotGoalValues()` 的原始 SQL 未给 camelCase 标识符加引号

`Goalcanvas/Repositories/Goalcanvas.php`：

```php
->selectRaw('itemId, MAX(dateRecorded) as maxDate')
```

PostgreSQL 把未加引号的标识符折叠为小写（`itemid` / `maxdate`），而外层 `select()`、`groupBy()`
和 `joinSub` 条件都被 Query Builder 加了双引号（`"itemId"` / `"maxDate"`），于是子查询输出列与
join 引用对不上 → 42703。同仓库其他方法（如 `percentDone`、`ReportEngine::getLatestStatusUpdateForProjects`）
已经用 `DatabaseHelper::wrapColumn()` 正确加引号，此处为漏改。

---

## 3. 修复（dev-self-hosted，`a0368362b` 起）

| 位置 | 修复 |
|---|---|
| `Reports/Repositories/Reports.php` | `addReport()` 对四个点数合计做 `(int) round((float) $v)`，`null` 保持 `null`（`pointTotal()` 私有方法） |
| `Goalcanvas/Repositories/Goalcanvas.php` | 子查询改用 `select(['itemId'])` + `wrapColumn('dateRecorded')` / `wrapColumn('maxDate')` |
| `Reports/Services/Reports.php` | `cronDailyIngestion()` 逐项目 `try/catch (\Throwable)` + `Log::error` |
| `tests/Unit/...` | 三个回归测试：SQL 加引号（PostgreSQL grammar）、项目隔离、点数取整 |

### 为什么 fork 侧用「取整」而不是加迁移？

fork 当前 DB 版本 **3.5.33**，而合并目标（上游 3.15.x）已经占用 `30534`（zp_queue.module）与
`30535`（MCP OAuth 表）。若在 fork 上新增 `30536` 并把 `db-version` 推到 3.5.36，下次合并 3.15
时 `updateDB()` 会因 `currentDBVersion(30536) > 30534/30535` **静默跳过**这两个上游迁移，造成
`zp_queue` 缺列、OAuth 表缺失，比当前事故更隐蔽。

因此本轮：

- fork：`addReport()` 取整止血（与 MySQL 既有行为一致），不加迁移、不动 `db-version`；
- 上游：同一根因用「列改 float + 迁移 30536」的治本方案提 PR。

---

## 4. 上游 PR

- [x] PR ① [#4094](https://github.com/Leantime/leantime/pull/4094) `fix/zp-stats-float-columns`：SchemaBuilder 四列 float + `update_sql_30536` + `dbVersion 3.5.36` + 测试
- [x] PR ② [#4095](https://github.com/Leantime/leantime/pull/4095) `fix/goalcanvas-snapshot-postgres-identifiers`：`wrapColumn` 加引号 + SQL 回归测试
- [x] PR ③ [#4096](https://github.com/Leantime/leantime/pull/4096) `fix/reports-daily-ingestion-isolation`：逐项目 try/catch + 测试

---

## 5. 验证与后续

部署后：

1. Railway 自动部署 → 容器内 `php bin/leantime schedule:test --name=reports:dailyIngestion`
   与 `--name=reports:goalValueSnapshot` 手动触发。
2. 日志确认无 `SQLSTATE[22P02]` / `[42703]`。
3. psql：
   ```sql
   select projectId, date, sum_points, sum_points_done from zp_stats order by date desc limit 20;
   select count(*) from zp_goal_history where "userId" is null;
   ```
4. 次日 00:00（Asia/Shanghai）复检一次。

后续（合并上游新版本时）：

- [x] 上游 float 迁移随版本进来后，删除 `Reports::addReport()` 的 `pointTotal()` 取整（PR ① 合并后即可）。
      **已完成于 3.17.0 升级**：迁移在 3.17 中编号为 **30537**（PR ① 原计划 30536，上游合入时顺延），
      升级分支已删除 `pointTotal()` 与 `ReportsPointTotalsTest`。
- [x] 合并带 `30534/30535` 的上游版本时，确认这两个迁移正常执行（`db-version` 不得先被 fork 推高）。
      3.17.0 升级在 MySQL/PostgreSQL 上均验证 3.5.33 → 3.5.37。
- [x] 把本轮两个 PG 兼容点登记进下一次 `.docs/upgrade-*` 的冲突预测。
      已登记于 `.docs/upgrade-3.17/README.md`。
