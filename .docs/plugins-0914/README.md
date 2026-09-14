# CostTracking 插件技术文档

> 生成日期：2026-09-14
> 插件版本：1.0.0 ｜ 适用基线：leantime-forked（upstream 3.10.0，commit `056835f1c` 之后）
> 插件目录：[app/Plugins/CostTracking/](../../app/Plugins/CostTracking)
> 前置阅读：[.docs/initial-0910/07-事件与插件.md](../initial-0910/07-事件与插件.md)（事件/过滤器/插件机制基线）

## 文档索引

| 文档 | 内容 |
|---|---|
| [01-需求与产品决策.md](01-需求与产品决策.md) | 业务背景、功能需求、已确认的 6 项产品决策、范围边界 |
| [02-技术方案.md](02-技术方案.md) | 总体架构、数据表设计、事件/过滤器挂载地图、两种汇总口径、DataTables 列冲突的解决 |
| [03-改动清单.md](03-改动清单.md) | 对核心模板的 3 处修改、`.gitmodules` 子模块解除过程、插件新增文件全清单 |
| [04-安装与验证手册.md](04-安装与验证手册.md) | 安装/启用/卸载命令、端到端验证用例、常见问题排查 |
| [05-持续开发指南.md](05-持续开发指南.md) | 目录约定、二次扩展操作手册、可做/禁止事项、上游合并注意、演进路线 |

## 一分钟速览

- **做什么**：给任务增加「预算成本 / 实际成本」两个金额字段，在项目层实时汇总并与项目已有预算 `zp_projects.dollarBudget` 对比。
- **怎么做**：100% 走 Leantime 官方插件机制（`PluginInterface` 生命周期 + `register.php` 事件/过滤器挂载 + Blade 事件钩子），**不修改任何核心表结构、不新增核心迁移、不改 `dbVersion`**。
- **数据在哪**：插件自有表 `zp_ticket_costs`，以 `ticketId` 为主键 1:1 挂接 `zp_tickets`，插件卸载即删表。
- **核心改动多少**：5 个核心 Blade 模板各加 1 行 `@dispatchEvent`（看板/Htmx 卡片、项目卡片、任务全表表头、简易列表标题格）；另为让插件能随 fork 提交，删除了 `.gitmodules` 中 `app/Plugins` 的官方子模块绑定。
- **展示位置**：任务新建/编辑表单、任务全表（两列+合计）、简易任务列表（任务名后的行内角标）、看板卡片角标、项目卡片总成本、项目设置页「成本」Tab（含顶层任务/子任务口径切换、预算使用率）。
- **关键技术点**：任务全表是 DataTables 且核心 JS 写死列索引，插件采用「表头行内追加 `<th>` + 行尾追加 `<td>`」方案，保持表头/正文各 16 格一一对齐，避免破坏核心排序/隐藏/工时合计。

## 交付物地图

```text
app/Plugins/CostTracking/
├── composer.json                 # 插件元数据（安装器直接读取）
├── register.php                  # 事件/过滤器装配（插件业务入口）
├── Services/
│   └── CostTracking.php          # 生命周期：install 建表 / uninstall 删表
├── Repositories/
│   └── Costs.php                 # 数据访问：upsert/删除/批量取数/项目汇总
├── Language/
│   ├── en-US.ini                 # 英文（平铺键，无段落头）
│   └── zh-CN.ini                 # 中文
└── Templates/                    # Blade 视图命名空间 costtracking::
    ├── formFields.blade.php      # 任务表单两个输入框
    ├── tableHeaderExtra.blade.php   # 全表：追加在核心表头行末尾的两个成本 <th>
    ├── tableCells.blade.php      # 全表：行尾两个金额 <td>
    ├── tableTotals.blade.php     # 全表/列表：表格下方分组合计
    ├── inlineBadge.blade.php     # 简易列表：任务名后的行内角标
    ├── kanbanBadge.blade.php     # 看板卡片角标
    ├── projectTabLink.blade.php  # 项目设置页 Tab 标题
    ├── projectTab.blade.php      # 项目设置页成本分析面板
    └── projectCardCost.blade.php # 项目集卡片总成本
```
