# TeamDocs 插件 — 部署与验证

> 生成日期：2026-09-19
> 插件版本：1.0.0 ｜ 包名：`leantime/teamdocs` ｜ 目录：`app/Plugins/TeamDocs`
> 作用：在右上角「帮助」下拉菜单中加入三个团队文档条目，点击以浮态弹框展示 Markdown 文档。
> 与 `CostTracking` 插件相互独立：TeamDocs 不碰任何成本数据，CostTracking 不受本次改动影响。

## 1. 组成

```text
app/Plugins/TeamDocs/
├── composer.json                       # 元数据（包名 leantime/teamdocs）
├── register.php                        # 监听 insideHelpMenu，渲染三个菜单项
├── Hxcontrollers/
│   └── Document.php                    # /hx/teamdocs/document/show/{key}，返回 modal fragment
├── Services/
│   └── Documents.php                   # 白名单 → 读文件 → 安全 Markdown → HTML
├── Templates/
│   ├── helpMenu.blade.php              # 三个 <li>（命名空间 teamdocs::）
│   └── partials/
│       └── documentModal.blade.php     # 弹框内容（标题 + 已净化 HTML）
└── Resources/docs/                     # 运行时文档快照（生产镜像只读这里）
    ├── cost-tracking-rules.md
    ├── leantime-zh-guide.md
    └── leantime-zh-glossary.md
```

核心侧唯一改动：`app/Domain/Menu/Templates/headMenu.blade.php` 在帮助下拉菜单里新增一行
`@dispatchEvent('insideHelpMenu')`（通用扩展点，不知道 TeamDocs 的存在）。
另有 `composer.json` 显式声明 `league/commonmark` 依赖（锁文件已同步 content-hash）。

## 2. 文档来源与同步（重要）

仓库根 `docs/` 是**团队可编辑的唯一事实来源**；`app/Plugins/TeamDocs/Resources/docs/` 是**打包进镜像的运行时快照**。
生产 `.dockerignore` 排除了根 `docs/`，因此运行时**不能**读根目录文档。

改文档的流程：

```bash
# 1. 改根目录三份文档
# 2. 同步到插件资源目录
cp docs/cost-tracking-rules.md  app/Plugins/TeamDocs/Resources/docs/
cp docs/leantime-zh-guide.md    app/Plugins/TeamDocs/Resources/docs/
cp docs/leantime-zh-glossary.md app/Plugins/TeamDocs/Resources/docs/
# 3. 校验（不一致会非零退出）
php scripts/check-teamdocs-docs.php
# 4. 两边一起提交
```

单元测试 `tests/Unit/app/Plugins/TeamDocsResourceSyncTest.php` 会对三份文件做 SHA-256 比对，
源与快照不一致时测试失败——不要只改一边。

## 3. 安装与启用

### 方式一：CLI

```bash
php bin/leantime plugin:install leantime/teamdocs
php bin/leantime plugin:enable  leantime/teamdocs
```

> 与 CostTracking 同样的规则：参数是 **composer 包名**（`leantime/teamdocs`），不是目录名。
> 非交互环境追加 `--no-interaction`。

### 方式二：管理界面

管理员登录 → 插件管理 → 「发现新插件」→ 安装 → 启用。

### 方式三：持久化卷 / Railway（自动）

与 CostTracking 共用同一套内置插件同步机制（镜像快照 → entrypoint 幂等同步 → 可选自动启用）：

```bash
LEAN_PLUGINS_ENABLE=mindrose/costtracking,leantime/teamdocs
# 只需要 TeamDocs 时：LEAN_PLUGINS_ENABLE=leantime/teamdocs
```

部署日志中应依次看到：

```text
[entrypoint] syncing built-in plugins into ...
[plugin:ensure] leantime/teamdocs: installed (folder=TeamDocs, id=...)
[plugin:ensure] leantime/teamdocs: enabled
[entrypoint] plugins ensured
```

首次若数据库未就绪，entrypoint 每 2 秒重试、最多 60 秒，耗尽只告警不阻断启动。
TeamDocs 没有自有数据表，install 只登记 `zp_plugins`，重复执行无副作用。

## 4. 验证用例

| # | 操作 | 期望结果 |
|---|---|---|
| 1 | 构建镜像，确认 `app/Plugins/TeamDocs/Resources/docs/` 三份 md 存在 | 三个文件都在，且与根 `docs/` 的 SHA-256 一致 |
| 2 | 启用插件后登录，展开右上角「帮助」 | 菜单出现「团队文档」标题 + 记账规矩 / 中文操作指南 / 中文术语表 三项；核心原有条目（本页说明、知识库、提交缺陷、社区、联系我们、System）不变 |
| 3 | 点「记账规矩」 | 浮态弹框加载，**不整页跳转**；标题与正文为该文档内容 |
| 4 | 依次点另外两项 | 各自打开对应文档，内容不串 |
| 5 | 观察 Markdown 渲染 | 标题/列表/表格/代码块正常；表格不横向溢出 |
| 6 | 关闭弹框，再点同一项 | 正常重新打开，不出现嵌套弹框 |
| 7 | 浏览器后退 | 弹框关闭回到原页面（由 `modals.js` hash 流程接管） |
| 8 | 手工访问 `/hx/teamdocs/document/show/../config/.env` | 返回安全错误片段，不泄露服务器路径或文件内容 |
| 9 | 禁用插件 | 三个菜单项消失，核心帮助菜单不受影响 |

## 5. 常见问题

- **菜单里没有三个条目**
  依次确认：插件已启用（`php bin/leantime plugin:list` 看 `leantime/teamdocs` 是否 enabled）→
  清视图缓存 `rm -rf storage/framework/views/* && rm -f storage/framework/viewPaths.php` →
  确认核心 `headMenu.blade.php` 含 `@dispatchEvent('insideHelpMenu')`。

- **点开弹框报「这份团队文档暂时无法打开」**
  说明白名单文件在运行时缺失或不可读。检查镜像内 `app/Plugins/TeamDocs/Resources/docs/` 是否有三份 md；
  Railway 单卷模式下确认 entrypoint 同步日志没有报错。

- **想看的是最新文档但弹框是旧内容**
  根 `docs/` 改了但没同步到插件资源目录。跑上面的 `cp` + `php scripts/check-teamdocs-docs.php`，重新构建镜像。

- **为什么不让弹框直接读仓库根 `docs/`**
  `.dockerignore` 排除了 `docs/`，生产镜像里根本不存在该目录；且放开读取会让 endpoint 具备读任意文件的能力。

## 6. 边界与后续可做

- 当前三个 key 固定（`rules` / `guide` / `glossary`），不接受用户传路径；新增文档需在
  `Services/Documents.php` 的白名单里加一项并同步资源文件。
- Markdown 渲染配置为 `html_input: escape` + `allow_unsafe_links: false`，即文档里的原始 HTML
  不会被当作 HTML 执行。若将来需要在文档中嵌入 HTML，需要显式改配置并重新评估 XSS 风险。
- 若将来多个插件都要往帮助菜单加条目，可考虑在核心收敛成专门的帮助菜单扩展 API；
  目前用 `insideHelpMenu` 这一行通用事件已足够。
