# Leantime Fork（leantime-forked）项目分析文档

> 生成日期：2026-09-10 ｜ 基于 git commit `056835f1c`（upstream 发布标记 3.10.0，但代码内 `AppSettings::$appVersion` = 3.9.8，2026-09-07）
> 用途：二开前的代码理解基线文档。本目录已被 `.gitignore` 忽略，不会进入版本库。
>
> 修订 2026-09-14：以下内容为上述提交时的**基线快照**，历史描述保留。但有两处已变化——① `app/Plugins` 已不再是官方 git submodule，现为普通目录，其中 `app/Plugins/CostTracking` 是指向独立仓库 `mindrose/costtracking` 的 submodule（clone 需 `--recurse-submodules`）；② 本目录实际已被纳入版本库跟踪（原"已被 .gitignore 忽略"的描述已过期）。
>
> 修订 2026-09-18：报表 bug 修复（三批）。① 采集失败（PG）：`zp_stats.tickets` 死字段停止读写（物理列保留）、空状态组改用可移植的 `IN (NULL)`、删除死方法 `Install::sqlPrep()`；② 报表页 500（PG，追加）：上游 `Reports/Repositories/ReportEngine.php` 的 `selectRaw()` 裸写混合大小写列名（`moduleId`/`projectId`）改为经 `DatabaseHelper::wrapColumn()` 引用；③ 报表页 500（追加，与数据库无关）：报表模板改用无 intl 依赖的 `format_number()`（`app/helpers.php`）替换 `Illuminate\Support\Number::format()`（镜像未装 `ext-intl`）。本目录 `04-数据层.md`/`10-领域模块地图.md` 已同步更新，`12-二开指南.md` 增补该坑；完整说明见 `.docs/bug-fix-0918/`（§1–§5 / §6 / §7）。

## 文档索引

| 文档 | 内容 |
|---|---|
| [01-项目概览.md](01-项目概览.md) | 项目是什么、版本、许可证、Fork 状态、功能清单 |
| [02-架构总览.md](02-架构总览.md) | 技术栈、目录结构、启动流程、请求生命周期、分层约定 |
| [03-配置系统.md](03-配置系统.md) | `.env` / `LEAN_*` 变量、配置优先级、laravelConfig |
| [04-数据层.md](04-数据层.md) | 全部 35 张 `zp_` 表、Repository/Model 模式、建表与升级机制 |
| [05-路由与控制器.md](05-路由与控制器.md) | 双路由系统（Laravel routes + 旧 Frontcontroller）、控制器模式、HTMX 控制器 |
| [06-模板与前端.md](06-模板与前端.md) | Blade 模板、组件体系、JS 架构、htmx 模式、主题与深色模式 |
| [07-事件与插件.md](07-事件与插件.md) | 事件/过滤器系统、插件系统（生命周期/市场/注册 API） |
| [08-认证与权限.md](08-认证与权限.md) | 登录、会话、角色、`#[RequiresPermission]`、2FA、LDAP、OIDC、密码重置 |
| [09-API与MCP.md](09-API与MCP.md) | JSON-RPC 2.0 API、API Key、Bearer Token、MCP AI 工具 |
| [10-领域模块地图.md](10-领域模块地图.md) | 44 个领域模块逐个说明 + 核心模块详解 |
| [11-构建测试与部署.md](11-构建测试与部署.md) | 构建、静态检查、测试、CI、Docker/Helm 部署 |
| [12-二开指南.md](12-二开指南.md) | 常见二开场景操作手册 + 代码约定 + 易踩的坑 |
| [CLAUDE.zh-CN.md](CLAUDE.zh-CN.md) | 仓库根目录 `CLAUDE.md` 的中文全文翻译（面向 AI 编码助手的仓库开发指南：架构说明 + 编码规范） |

## 一分钟速览

- **这是什么**：Leantime —— 面向"非项目经理"的开源项目管理软件（"像 Trello 一样简单，像 Jira 一样强大"），AGPL-3.0。
- **技术栈**：PHP 8.2+ / Laravel 11（深度定制）/ MySQL 8 或 MariaDB 10.6+（支持 PostgreSQL）/ jQuery + htmx + Tailwind 前端 / Laravel Mix 构建。
- **本 Fork 状态**：干净 fork（`git status` 无本地改动），仅 `master` 分支，submodule `app/Plugins` 未初始化（OSS 版本不需要它）。
- **架构核心**：领域驱动（44 个 Domain 模块），每域 = Controllers + Services + Repositories + Models + Templates（Blade）。
- **三大正在演进的特征**：① 模板已 100% 迁移到 Blade（0 个 `.tpl.php`）；② HTMX 局部刷新正在逐步替代 jQuery AJAX；③ 新增 MCP（Model Context Protocol）AI 工具层（55 个工具）与 JSON-RPC 2.0 API。
- **最常用的二开入口**：新增一个服务方法（`@api` 注解）→ 自动暴露为 JSON-RPC API；新增一个 MCP 工具类 → 自动被 AI 使用；新增事件监听器 → 写进领域 `register.php`。
