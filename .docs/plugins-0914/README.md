# CostTracking 插件技术文档

> 生成日期：2026-09-14
> 插件版本：1.0.0 ｜ 适用基线：leantime-forked（upstream 3.10.0，commit `056835f1c` 之后）
> 插件目录：[app/Plugins/CostTracking/](../../app/Plugins/CostTracking)
> 前置阅读：[.docs/initial-0910/07-事件与插件.md](../initial-0910/07-事件与插件.md)（事件/过滤器/插件机制基线）
>
> 修订 2026-09-14：插件已迁至独立仓库 `mindrose/costtracking`（<https://github.com/GavinCnod/mr-leantime-costtracking>，含 README/LICENSE/patches），并在 fork 的 `app/Plugins/CostTracking` 以 **git submodule** 挂回；本节下方"文档索引/一分钟速览/交付物地图"及元数据章节已同步。clone 本 fork 需带 `--recurse-submodules`。

## 文档索引

| 文档 | 内容 |
|---|---|
| [01-需求与产品决策.md](01-需求与产品决策.md) | 业务背景、功能需求、已确认的 6 项产品决策、范围边界 |
| [02-技术方案.md](02-技术方案.md) | 总体架构、数据表设计、事件/过滤器挂载地图、两种汇总口径、DataTables 列冲突的解决 |
| [03-改动清单.md](03-改动清单.md) | 对核心模板的 5 处钩子修改、`.gitmodules` 两次变更（解除官方子模块 → 插件独立成库并以子模块挂回）、插件文件清单 |
| [04-安装与验证手册.md](04-安装与验证手册.md) | 安装/启用/卸载命令、端到端验证用例、常见问题排查 |
| [05-持续开发指南.md](05-持续开发指南.md) | 目录约定、二次扩展操作手册、可做/禁止事项、上游合并注意、演进路线 |

## 一分钟速览

- **做什么**：给任务增加「预算成本 / 实际成本」两个金额字段，在项目层实时汇总并与项目已有预算 `zp_projects.dollarBudget` 对比。
- **怎么做**：100% 走 Leantime 官方插件机制（`PluginInterface` 生命周期 + `register.php` 事件/过滤器挂载 + Blade 事件钩子），**不修改任何核心表结构、不新增核心迁移、不改 `dbVersion`**。
- **数据在哪**：插件自有表 `zp_ticket_costs`，以 `ticketId` 为主键 1:1 挂接 `zp_tickets`，插件卸载即删表。
- **核心改动多少**：5 个核心 Blade 模板各加 1 行 `@dispatchEvent`（看板/Htmx 卡片、项目卡片、任务全表表头、简易列表标题格）；另将插件拆为独立仓库 `mindrose/costtracking`，并在 `app/Plugins/CostTracking` 以 git submodule 挂回（clone 需 `--recurse-submodules`）。
- **展示位置**：任务新建/编辑表单、任务全表（两列+合计）、简易任务列表（任务名后的行内角标）、看板卡片角标、项目卡片总成本、项目设置页「成本」Tab（含顶层任务/子任务口径切换、预算使用率）。
- **关键技术点**：任务全表是 DataTables 且核心 JS 写死列索引，插件采用「表头行内追加 `<th>` + 行尾追加 `<td>`」方案，保持表头/正文各 16 格一一对齐，避免破坏核心排序/隐藏/工时合计。

## 交付物地图

```text
app/Plugins/CostTracking/
├── composer.json                 # 插件元数据（安装器直接读取）
├── LICENSE                       # AGPL-3.0-only 许可证正文 + 版权声明
├── register.php                  # 事件/过滤器装配（插件业务入口）
├── Command/
│   └── SyncMetadataCommand.php   # plugin:costtracking:sync-metadata 元数据同步命令
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

## 元数据、版权与发布信息（2026-09-14 变更）

将插件对外身份从官方模板值改为作者自有信息，并补充开源许可证与元数据同步手段。

### 1. `composer.json` 字段变更

| 字段 | 旧值 | 新值 |
|---|---|---|
| `name` | `leantime/costtracking` | `mindrose/costtracking` |
| `description` | 英文单语 | 中英双语（英文 + 中文，同一字符串） |
| `version` | `1.0.0` | `1.0.0`（不变） |
| `license` | 无 | `AGPL-3.0-only`（新增） |
| `homepage` | `https://github.com/leantime/leantime` | `https://github.com/GavinCnod/mr-leantime-costtracking` |
| `authors[0].name` | `Internal` | `Gavin Chen` |
| `authors[0].email` | `noreply@example.com` | `gavinchen@mindrose.xyz` |

变更后 `composer.json` 全文：

```json
{
    "name": "mindrose/costtracking",
    "description": "Adds planned/actual cost fields to tickets and project-level cost rollups against the project budget. 为任务增加计划成本与实际成本字段，并按项目预算汇总项目级成本。",
    "version": "1.0.0",
    "type": "leantime-plugin",
    "license": "AGPL-3.0-only",
    "homepage": "https://github.com/GavinCnod/mr-leantime-costtracking",
    "authors": [
        {
            "name": "Gavin Chen",
            "email": "gavinchen@mindrose.xyz"
        }
    ],
    "require": {}
}
```

### 2. `LICENSE`

新增 `app/Plugins/CostTracking/LICENSE`：

- 正文为 **GNU Affero General Public License v3** 标准全文（与 Leantime 本体一致，插件作为衍生作品继承 AGPL）。
- 文件末尾附版权声明：`Copyright (C) 2026 Gavin Chen <gavinchen@mindrose.xyz>` + 仓库地址。
- 同时在 `composer.json` 声明 `"license": "AGPL-3.0-only"`，供插件市场/工具链识别。

### 3. 元数据同步机制与命令

**背景**：Leantime 只在安装/发现插件时（`Plugins::createPluginFromComposer()`，`app/Domain/Plugins/Services/Plugins.php:258`）读取一次 `composer.json`，之后元数据落库到 `zp_plugins`，列表/详情页一律**读库**（`Repositories/Plugins.php:24-77`）。因此**改 `composer.json` 不会自动更新已部署实例**显示的作者/邮箱/描述/版本。

**解决**：随插件新增命令 `plugin:costtracking:sync-metadata`，读取插件自身 `composer.json` 并与 `zp_plugins` 行对比更新，随后清理 `plugins.enabledPlugins` / `plugins.marketplacePluginsFlat` 缓存。**不触碰 `zp_ticket_costs`**，不会丢成本数据。

命令文件 `app/Plugins/CostTracking/Command/SyncMetadataCommand.php` 之所以能生效，是因为 `ConsoleKernel::discoverCommands()`（`app/Core/Console/ConsoleKernel.php:125-143`）会自动扫描 `app/Plugins/**/Command/`——命令随插件发布，无需改动核心。

```bash
# 预览将发生的变更（不写库）
php bin/leantime plugin:costtracking:sync-metadata --dry-run

# 实际写入
php bin/leantime plugin:costtracking:sync-metadata
```

- 仅同步 `name / description / version / homepage / authors` 五列；以 `foldername`（默认 `CostTracking`）定位记录。
- 插件命令仅在插件**启用**时被加载（`getEnabledPluginPaths()`）。
- 无差异时输出 "already up to date" 并以成功码退出。

### 4. 无 CLI 时的等价 SQL 兜底

```sql
UPDATE zp_plugins
SET name        = 'mindrose/costtracking',
    description = 'Adds planned/actual cost fields to tickets and project-level cost rollups against the project budget. 为任务增加计划成本与实际成本字段，并按项目预算汇总项目级成本。',
    version     = '1.0.0',
    homepage    = 'https://github.com/GavinCnod/mr-leantime-costtracking',
    authors     = '[{"name":"Gavin Chen","email":"gavinchen@mindrose.xyz"}]'
WHERE foldername = 'CostTracking';
-- 之后清理 installation 缓存（plugins.enabledPlugins）以让 UI 立即生效
```

### 5. 对已部署实例的影响（部署/升级注意）

- **运行时身份是文件夹名，不是 `composer.json.name`**：`Plugins.php:147`、`238`、`412-419` 与 `register.php:131 new Registration('CostTracking')` 均按目录名 `CostTracking` 定位。只改 `composer.json.name` 并覆盖部署，插件仍正常加载、启用状态保留、不会被当作新插件。
- **不要改**：目录名 `CostTracking`、命名空间 `Leantime\Plugins\CostTracking`、`new Registration('CostTracking')`。任一改动会使插件被识别为新插件、旧记录变孤儿。
- **元数据需手动同步**：升级后运行上述命令或 SQL，否则列表仍显示旧作者/邮箱/描述/版本。
- **卸载会删数据**：`Services/CostTracking.php::uninstall()` 执行 `Schema::dropIfExists('zp_ticket_costs')`。请勿用"卸载再重装"来刷新元数据，改用同步命令，并始终先备份该表。
- **市场授权标识**：`InstalledPlugin::getIdentifier()`（`Models/InstalledPlugin.php:160-174`）由 `name` 推导（`mindrose/costtracking` → `mindrose_costtracking`）。文件夹插件不走授权；若将来改为 phar/市场发行，标识变化会导致旧授权对不上。
