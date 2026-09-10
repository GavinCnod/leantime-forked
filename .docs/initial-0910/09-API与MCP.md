# 09 API 与 MCP

## 9.1 JSON-RPC 2.0 API（主 API 面）

**端点**：`POST /api/jsonrpc`（控制器 [Domain/Api/Controllers/Jsonrpc.php](app/Domain/Api/Controllers/Jsonrpc.php)，562 行；前端客户端 [app/Domain/Api/Js/jsonrpcClient.js](app/Domain/Api/Js/jsonrpcClient.js)）。

**方法命名**（`parseMethodString` :310-345）：
```
leantime.rpc.{module}.{methodname}               # 4 段（service = 域同名）
leantime.rpc.{module}.{servicename}.{methodname} # 5 段
```

**分发逻辑**（:198-218）：按 `app/Domain/{Module}/Services/{Service}` 解析，依次回退：Domain Service → Plugin Service → **Plugin Tools**。使用 PHP Reflection 匹配参数名、校验必填、类型强转（`prepareParameters` :423-474）。

**方法可见性**：仅 docblock 含 **`@api`** 标签的公开方法可被调用（`isApiMethod` :354-371）。因此"服务方法 + `@api` 注解"就是二开最便宜的 API 暴露方式。

**协议细节**：
- 必须带 `"jsonrpc": "2.0"`；支持批量请求（:175-183）；
- 无 `id` 视为通知，不响应；
- 错误码：`-32700` 解析错误、`-32600` 无效请求、`-32601` 方法不存在、`-32602` 参数错误、`-32000` 服务器错误、`-32001` 授权拒绝、`-32004` 插件未启用；
- 响应封装 [JsonRpcResponse.php](app/Core/Http/Responses/JsonRpcResponse.php) / [JsonRpcErrorResponse.php](app/Core/Http/Responses/JsonRpcErrorResponse.php)；`fromException()` 将 `LeantimeExceptionInterface` 映射为对应 code，其余折叠为 -32000；**HTTP 状态恒为 200**（错误在体内）。
- 方法级/类级 `#[RequiresPlugin]` 门禁（:237-245）。

**BaseService**（[app/Core/Domains/BaseService.php](app/Core/Domains/BaseService.php)）为服务提供 `authorize()`（拒绝抛 AuthorizationException → 403/-32001）、`can()`、`currentUserId()`、`validate()`（422/-32602）；`PermissionService` 由 `afterResolving` 钩子惰性注入（避免构造期循环递归）。

**限流**：API 120 请求/分钟/IP+用户（`LEAN_RATELIMIT_API`）。

**遗留 REST 控制器**：`app/Domain/Api/Controllers/` 下旧式 REST 控制器（Canvas、Goalcanvas、I18n、StaticAsset 等）已废弃，新代码一律走 JSON-RPC。

## 9.2 MCP（Model Context Protocol）AI 工具层

项目集成了 `laravel/mcp`，把业务能力暴露给 AI Agent（MCP 端点，限流 300/min）。

**工具形态**（6 个域共 55 个，`app/Domain/{X}/Tools/`）：

```php
class AddTaskTool extends \Laravel\Mcp\Server\Tool {
    public function name(): string { return 'addTask'; }
    public function description(): string { return 'Adds a new task quickly based on the provided parameters.'; }
    public function schema(ToolInputSchema $schema): ToolInputSchema {
        return $schema->string('headline')->required()->string('description')...
    }
    public function handle(array $arguments): ToolResult { ... }
}
```

- 只读工具加 `#[IsReadOnly]`（如 [FindTasksTool.php:16](app/Domain/Tickets/Tools/FindTasksTool.php#L16)）。
- 工具内部包装领域 Service（如 `AddTaskTool` → `Tickets::quickAddTicket`）。
- 发现机制：`McpToolDiscovery` 扫描各域 `Tools/` 目录；同一类也可被 JSON-RPC 分发（回退链含 `Plugins\X\Tools\X`）。
- 工具分布：Tickets 16、Calendar 10、Projects 9、Goalcanvas 8、Timesheets 7、Comments 5。

> **二开价值点**：给 AI 加能力 = 在对应域加一个 Tool 类（name/schema/handle），无需改任何注册表；AI 自动发现。注意在 `handle()` 内做权限校验（调用 Service 层的鉴权逻辑）。

## 9.3 认证方式

1. **x-api-key**（`ApiGuard`）：格式 `lt_{user}_{key}`（三段式），`password_verify` 比对（[Api.php:56-86](app/Domain/Api/Services/Api.php#L56)）。
2. **Bearer Token**：`php bin/leantime auth:create-bearer-token --email=xxx`（强制 CLI-only，[CreateBearerTokenCommand.php](app/Command/CreateBearerTokenCommand.php)）；40 位随机串明文返回，库中存 sha256（`zp_access_tokens`，列含 `abilities`、`expires_at`、`last_used_at`）；`AuthCheck::authenticateApi` → `Auth::getUserByToken()` 校验。
3. **会话 Cookie**（Web 页面内部 AJAX 调用时也有效）。

**注意**：Sanctum 集成存在（[Core/Auth/Tokens/SanctumServiceProvider.php](app/Core/Auth/Tokens/SanctumServiceProvider.php) 绑定自定义 AccessToken 模型），但本项目 Bearer token 格式与标准 Sanctum 不同，**不要假设两者互换**。

## 9.4 其他 API 相关

- **I18n API**：`/api/i18n`（前端语言包拉取）。
- **StaticAsset**：受控静态资源服务（`StaticAssetType` 枚举定义可服务类型）。
- **status 端点**：`GET /status`（未认证，仅暴露登录方式/版本等安全过滤后的信息，供移动端发现 OIDC）。
- **Cron**：`/cron/run` 返回空响应后在 `request_terminated` 事件里执行 `schedule:run`（"穷人 cron"，无需系统 crontab）。
