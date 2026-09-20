# TeamDocs 插件 — 部署与验证

> 生成日期：2026-09-19 ｜ 最近更新：2026-09-20
> 插件版本：1.1.0 ｜ 包名：`leantime/teamdocs` ｜ 目录：`app/Plugins/TeamDocs`
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

核心侧改动：

- `app/Domain/Menu/Templates/headMenu.blade.php` 在帮助下拉菜单里新增一行
  `@dispatchEvent('insideHelpMenu')`（通用扩展点，不知道 TeamDocs 的存在）。
- `app/Core/Support/MarkdownRenderer.php`（1.1.0 新增）—— 通用 Markdown → HTML 渲染器。
  插件通过构造注入使用它，不再自己 new converter。渲染器是**核心类**，任何需要展示
  作者手写 Markdown 的地方都可以复用。
- `public/assets/css/components/markdown.css`（1.1.0 新增）—— `.md-body` 排版，渲染器输出的
  标题/列表/表格/引用/callout/脚注/TOC 全靠它。
- `public/assets/less/markdown-modal.less`（1.1.0 新增）—— TeamDocs 弹框外壳（标题栏 + 高度控制）。

`main.less` 里两行 `@import` 把上面两个样式表并入全站 `main.css`，**插件不加载任何自有 CSS**。
详见第 6 节。

另有 `composer.json` 显式声明 `league/commonmark` 依赖（锁文件已同步 content-hash）。

## 2. 文档来源与同步（重要）

仓库根 `docs/` 是**团队可编辑的唯一事实来源**；`app/Plugins/TeamDocs/Resources/docs/` 是**打包进镜像的运行时快照**。
生产 `.dockerignore` 排除了根 `docs/`，因此运行时**不能**读根目录文档。

改文档的流程：

```bash
# 1. 改根目录三份文档
# 2. 同步到插件资源目录，并校验（一条命令搞定；不带 --sync 则只校验）
php scripts/check-teamdocs-docs.php --sync
# 3. 两边一起提交
```

`--sync` 先全部复制再逐份校验，任一份读不到就**一份都不复制**并退出 1，不会留下半同步状态。

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
| 5 | 看弹框是否超出屏幕 | 弹框高度止于视口内，**长文档在弹框内部滚动**，滚到底能看到文末；标题栏始终可见 |
| 6 | 点开「记账规矩」，点正文里的「中文操作指南」链接 | 在同一弹框内切换到该文档（交叉文档链接被改写，不是死链） |
| 7 | 看 Markdown 渲染 | 见下方「Markdown 渲染清单」 |
| 8 | 关闭弹框，再点同一项 | 正常重新打开，不出现嵌套弹框 |
| 9 | 浏览器后退 | 弹框关闭回到原页面（由 `modals.js` hash 流程接管） |
| 10 | 手工访问 `/hx/teamdocs/document/show/../config/.env` | 返回安全错误片段，不泄露服务器路径或文件内容 |
| 11 | 手工访问 `/hx/teamdocs/document/show/unknown` | 返回 404 |
| 12 | 禁用插件 | 三个菜单项消失，核心帮助菜单不受影响 |

### Markdown 渲染清单（用例 7 展开）

1.1.0 起三份文档用了 GFM 语法，逐项确认：

| 元素 | 在哪份文档 | 期望 |
|---|---|---|
| 表格 | 记账规矩(1 张) / 术语表(4 张) | 有边框、表头底色；**列宽贴合内容**，不撑满弹框；窄屏时表格自身横向滚动，弹框不跟着横向滚 |
| callout | 记账规矩("注意"×2/"警告"/"重要")、指南("注意"×2/"警告")、术语表("注意"×2/"提示") | 左侧彩色竖条 + 顶部中文类型标签，颜色按类型区分 |
| 目录(TOC) | 记账规矩 / 指南（术语表**没有**，它是扁平参照表） | 弹框顶部带边框的目录块；点任一条目跳到对应标题 |
| 标题锚点 | 全部 | 鼠标悬停标题时右侧淡出 `#`；点它把地址跳到该节的锚点 |
| 交叉文档链接 | 全部（记账规矩 2 条 / 指南 2 条 / 术语表 3 条） | 虚线下划线（区别于外链）；点击在弹框内切换文档 |
| 引号块 | 全部 | 左侧竖条 + 浅底，**不是斜体**（中文斜体不可读） |
| 行内代码 | 全部 | 等宽字体 + 浅底 + accent 色 |

深色主题（右上角切换）下再走一遍表格、callout、TOC：文字与底色对比度要够，彩色竖条不应糊在深底上。

## 5. 常见问题

- **菜单里没有三个条目**
  依次确认：插件已启用（`php bin/leantime plugin:list` 看 `leantime/teamdocs` 是否 enabled）→
  清视图缓存 `rm -rf storage/framework/views/* && rm -f storage/framework/viewPaths.php` →
  确认核心 `headMenu.blade.php` 含 `@dispatchEvent('insideHelpMenu')`。

- **点开弹框报「这份团队文档暂时无法打开」**
  说明白名单文件在运行时缺失或不可读。检查镜像内 `app/Plugins/TeamDocs/Resources/docs/` 是否有三份 md；
  Railway 单卷模式下确认 entrypoint 同步日志没有报错。

- **想看的是最新文档但弹框是旧内容**
  根 `docs/` 改了但没同步到插件资源目录。跑 `php scripts/check-teamdocs-docs.php --sync`，重新构建镜像。

- **弹框排版不对（表格没边框、callout 没颜色、目录样式丢失）**
  说明 `main.css` 里没有新的样式表。确认 `public/dist/css/main.*.min.css` 里含 `.md-body`，
  没有就 `npx mix` 重新构建；有但页面还是旧的，清视图缓存后强制刷新（CSS 带 `?v=` 版本戳，
  一般刷新即可）。

- **为什么不让弹框直接读仓库根 `docs/`**
  `.dockerignore` 排除了 `docs/`，生产镜像里根本不存在该目录；且放开读取会让 endpoint 具备读任意文件的能力。

## 6. 边界与后续可做

- 当前三个 key 固定（`rules` / `guide` / `glossary`），不接受用户传路径；新增文档需在
  `Services/Documents.php` 的白名单里加一项并同步资源文件。
- Markdown 渲染由核心 `MarkdownRenderer` 负责，默认 `html_input: escape` +
  `allow_unsafe_links: false`，即文档里的原始 HTML 会被**当文本显示**、`javascript:` 等
  危险协议链接会被降级为纯文本。若将来需要在文档中嵌入 HTML，需要显式改配置并重新评估
  XSS 风险。
- **样式为什么不放在插件里**：插件自带样式表需要自己的路由，而 URL 里带点和扩展名会被
  Leantime 的 Frontcontroller 当方法名解析（`markdown.css` → controller=`stylesheet`、
  method=`markdown`、id=`css`），得额外加 Laravel 路由绕开。而且渲染器本身是核心类，
  样式跟着核心 CSS 走更自然，也省一次请求。`main.css` 全站加载，插件因此不需要
  注册任何 CSS 事件。
- **为什么没有走 Tailwind**：本 fork 的 Tailwind 管线已失效——`webpack.mix.js` 里的
  `.tailwind()` 入口 `public/assets/less/app.less` 只剩 `@tailwind` 指令，编译后是空串，
  Tailwind 拿到空输入什么都不输出。另外 `tailwind.config.js` 的 `prefix: 'tw-'` 会把
  `addComponents` 的类改写成 `.tw-md-body`，与运行时生成的 HTML 对不上；而 `.md-body`
  这类类名只出现在运行时渲染的字符串里，构建期的 content 扫描本来就抓不到。
  **不要再往 `tailwind.config.js` 加样式**，它不会影响任何产物。
- 若将来多个插件都要往帮助菜单加条目，可考虑在核心收敛成专门的帮助菜单扩展 API；
  目前用 `insideHelpMenu` 这一行通用事件已足够。

### 样式改动注意

`markdown.css` 靠主题 token 取色，改动前先看文件顶部的「Theme contract」注释块，那里列了
渲染器输出依赖的全部 token，并标注了两个坑：

- `--orange` 在**四个内置主题里都没有定义**，warning callout 一直走字面量回退色；想改颜色要么
  给主题加这个 token，要么改回退值。
- `--primary-font-color` / `--secondary-font-color` 在浅色和深色主题里**语义相反**（深色主题下
  secondary 是低对比那个）。所以正文类元素一律继承 body 颜色，不要点名 token。

改完样式需要重新构建并清缓存：

```bash
npx mix                                                              # 产出 public/dist/css/main.*.min.css
rm -rf storage/framework/views/* && rm -f storage/framework/viewPaths.php
```

改 Blade 模板（如 `documentModal.blade.php`）只清缓存即可，不需要重新构建。
