# CLAUDE.md

本文件为 Claude Code (claude.ai/code) 在本仓库中处理代码时提供指导。

## 关于 Leantime

Leantime 是一个开源项目管理系统，为非项目经理人员设计。它把战略、规划和执行结合在一个易于使用的界面中。该应用使用 PHP (Laravel)、MySQL 和一个 JS 前端构建。当前版本：3.6.2。

## 当前状态与进行中的迁移

这些是正在进行的架构工作。它们都不需要被主动修复 —— 它们提供了理解代码库为何存在混合模式的背景。

### 1. HTMX 迁移（进行中）
**目标**：用 HTMX partial 更新取代 jQuery AJAX 和整页重新加载。
**状态**：42 个领域中有 8 个拥有专门的 `Hxcontrollers/`，总共有 19 个 HxControllers。约 57 个 Blade 模板和约 14 个 tpl.php 文件使用了 HTMX 属性。
**拥有 HxControllers 的领域**：Tickets、Projects、Timesheets、Widgets、Menu、Notifications、Plugins、Help。
**模式**：主页面控制器加载最少的数据 + 骨架；内容通过 HTMX partial 加载。新的异步工作应使用 HTMX，而不是 jQuery AJAX。

### 2. 模板迁移（进行中）
**目标**：从遗留（legacy）的 `.tpl.php` 迁移到 Laravel Blade `.blade.php`。
**状态**：约 198 个遗留（legacy）的 `.tpl.php` 文件，对比领域中的约 91 个 `.blade.php` 文件 + 共享 Views 中的约 33 个。约 30% 已迁移。
- **完全现代化（仅 Blade）**：Dashboard、Gamecenter、Goalcanvas、Menu、Notifications、Plugins、Widgets
- **部分现代化（混合）**：Auth、Calendar、Comments、Help、Projects、Tickets、Timesheets、Users
- **完全遗留（仅 TPL）**：Blueprints/Canvas、Clients、Files、Ideas、Wiki、Sprints、Setting 等。
**模式**：主页面视图倾向于保留 `.tpl.php`，而新的 partial 和 HTMX fragment 使用 `.blade.php`。在改动模板时，新工作优先使用 Blade。

### 3. 服务层 / JSON-RPC
**当前状态**：服务既是业务逻辑层，也是 JSON-RPC API 接口面。服务类上的任何 public 方法都可以通过 `leantime.rpc.{domain}.{service}.{method}` 调用。`@api` 注解标记了预期作为 API 的方法，但在运行时并不强制执行。

### 4. 插件系统（私有子模块）
**当前状态**：`app/Plugins/` 是一个 git 子模块，指向存放商业插件的私有仓库。在 OSS 仓库中该目录基本上是空的。三种插件类型：system（env 配置，启动时加载）、custom（文件夹）、marketplace（phar + 许可证密钥）。

### 5. 事件系统（基于字符串，正转向基于类）
**当前状态**：100% 的事件名都是基于字符串的，由类命名空间动态生成（例如 `leantime.domain.tickets.services.tickets.updateTicket.ticket_updated`）。只存在一个基于类的事件：`Files/Events/FileUploaded.php`（样板代码）。`DispatchesEvents` trait 被混入几乎每一个核心类。今后的工作应在实际可行处优先采用基于类的事件。

### 6. JavaScript 架构（已过时，需要组件化）
**当前状态**：所有 JS 都使用全局 `leantime` 命名空间和 IIFE 模块模式。每个页面加载约 7-8MB 的 JS（没有代码分割）。仍在使用 jQuery 3.7.1 + Bootstrap 2.x（非常老旧）。仅 TinyMCE 5.10.9 就占 3.6MB。同时包含了 Moment.js 和 Luxon（冗余）。曾计划实现基于文件的按领域加载系统，但在 `webpack.mix.js` 中仍处于被注释掉的状态。最终需要带代码分割的组件化架构。

## 开发环境搭建

### 要求
- PHP 8.2+
- MySQL 8.0+ 或 MariaDB 10.6+
- 必需的 PHP 扩展：BC Math、Ctype、cURL、DOM、Exif、Fileinfo、Filter、GD、Hash、LDAP、Multibyte String、MySQL、OPcache、OpenSSL、PCNTL、PCRE、PDO、Phar、Session、Tokenizer、Zip、SimpleXML

### 使用 Docker 进行本地开发（推荐）
```bash
# 首先构建开发环境
make clean build

# 启动开发服务器
make run-dev
```

这会启动一个运行在 8090 端口上的开发服务器，包含：
- Leantime 应用：http://localhost:8090
- MailDev（用于邮件测试）：http://localhost:8081
- phpMyAdmin：http://localhost:8082（认证：leantime/leantime）
- S3Ninja（用于 S3 测试）：http://localhost:8083

### 手动本地开发
```bash
# 安装依赖
make install-deps-dev

# 为开发环境构建
make build-dev

# 将你的 web 服务器指向 public/ 目录
# 创建 MySQL 数据库
# 将 config/.env.sample 复制为 config/.env 并配置你的数据库
# 访问 <localdomain>/install
```

## 常用命令

### 构建命令
```bash
make install-deps-dev    # 安装开发依赖
make install-deps        # 安装生产依赖
make build-dev           # 为开发环境构建（带 source map）
make build               # 为生产环境构建
make clear-cache         # 清除缓存
make package             # 打包用于发布
npx mix                  # 使用 webpack 构建 js/css（在根目录或某个插件内运行）
```

### 测试命令
```bash
make phpstan             # 运行静态分析（等级 0）
make test-code-style     # 运行代码风格检查（Laravel Pint）
make fix-code-style      # 修复代码风格问题（Laravel Pint）
make unit-test           # 运行单元测试（Docker）
make acceptance-test     # 运行验收测试（Docker）

# 运行特定的验收测试分组（在 Docker 内）：
docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec leantime-dev php vendor/bin/codecept run -g api --steps
docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec leantime-dev php vendor/bin/codecept run -g timesheet --steps
# 可用分组：api、timesheet、login、ticket、user
```

### CLI 命令
Leantime 扩展了标准的 Laravel artisan 命令，并包含若干位于 `app/Command` 目录中、可通过以下方式执行的命令行工具：
```bash
php bin/leantime [command]
```

常用命令：
- `system:update` - 更新 Leantime 安装
- `plugin:enable [pluginname]` - 启用指定插件
- `plugin:disable [pluginname]` - 禁用指定插件
- `plugin:install [pluginname]` - 从插件市场安装插件
- `plugin:list` - 列出所有已安装的插件
- `user:add` - 添加新用户
- `setting:save [key] [value]` - 保存系统设置

## 代码架构

### 目录结构

- `app/` - 主应用代码
  - `Core/` - 框架核心组件
    - `Application/` - 应用服务提供者
    - `Auth/` - 认证服务（guard、Sanctum 令牌）
    - `Bootstrap/` - 自定义 bootstrap（LoadConfig）
    - `Configuration/` - 应用配置（Environment、DefaultConfig、AppSettings、laravelConfig）
    - `Console/` - 控制台内核
    - `Controller/` - 基础控制器（Controller、HtmxController、Frontcontroller、Composer）
    - `Db/` - 数据库抽象（Db、Repository、DbColumn、DatabaseHelper）
    - `Domains/` - 基础领域接口（DomainService、DomainRepository、DomainModel）
    - `Events/` - 事件系统（EventDispatcher、DispatchesEvents trait）
    - `Exceptions/` - 异常处理
    - `Files/` - 文件管理
    - `Http/` - HTTP 处理（HttpKernel、IncomingRequest、ApiRequest、HtmxRequest）
    - `Middleware/` - 请求中间件（16 个中间件）
    - `Plugins/` - 插件基础设施
    - `Routing/` - 路由加载
    - `Support/` - 辅助工具（CarbonMacros、DateTimeHelper、Format、Cast）
    - `UI/` - 模板处理（Template、Theme、ViewsServiceProvider）
  - `Domain/` - 应用领域（约 42 个模块，在画布变体合并进 Blueprints 之后），按功能组织
    - 每个领域通常包含：
      - `Controllers/` - HTTP 端点
      - `Hxcontrollers/` - HTMX 专用控制器
      - `Repositories/` - 数据访问
      - `Services/` - 业务逻辑
      - `Models/` - 数据结构
      - `Templates/` - 视图模板（`.tpl.php`、`.blade.php`、`partials/`）
      - `Js/` - 领域专用 JavaScript
      - `Composers/` - 视图 composer
      - `Listeners/` - 事件监听器
      - `Htmx/` - HTMX 事件枚举
      - `Middleware/` - 领域中间件
      - `register.php` - 事件/过滤器监听器注册
  - `Views/` - 共享视图文件
    - `Templates/layouts/` - 布局骨架（app、entry、blank、error、registration）
    - `Templates/components/` - 共享 Blade 组件（`<x-global::componentName>`）
    - `Templates/sections/` - 页头、页脚、导航区段
    - `Composers/` - 共享视图 composer（App、Header、Footer、Entry、PageBottom）
  - `Plugins/` - 扩展插件（指向私有仓库的 git 子模块）
  - `Language/` - 国际化文件（基于 INI）
- `bootstrap/` - 应用引导文件
- `config/` - 配置文件（.env、.env.sample）
- `public/` - Web 根目录
  - `assets/` - 静态资源（CSS、JS、图片、字体）
  - `dist/` - 构建/编译后的资源（`npx mix` 的输出）
  - `theme/` - 主题文件（default、minimal）
- `storage/` - 日志、缓存和会话的存储
- `tests/` - 测试文件（Codeception v5.1）
  - `Acceptance/` - 验收测试（Cest 格式，WebDriver + Selenium）
  - `Unit/` - 单元测试（继承 Laravel TestCase）

### 总体架构概览
该应用构建在 Laravel 11 之上，并带有大量自定义组件。它使用插件系统实现可扩展性。
Leantime 遵循领域驱动架构：

**Core**

框架代码（Laravel）以及任何扩展类都位于 `app/Core` 文件夹中。
Core 管理所有共享功能和基础特性。

**领域（Domain）**

`app/Domain/` 中约有 42 个领域模块。每个模块都有若干层，共同代表一个领域：

1. **控制器**处理 HTTP 请求并委派给服务
2. **服务**包含业务逻辑并编排操作
3. **仓储（Repository）**访问和操作数据存储
4. **模型**表示数据结构
5. **模板**表示视图文件（Blade 和遗留 legacy PHP）
6. **监听器**包含事件监听器
7. **任务（job）**是队列任务

**Plugins**
插件是位于 `app/Plugins/` 中、可安装的领域模块。
每个插件都遵循与领域模块相同的结构，但还包含一个用于插件标识的 `composer.json` 文件。
插件可以作为文件夹管理，也可以作为预打包的 phar 文件管理。

### 领域模块参考

**核心功能领域**：Tickets、Projects、Users、Sprints、Timesheets、Calendar、Comments、Files、Wiki、Ideas、Reports、Notifications、Dashboard、Widgets、Menu、Tags、Reactions、Entityrelations、Audit、Read

**画布领域**：约 14 个独立的画布变体领域（Cpcanvas、Dbmcanvas、Leancanvas、Swotcanvas 等）已被**合并为单一的 `Blueprints` 领域**。这些变体不再作为独立的领域存在 —— 它们由 YAML 定义，并在运行时按 slug 选择。剩余的画布家族领域：
- **Blueprints** —— 合并后的画布框架，也是当前的基础。原生路由 `/blueprints/{canvasSlug}/...`（{canvasSlug} = swot/lean/goal 等）；遗留（legacy）的 `/{x}canvas/...` 路径会 301 重定向到这里。所有画布数据都存放在共享的 `zp_canvas`（画布板，`type` 列）+ `zp_canvas_items`（条目，`box` 列）表中，以 `canvasId` 为键。`Wiki` 和 `Goalcanvas` 都 `extend Blueprints`；旧的 `Canvas` 仓储 `extends BlueprintsRepository`。
- **Goalcanvas** —— 继承 Blueprints；完全使用 Blade，并有自己的服务。
- **Logicmodelcanvas** —— 较新的画布类型；仍然通过遗留（legacy）的 `Canvas` 控制器（Frontcontroller）路由。
- **Canvas** —— *旧的*基础，如今处于半遗留/已废弃状态。只有 `Logicmodelcanvas` 仍然依附于它的控制器；除此之外，它已是 Blueprints 之上一个已废弃的垫片层。新的画布工作应以 Blueprints 为目标，绝不要以旧的 `Canvas` 领域为目标。

**系统领域**：Api、Auth、Cron、CsvImport、Connector、Environment、Errors、Install、Ldap、Modulemanager、Oidc、Plugins、Queue、Setting、TwoFA

**仅后端领域**（无 UI）：Audit、Entityrelations、Ldap、Reactions、Read、Tags、Queue

**共享的画布表（IDOR 注意事项）**：`zp_canvas` 和 `zp_canvas_items` 在**每一个**画布类型之间共享，并使用同一个 id 序列。因此，仅凭 id 的仓储读取/写入可能落到*外来的*画布类型或项目上。画布家族的服务/仓储方法必须**失败即拒绝（fail closed）** —— 解析实体真实的项目（当 id 与预期的 `type`/`box` 不匹配时返回 false/null/0）并据此授权，绝不能回退到 `session('currentProject')`。（已确立的模式参见 Wiki/Ideas。）

### 架构细节

#### 应用启动顺序

1. `public/index.php` 加载辅助函数、自动加载器，并通过 `bootstrap/app.php` 创建 Application
2. `bootstrap/app.php` 创建 `Leantime\Core\Application`（继承 Laravel 的对应类），绑定 HttpKernel、ConsoleKernel、ExceptionHandler、IncomingRequest
3. `Bootloader::getInstance()->boot($app)` 捕获请求并路由到 HttpKernel 或 ConsoleKernel
4. **HttpKernel bootstrappers**（按顺序）：LoadEnvironmentVariables、**LoadConfig**（自定义 —— 加载 `laravelConfig.php` + Environment）、HandleExceptions、RegisterFacades、RegisterProviders、BootProviders
5. **中间件管线**处理请求（参见 Middleware 章节）
6. **路由**：先尝试 Laravel 路由，如果没有匹配则回退到 Frontcontroller

#### 配置

系统管理员可以使用 .env 文件或环境变量来配置 Leantime。这些需要存放在 `config/` 文件夹中。

**自定义配置加载器**（`app/Core/Bootstrap/LoadConfig.php`）：
- 创建 `Environment` 实例作为配置仓储（不是 Laravel 的标准 Repository）
- 从 `app/Core/Configuration/laravelConfig.php` 加载（不是从 `config/` 的 PHP 文件加载）
- 优先级顺序：环境变量 > .env 文件 > PHP 配置文件 > DefaultConfig 默认值
- 将 `DefaultConfig` 属性上的 `#[LaravelConfig('dotted.key')]` attribute 映射到 Laravel 配置

**重要**：ServiceProviders 的列表存放在 `laravelConfig.php` 中。所有 Laravel 配置（数据库、缓存、会话、认证等）都位于这一个文件里，而不是分散在独立的 `config/*.php` 文件中。标准的 `artisan publish` 将无法正确工作。

可由用户编辑的变量应添加到 `config/.env.sample`，并通过 `LEAN_*` 前缀暴露。

#### 数据层架构（待重构）

1. **仓储（Repository）类**：位于各领域专用的 `/Repositories` 文件夹中，这些类继承 `Leantime\Core\Db\Repository` 并提供数据访问层。依据代码编写时间的不同，它们混用原生 SQL 查询与 Laravel Query Builder。`dbcall()` 方法提供了一个包装器，在 SQL 执行前后派发事件。

2. **模型**：位于各领域专用的 `/Models` 文件夹中，这些是带有 public 属性的简单数据结构。没有 ORM 注解、没有验证、没有封装。属性通常使用 `mixed` 类型提示。有些使用 `#[DbColumn('name')]` attribute 进行列映射。

3. **数据库抽象**：`Core/Db/Db.php` 包装了 Laravel 的 `DatabaseManager`（不再使用原生 PDO）。`Core/Db/DatabaseHelper.php` 为 MySQL、PostgreSQL 和 MS SQL Server 提供跨数据库兼容性辅助函数。

4. **表命名约定**：数据库表使用 `zp_` 前缀（例如 `zp_projects`、`zp_users`）。

为了集成 Doctrine，以下方面将需要重构：

- **仓储（Repository）模式**：当前的仓储把领域逻辑与数据访问混在一起。它们需要重构为使用 Doctrine 的 EntityManager。
- **实体定义**：当前的模型需要转换为规范的 Doctrine 实体，并带有用于映射的注解/attribute。
- **SQL 语句**：原生 SQL 查询需要替换为 Doctrine 的 DQL 或 QueryBuilder。
- **列 attribute**：当前的 `DbColumn` attribute 需要替换为 Doctrine 的映射注解。
- **事务**：当前手动的事务处理将替换为 Doctrine 的事务管理。
- **关系管理**：当前手动的关系处理将替换为 Doctrine 的关系映射。

#### HTTP 层架构

**双路由系统**：
1. **Laravel 路由**（新增，首选）：域和插件中的标准 `routes.php` 文件，由 `RouteLoader` 加载
2. **Frontcontroller**（遗留（legacy），已废弃，但仍处理大多数请求）：基于约定的 URL 到类的映射

**Frontcontroller URL 约定**（`Core/Controller/Frontcontroller.php`）：
```
/module/action           -> Domain\{Module}\Controllers\{Action}::get()|post()
/module/action/id        -> Domain\{Module}\Controllers\{Action}::get()|post() with id param
/module/action/id/method -> Domain\{Module}\Controllers\{Action}::method()
/hx/module/action        -> Domain\{Module}\Hxcontrollers\{Action}
```
解析顺序：Domain Controllers > Domain Hxcontrollers > Plugin Controllers > Plugin Hxcontrollers

**两种控制器方法模式**（两者共存）：
1. **`run()` 方法（遗留（legacy），约 55 个控制器）**：由单个方法通过内联 `$_POST`/`$_GET` 检查同时处理 GET 和 POST
2. **`get($params)` / `post($params)`（现代，约 83 个控制器）**：按 HTTP 动词拆分方法。返回 `Response`。**新代码请优先采用此模式。**

**请求类型**（通过 `RequestTypeDetector` 自动检测）：
- `IncomingRequest` - 标准 Web 请求
- `ApiRequest` - API 请求（新增 `getAuthorizationHeader()`、`getAPIKey()`、`getBearerToken()`）
- `HtmxRequest` - HTMX 请求（新增 `isBoosted()`、`getTarget()`、`getTriggerName()` 等）

**中间件栈**（`HttpKernel.php` 中的确切顺序）：
1. `TrustProxies` - 代理信任校验
2. `StartSession` - 带锁与指数退避的会话初始化
3. `Installed` - 若未安装则重定向到 `/install`
4. `Updated` - 若数据库版本落后则重定向到更新流程
5. `LoadPlugins` - 触发事件，进而触发用户插件 `register.php` 的加载
6. `InitialHeaders` - 安全响应头（CSP、X-Frame-Options）——可由插件过滤
7. `AuthCheck` - 认证（web 守卫（guard）+ API 守卫（guard）、2FA 检查、公共路由绕过）
8. `AuthenticateSession` - 密码哈希校验、Leantime 用户会话数据
9. `RequestRateLimiter` - 限流（按分钟；以 IP + 会话用户 id 为键；API/MCP/通用共享同一个计数器）：login 20/min、API 120/min、MCP 300/min、general 2000/min ——可通过 `LEAN_RATELIMIT_*` 覆盖
10. `HandleCors` - CORS 处理
11. `ValidatePostSize` - POST 大小校验
12. `TrimStrings` - 空白字符裁剪（密码除外）
13. `ConvertEmptyStringsToNull` - 空字符串转为 null
14. `SetCacheHeaders` - 支持 etag 的缓存控制
15. `Localization` - 语言、时区、日期/时间格式、CarbonImmutable 宏
16. `CurrentProject`（域中间件）- 为非 HTMX/API 请求设置当前活动项目上下文

**双管线架构**：在核心中间件栈之后，会为插件注册的中间件运行第二条管线：
```php
// Core middleware -> Plugin middleware -> Router dispatch
```
插件通过 `Registration::registerMiddleware()` 注册到这条第二条管线中。

#### 事件系统

Leantime 在 `Core/Events/` 中有一套自定义事件系统，它实现了 Laravel 的 `Dispatcher` 接口，但提供两套并行的机制（类似于 WordPress 钩子）：

**事件**（即发即忘）：
```php
self::dispatch_event('ticket_created', $payload);
```

**过滤器**（通过管线修改数据）：
```php
$result = self::dispatch_filter('beforeReturnAllPlugins', $installedPlugins, ['enabledOnly' => $enabledOnly]);
```

**事件名约定**：名称由类命名空间 + 方法自动生成：
```
leantime.domain.tickets.services.tickets.updateTicket.ticket_updated
```
移动一个类会改变它所有的事件名——这正是基于类的事件成为期望方向的原因。

**监听器注册**（在 `register.php` 文件中）：
```php
// Class-based listener (calls handle() method)
EventDispatcher::add_event_listener(
    'leantime.domain.projects.services.projects.notifyProjectUsers.notifyProjectUsers',
    NotifyProjectUsers::class
);

// Closure listener with wildcard
EventDispatcher::addEventListener('leantime.domain.auth.*.userSignUpSuccess', function ($params) {
    $helperService = app()->make(\Leantime\Domain\Help\Services\Helper::class);
    $helperService->createDefaultProject(session('userdata.id'), session('userdata.role'));
});

// Filter listener with priority
EventDispatcher::add_filter_listener(
    'leantime.domain.menu.repositories.menu.getMenuStructure.menuStructures.project',
    function ($menu) { $menu['newItem'] = [...]; return $menu; },
    50  // lower = earlier execution
);
```

**模式匹配**：支持 `*`（任意字符串）、`?`（任意字符）、`{RGX:pattern:RGX}`（内联正则）。

**Blade 指令**：`@dispatchEvent('eventName')`、`@dispatchFilter('filterName', $data)`

**事件发现**（`discoverListeners()`）：在启动时调用，扫描所有 `app/Domain/*/register.php` 文件 + 系统插件 `register.php` 文件。用户启用的插件 register 文件稍后通过 `LoadPlugins` 中间件事件加载。

**register.php 模式指南**：拥有 `register.php` 的域有：Auth、CsvImport、Help、Install、Notifications、Plugins、Queue、Reports。这些文件：
- 通过 `EventDispatcher` 注册事件/过滤器监听器
- 通过 Laravel Scheduler 调度定时任务（cron）
- 钩入应用生命周期事件
- 目前全部使用基于字符串的事件名

#### 服务层架构

服务层实现业务逻辑，并遵循以下原则：

1. **领域（Domain）服务**：这些类位于各域专属的 `/Services` 文件夹中，实现了 `Leantime\Core\Domains\DomainService` 接口。

2. **职责**：服务类封装业务规则并在仓储（Repository）之间协调，通常会把来自多个仓储（Repository）的数据组合起来。

3. **实现模式**：
   - 服务把数据访问委托给仓储（Repository）
   - 服务处理域专属的校验规则
   - 服务在发生重要状态变更时触发事件
   - 服务实现权限检查与授权逻辑
   - 使用基于构造函数的依赖注入（DI），配合 PHP 8 的构造器属性提升
   - 使用 `DispatchesEvents` trait 做事件集成
   - 使用 `dispatch_filter()` 作为插件钩子点

4. **过滤器系统**：服务使用过滤器系统，让插件可以在处理之前和之后修改数据。

5. **API 暴露**：服务类中大多数公共方法都标有 `@api` 注解，以表明它们属于稳定 API 的一部分。任何公共服务方法都可以通过 JSON-RPC 在 `leantime.rpc.{Domain}.{Service}.{method}` 处调用——`@api` 注解仅为文档用途，运行时不做强制。

为 Doctrine 重构时：
- 服务需要改为处理 Doctrine 实体，而不是数组结构
- 事务处理将从仓储（Repository）移到服务
- 水合（hydration）逻辑可以用 Doctrine 的实体管理器简化

#### JSONRPC API 架构

Leantime 为用户提供 JSON-RPC 2.0 API。该 API 是一个薄封装，可通过 API 域（`app/Domain/Api/Controllers/Jsonrpc.php`）访问，并为所有域的服务层提供结构化访问。

**方法路由约定**：
```
leantime.rpc.{domain}.{methodname}                  # 4 segments (service = domain name)
leantime.rpc.{domain}.{servicename}.{methodname}     # 5 segments
```

**工作原理**：控制器使用 PHP 反射（Reflection）内省服务方法的参数，按名称匹配请求参数，校验必填参数，并尝试类型转换。服务通过 `app()->make()` 解析。

**认证**：有两种类型：
1. **Leantime API 密钥**（`x-api-key` 请求头）：格式为 `lt_{user}_{key}`，充当服务账号
2. **Laravel Sanctum**（Bearer 令牌）：个人访问令牌（需要 AdvancedAuth 插件）

**已废弃的 API 控制器**：`app/Domain/Api/Controllers/` 目录中包含返回 JSON 的遗留（legacy）类 REST 控制器（Tickets.php、Projects.php 等）。这些已废弃——所有新的 JS API 调用都应走 JSON-RPC 端点。

#### 模板系统

Leantime 使用双模板系统，正积极从 PHP 迁移到 Blade：

**模板类型**：
- `.tpl.php`（约 198 个文件）- 使用 `$tpl->get('variable')` 模式的遗留（legacy）PHP 模板
- `.blade.php`（域中约 91 个，Views 中约 33 个）- 现代 Laravel Blade
- `.sub.php`（约 19 个文件）- 通过 `$tpl->displaySubmodule()` 复用的遗留（legacy）模板 fragment
- `.inc.php`（约 10 个文件）- 画布基础 include

**共享视图文件夹**（`app/Views/`）：
- `Templates/layouts/` - 布局骨架：`app.blade.php`（主布局）、`entry.blade.php`（登录）、`blank.blade.php`、`error.blade.php`、`registration.blade.php`
- `Templates/components/` - 共享 Blade 组件：accordion、badge、button、dropdownPill、emojiinput、inlineLinks、inlineSelect、loader、loadingText、pageheader、selectable、tabs、undrawSvg，以及 kanban 子组件
- `Templates/sections/` - header、footer、pageBottom、appAnnouncement
- `Composers/` - App、Header、Footer、Entry、PageBottom

**组件语法**：共享组件用 `<x-global::componentName>`，域专属组件用 `<x-widgets::moveableWidget>`。匿名组件会自动解析——`<x-{domain}::{name}>` → `app/Domain/{Domain}/Templates/components/{name}.blade.php`，`<x-global::{name}>` → `app/Views/Templates/components/{name}.blade.php`（在 `ViewsServiceProvider::registerBladeCompiler()` 中注册）。

**三种组件类型**（任何可复用的内容都应优先使用组件，而不是 `@include` 的 partial）：
1. **展示型（匿名）**——由 `@props` 驱动的纯标记，没有控制器。位于 `Templates/components/`，以 `<x-{domain}::{name}>` 调用。是卡片/徽章/子菜单等的默认选择。
2. **HTMX 驱动**——由 `HxController`（`init()` 依赖注入、`$view`、action 方法）支撑的自行获取/刷新小部件。使用标准的 `<x-global::hx>` 组件挂载，而不是手写 `hx-get`/`hx-trigger` 样板代码。在 `HxComponent` 上声明事件契约（见下文）。
3. **类支撑**——带有 PHP 类的 Blade 组件，用于构造函数依赖注入 / 计算属性。类位于 `app/Domain/{Domain}/View/Components/{Name}.php`（全局组件则为 `app/Views/Components/`），解析优先于匿名文件。请谨慎使用，仅当逻辑对匿名组件而言过重、且不是服务端获取时才使用。

`partials/` 保留给不可复用、页面专属的 fragment，以及 `HxController` 的 `$view` 目标。

**模板渲染方法**（位于 `Template` 类上）：
- `display($template, $layout, $code)` - 带布局的整页渲染
- `displayPartial($template)` - 不带布局渲染
- `displayFragment($viewPath, $fragment)` - HTMX fragment 渲染
- `displaySubmodule($alias)` - 渲染遗留（legacy）子模块
- `emptyResponse()` - 空 HTTP 响应

**用于异步调用的 HTMX**

Leantime 正在对需要异步更新的元素使用 HTMX。这一过程仍在进行中。
目标是让主页面控制器只加载最少量的数据来展示页面以及一些共享组件（比如过滤器之类），而所有内容都通过 htmx 加载。
所有 htmx 控制器都在 HxControllers 文件夹内。htmx 调用的模板应放在 `templates/partials` 中，因为它们只代表页面内容的一小部分。
如果某个 partial 或 htmx 调用代表的实体可能会在其他多处被使用（工单卡片、项目卡片、用户卡片等），则应创建一个组件。

**HTMX 模式指南**：

URL 约定：`/hx/{module}/{controller}/{action}`

创建 HxController：
```php
namespace Leantime\Domain\{Module}\Hxcontrollers;

use Leantime\Core\Controller\HtmxController;

class MyController extends HtmxController
{
    // Required: points to a Blade partial
    protected static string $view = '{module}::partials.myPartial';

    // DI via init(), NOT __construct()
    public function init(MyService $service): void
    {
        $this->service = $service;
    }

    // Action methods are named semantically, not by HTTP verb
    public function get($params): void
    {
        $this->tpl->assign('data', $this->service->getData($params['id']));
    }

    public function save(): void
    {
        // Process $_POST
        $this->tpl->setNotification('Saved!', 'success');   // already emits HtmxUiEvents::Notify
        $this->tpl->emit(HtmxTicketEvents::UPDATE);          // tell listeners the entity changed
    }
}
```

**客户端（HTMX）事件命名约定**——事件通过 `HX-Trigger` 响应头从服务器传到浏览器。分为两个平面，与后端的 `leantime.*` 事件保持分离：
- **数据事件**（`lt:{domain}:{entity}.{verb}`，过去时）：“实体 X 已变更 → 刷新”。通过 `hx-trigger="lt:tickets:ticket.updated from:body"` 以声明方式消费。由各域的枚举 `app/Domain/{D}/Htmx/Htmx{D}Events.php` 支撑。
- **UI 命令事件**（`lt:ui:{command}`）：“做一件 UI 相关的事”（toast 提示、关闭模态框）。由单一的核心枚举 `Leantime\Core\Events\Htmx\HtmxUiEvents`（Notify、ModalClose、…）支撑——由 `app.js`/`modals.js` 中的 JS 监听器消费。插件复用这些事件，绝不自造 `lt:ui:*`。

枚举通过 `InteractsWithHtmxEvents` trait 实现 `HtmxEvent`（提供 `event()`→传输值、用于按实体事件的 `scoped($id)`、用于 `hx-trigger` 字符串的 `trigger()`）。PHP 枚举无法实现 `__toString`，因此在 PHP 中请使用 `event()`/`->value`；Blade 的 `{{ Events::Case }}` 会通过 Laravel 的 `e()` 渲染该值。使用 `$this->tpl->emit(...)` / `$this->setHTMXEvent(...)` 发出（两者都接受 `HtmxEvent|string`）。

> 迁移窗口：遗留（legacy）的 DOMAIN 事件字符串（`ticket_update`、`subtasks_update` 等）会通过 `HtmxEvents::LEGACY_ALIASES` 与其 `lt:` 替代者进行双向双重发出，因此旧的和新的声明式 `hx-trigger` 监听器都会触发。当所有发出方 + 监听方都完成迁移后，移除此映射。UI 命令事件（`lt:ui:*`）不做双重发出——它们由 JS 的 `addEventListener` 处理器消费，这些处理器直接监听每个遗留名称，因此为它们设置别名会导致每个别名各调用一次处理器（重复 growl / 多次关闭模态框）。

```php
// Per-domain data-event enum
use Leantime\Core\Events\Htmx\{HtmxEvent, InteractsWithHtmxEvents};

enum HtmxTicketEvents: string implements HtmxEvent {
    use InteractsWithHtmxEvents;
    case UPDATE = 'lt:tickets:ticket.updated';
    case SUBTASK_UPDATE = 'lt:tickets:subtask.updated';
}

// Emit from an HxController action
$this->tpl->emit(HtmxTicketEvents::UPDATE);
```

**HTMX 驱动的组件**——在 `HxComponent` 上声明事件契约，并用 `<x-global::hx>` 挂载（无需手写样板代码）。挂载时会读取 `listensTo()`，因此发出侧和监听侧引用的是同一个枚举，不会彼此偏离：
```php
class Subtasks extends \Leantime\Core\Controller\HxComponent {
    protected static string $view = 'tickets::partials.subtasks';
    public static string $swap = 'innerHTML';
    public static function route(): string { return 'tickets/subtasks'; }
    public static function listensTo(): array { return [HtmxTicketEvents::SUBTASK_UPDATE]; }
    public static function emits(): array { return [HtmxTicketEvents::SUBTASK_UPDATE]; }
}
```
```blade
{{-- contract-driven: route + refresh triggers derived from the class --}}
<x-global::hx :for="\Leantime\Domain\Tickets\Hxcontrollers\Subtasks::class" :id="$ticket->id" trigger="load" />

{{-- attribute-driven escape hatch (one-offs / plugins) --}}
<x-global::hx endpoint="comments/reactions/get" :listen="[HtmxTicketEvents::UPDATE]" />
```

常用的 HTMX 模式：
- 懒加载：`hx-trigger="revealed"`（小部件在滚动进入视图时加载）——`<x-global::hx>` 默认即为此
- 跨组件更新：`hx-trigger="lt:tickets:ticket.updated from:body"`
- 加载指示器：`hx-indicator=".htmx-indicator"` 配合 `<x-global::loadingText>`
- 预加载：`preload="mouseover"`（用于下拉菜单的悬停预加载）
- 通知：`$tpl->setNotification(...)` 会发出 `HtmxUiEvents::Notify`（`lt:ui:notify`）→ 经 `app.js` 中的全局监听器触发 jQuery growl

**批量模板变量赋值**（HxControllers 中的常见模式）：
```php
array_map([$this->tpl, 'assign'], array_keys($tplVars), array_values($tplVars));
```

#### 角色管理、授权与认证
- Leantime 结合使用 Laravel 的标准认证、Sanctum 以及自定义认证提供方。
- 三个认证守卫（guard）：`leantime`（web，基于会话）、`sanctum`（令牌）、`jsonRpc`（API）
- 每个用户可以拥有一个角色，该角色目前是硬编码的
- 每个用户可以分配到一个客户
- 用户可以被分配到项目
- 角色赋予用户访问数据或系统各部分的权限
- 此外，每个用户还有特定的项目访问权限。
  - 项目可以是“Accessible to everyone”、“accessible only by users within a client”，或仅可由直接分配到该项目的用户访问。
  - Admins 和 Owners 可以访问所有项目

**API 认证**

系统管理员和用户可以创建 API 密钥。密钥有 2 种类型：
1. Leantime API 密钥，充当服务账号，并按普通用户的方式处理。格式：`lt_{user}_{key}`。用户名是 API 密钥名称，密码是 api-secret
2. 个人访问令牌可由用户创建（如果已安装 AdvancedAuth 插件）。令牌可用于认证拥有它们的用户。我们为此使用 Laravel Sanctum。

**其他认证提供方**
Leantime 原生支持 LDAP 和 OIDC 认证，但也可以通过 Laravel Socialite 集成其他提供方（Authentik、Auth0、Gitea、GitHub、GitLab、Google、Keycloak、Microsoft、Okta、PropelAuth、EduID、SAML2）。

## 前端架构

### 构建系统
Laravel Mix 6.x（Webpack 5.x）—— 在 `webpack.mix.js` 中配置。输出到 `public/dist/`，文件名带版本戳。

**JS 打包产物**（全部通过 `header.blade.php` 在每个页面加载）：
- `compiled-htmx` + `compiled-htmx-extensions` - HTMX 核心 + head-support、preload、SSE 扩展
- `compiled-frameworks` - jQuery 3.7.1 + Bootstrap 2.x
- `compiled-framework-plugins` - jQuery UI、Chosen.js、growl、tags input、nestedSortable
- `compiled-global-component` - Luxon、Moment、Tippy.js、Uppy、Croppie、Packery、Shepherd.js、Isotope、GridStack、jsTree、Mermaid、Marked
- `compiled-editor-component` - TinyMCE 5.10.9 + 约 20 个自定义插件（3.6MB）
- `compiled-calendar-component` - FullCalendar + iCal.js
- `compiled-table-component` - DataTables + 插件
- `compiled-gantt-component` - Snap.svg + 自定义 Frappe Gantt
- `compiled-chart-component` - Chart.js + Luxon 适配器
- `compiled-app` - 核心应用 + 通过 glob `./app/Domain/**/*.js` 引入的全部领域 JS 文件

### JavaScript 架构
**全局命名空间**：所有 JS 都使用带 IIFE 模块模式的 `leantime` 命名空间：
```javascript
leantime.ticketsController = (function () {
    function doSomething() { ... }
    return { doSomething: doSomething };
})();
```

**领域 JS 文件**（`app/Domain/*/Js/` 中共 46 个）：模式与后端一致 —— `{domain}Repository.js` 负责 AJAX，`{domain}Service.js` 负责逻辑，`{domain}Controller.js` 负责 UI/DOM。

**指导**：使用 HTMX 进行数据加载/更新。仅在需要交互性（编辑器、拖放等）时使用 JS。当需要 fetch 时使用 JSON-RPC 端点。使用 fetch 时：
```javascript
fetch(url, { credentials: "include", headers: { 'X-Requested-With': 'XMLHttpRequest' } })
```

### CSS 架构
**三层体系**：
1. **第三方**：Bootstrap 2.x、jQuery UI、Font Awesome 6.5.2、特定库的 CSS
2. **自定义组件**：`public/assets/css/components/` —— structure.css、style.default.css、nav.css、kanban.css、forms.css、mobile.css、tables.css 等。
3. **Tailwind 3.4.x**：提供带 `tw-` 前缀的版本以避免与 Bootstrap 冲突。仅启用 `@tailwind components` 与 `@tailwind utilities`（base 已禁用）。新的 CSS 正在向 Tailwind 迁移。

**CSS 变量（设计令牌）**：主题系统建立在 100 多个 CSS 自定义属性之上。始终使用这些变量，而不是硬编码的值：
- 颜色：`--accent1`、`--accent2`、`--primary-color`、`--primary-font-color`、`--primary-background`、`--secondary-background`、`--layered-background`
- 排版：`--primary-font-family`、`--base-font-size`、`--font-size-xs` 到 `--font-size-xxxl`
- 布局：`--box-radius`、`--box-radius-small`、`--box-radius-large`、`--element-radius`、`--input-radius`
- 阴影：`--min-shadow`、`--regular-shadow`、`--large-shadow`、`--input-shadow`
- Z-index：`--zlayer-1` 到 `--zlayer-9`
- 玻璃效果：`--glass-blur`、`--glass-background`、`--glass-border`

### 主题系统
主题位于 `public/theme/{name}/`，包含 `theme.ini`、`css/light.css`、`css/dark.css`。
两个内置主题：**default**（“More”）和 **minimal**（“Less”），两者都有浅色/深色模式。
字体：Roboto（默认）、Atkinson Hyperlegible（无障碍）、Shantell Sans。

## 编码规范

### 任务处理优先级

处理用户请求时，遵循以下优先级顺序：

1. **简单查询**：对于关于现有代码的直接问题，直接使用 Read/Grep 工具
2. **代码修改**：对于现有功能的改动，先分析当前实现
3. **新功能**：对于新功能，在实现之前先研究已有的类似模式
4. **调试**：对于缺陷修复，先复现问题，再实现修复
5. **复杂任务**：对于多步骤操作，先用 TodoWrite 制定计划再执行

### 上下文管理

#### 处理大型代码库
- 有策略地使用搜索工具（Grep、Glob），在读取文件之前先找到相关代码
- 当可能有多个相关文件时，批量执行工具调用以高效地读取它们
- 专注于理解与用户请求相关的特定代码区域

#### 澄清需求
- 当用户请求含糊不清时，提出澄清性问题
- 当可能存在多种实现方式时，向用户展示可选项
- 如果不确定现有的模式或约定，先研究代码库

#### 高效使用工具
- 对于可能需要多轮执行的复杂搜索，使用 Task 工具
- 在单次回复中批量执行相互独立的工具调用
- 在处理相互关联的功能时，一起读取相关文件

### 测试策略

**框架**：Codeception v5.1（封装 PHPUnit）。所有测试目标都通过 `make` 命令以 Docker 优先方式运行。

#### 何时运行测试
- **在做出更改之前**：运行相关测试以建立基线
- **在开发过程中**：为正在修改的具体领域运行单元测试
- **在实现之后**：对受影响的区域运行完整测试套件
- **在提交之前**：始终运行代码风格检查和静态分析

#### 测试选择指南
- 对于 API 改动：运行 API 专用的验收测试（`-g api`）
- 对于领域相关的改动：运行该领域的测试（例如 `-g timesheet`）
- 对于核心改动：运行完整测试套件
- 对于前端改动：同时测试功能与样式

#### 测试失败的处理
- 绝不忽略测试失败
- 在继续开发新功能之前先修复失败的测试
- 如果测试确实已经过时，作为任务的一部分更新它们

### 安全准则

#### 数据保护
- 绝不记录敏感的用户数据（密码、API 密钥、个人信息）
- 对所有用户输入使用恰当的输入校验与清理
- 遵循现有的认证与授权模式
- 在处理数据库查询时注意防范 SQL 注入

#### 插件开发安全
- 处理插件时，确保它们遵循相同的安全标准
- 校验插件的输入与输出
- 不要通过插件 API 暴露内部系统信息
- 对插件权限遵循最小权限原则

#### 代码安全实践
- 通过现有的仓储（Repository）模式使用参数化查询
- 校验文件上传并安全地处理它们
- 确保恰当的会话管理
- 遵循 OWASP 的 Web 应用安全指南

### 性能准则

#### 数据库操作
- 使用现有的仓储（Repository）模式，而不是直接查询
- 处理关联数据时注意 N+1 查询问题
- 添加新的查询模式时考虑数据库索引
- 对大型结果集使用分页

#### 文件操作
- 读取多个相关文件时使用批量工具调用
- 避免不必要地读取大文件 —— 先使用有针对性的搜索
- 处理大型数据集时考虑内存占用

#### 前端性能
- 添加新功能时最小化 JavaScript 打包产物的体积
- 使用 HTMX 实现高效的 partial 页面更新
- 适当地优化图片与静态资源
- 遵循现有的懒加载与缓存模式
- 使用 htmx 进行信息更新与重新加载，使用 javascript 实现交互性

#### 缓存
- Leantime 使用 Laravel 缓存，可以是基于文件的或 Redis。
- 凡是有昂贵操作发生的地方都应使用缓存。
- 当 Redis 可用时，检查管理员是否选择了 Redis，并通过相应的 ServiceProvider 自动加载配置。

## 开发实践

### 插件系统

Leantime 有一个完善的插件系统，可以扩展核心功能：

1. **插件架构**：
   - 插件位于 `app/Plugins` 目录（商用插件是指向私有仓库的 git 子模块）
   - 每个插件都是自包含的包，拥有自己的领域（Domain）结构
   - 插件可以通过 Composer 拥有自己的 vendors
   - 两种插件格式：基于文件夹和基于 phar（用于插件市场插件）

2. **插件注册**（`register.php`）：
   - 通过 `EventDispatcher` 注册事件监听器与过滤器
   - `Registration` 服务（`Domain\Plugins\Services\Registration`）提供流式 API：
     ```php
     $registration = new Registration('MyPlugin');
     $registration->registerMiddleware([MyMiddleware::class]);
     $registration->registerLanguageFiles(['en-US', 'de-DE']);
     $registration->addMenuItem([...], 'project', ['main', 'submenu-key']);
     $registration->addCss(['app.css']);
     $registration->addHeaderJs(['vendor.js']);
     $registration->addFooterJs(['app.js']);
     ```

3. **插件加载顺序**：
   - **系统插件**（来自 `LEAN_PLUGINS` 环境变量）：在启动时于 `discoverListeners()` 期间加载，早于中间件。无法通过 UI 禁用。
   - **用户插件**：在 `LoadPlugins` 中间件触发时加载（在会话、安装检查、更新检查之后）
   - 插件的 `routes.php` 文件通过 `RouteLoader` 加载

4. **插件类型**：
   - 系统：在配置中定义的核心已启用插件。始终加载，无法通过 UI 禁用，在栈中更早加载
   - 插件市场：来自 marketplace.leantime.io。以 phar 包形式分发，需要许可证密钥校验
   - 自定义文件夹：常规插件或开发中的插件

5. **插件生命周期**：`discoverNewPlugins()` -> `installPlugin()` -> `enablePlugin()` -> `disablePlugin()` -> `removePlugin()`。每个插件服务类都可以实现 `install()`、`uninstall()`、`enable()`、`disable()` 钩子。

6. **插件许可证密钥与校验**：
   - 插件可以从插件市场（marketplace.leantime.io）购买。
   - 每个插件都需要使用许可证密钥进行安装，该密钥存储在数据库中
   - 许可证密钥是永久的，但受用户数量限制。
   - Leantime 会定期检查系统中的活跃用户数量，并与服务器进行校验。
   - 如果系统的用户数超过某个插件允许的数量，该插件会被禁用。数据仍保留在数据库中
   - 每日定时任务（cron）校验所有插件市场插件的许可证

7. **重构考虑**：
   - 从基于字符串的事件钩子转向基于类的事件
   - 实现更健壮的依赖管理系统
   - 统一插件启用/停用钩子
   - 添加版本管理与兼容性检查
   - 插件更新应自动处理，无需输入许可证密钥

### 事件系统
功能应使用事件系统，以在组件之间保持松耦合。事件系统是 Laravel 事件的自定义扩展，也包含过滤器的选项。完整文档参见架构细节下的“事件系统”一节。

### 路由
Leantime 采用双路由：Laravel 路由（新代码首选）和遗留（legacy）Frontcontroller（基于约定的 URL 到类映射）。详情参见 HTTP 层架构。

### 测试
代码应使用以下工具进行测试：
- PHPStan 用于静态分析（当前为 level 0）
- Laravel Pint 用于代码风格（主要工具；也配置了 PHPCS，但优先使用 Pint）
- Codeception v5.1 用于单元测试和验收测试
- 测试分组：`api`、`timesheet`、`login`、`ticket`、`user`

## 具体代码风格规范

### 代码风格
我们使用 Laravel Pint 进行代码风格检查（配置位于 `.pint/pint.json`）。

### 向后兼容
除非特别说明，否则不要保留任何旧代码，也不要构建任何形式的向后兼容。

### 配置
所有 laravel 配置都需要存储在核心配置文件夹内的 laravelConfig 文件中。任何应由用户编辑的变量都应添加到 sample.env 文件，并通过 `LEAN_*` 暴露。我们不从根配置文件夹加载任何自定义 php 配置，因此像 artisan publish 这样的操作不会正确地发布配置。相反，内容需要添加到 laravelConfig 中。
当某个服务（队列、缓存、会话等）可使用 redis 时，我们应检查管理员是否选择了使用 redis，然后通过相应的 serviceProvider 自动加载 redis 配置。

### 错误日志
记录错误时，始终使用 Log Facade（确保它已包含在 use 语句中）。
示例：`Log::error($exception)`

不要使用辅助函数 error_log()

### 严格类型
- 尽可能使用严格类型（用于返回值和参数）
- 创建数组时，评估是否应使用模型/对象，如认为合适则创建一个

### 注释
- 为所有方法和类添加有效的 phpDoc 注释。
- 对于每个被修改的方法，确认其 PhpDoc 注释存在且与代码一致
- 服务中对我们的 jsonRPC 可用的方法应包含 @api 文档注释

### 日期时间处理
所有与日期时间相关的事情都始终使用 `CarbonImmutable` 或 `dtHelper()` 函数类，它们有各种宏来帮助处理常见的日期格式。
作为一般规则，来自数据库的所有日期都假定为 UTC，且格式为 YYYY-MM-DD HH:MM:SS
来自前端/用户的日期假定为用户所在时区及其相应的日期格式。
我们有一个 DateTimeHelper 类来解析我们遇到的常见日期时间格式，大多数情况下都应使用 dateTimeHelper。

### 分层约束
- 控制器只应调用服务，而不是仓储。如果检测到仓储调用，应进行重构。
- 服务可以调用仓储
- 在其他领域服务中调用领域服务时要小心，因为可能发生循环引用
- 服务应校验输入，并在校验失败时抛出异常
