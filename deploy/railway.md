# Railway 部署操作手册

> 本文是**线性操作手册**：从步骤 0 到步骤 11 依次执行即可。
> Railway 会在你改动配置时自动触发重新部署，所以会看到"首次部署失败 → 配置完成 → 再次部署成功"的过程，属正常现象。

Railway 上本项目的结构：

```
Railway 公网域名 ──> app 服务（用 deploy/Dockerfile.prod 构建）
                        │  单卷挂载到 /data（持久化 userfiles / storage / plugins）
                        ├──> PostgreSQL（Railway 托管）
                        └──> Redis（可选，Railway 托管）
```

> Railway 关键限制：**每个服务只能挂一个卷**。所以只挂一个卷到 `/data`，容器入口脚本 `deploy/entrypoint.sh` 会把需要持久化的目录软链进去（由 `LEAN_PERSIST_ROOT=/data` 启用）。

---

## 步骤 0：准备

- 本仓库已推送到 GitHub
- 已注册 Railway 账号（Hobby $5/月 或 Pro $20/月）
- 本地生成 session 密钥，记下输出，步骤 4 要用：

  Linux / macOS / Git Bash：

  ```bash
  openssl rand -hex 32
  ```

  Windows PowerShell（系统自带，无需 openssl）：

  ```powershell
  $b=New-Object byte[] 32;[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b);($b|ForEach-Object{$_.ToString('x2')}) -join ''
  ```

---

## 步骤 1：从 GitHub 创建项目

1. 打开 [railway.com](https://railway.com) 控制台 → **New Project**
2. 选择 **Deploy from GitHub repo** → 授权并选择本仓库
3. Railway 自动创建服务并**立即开始首次构建**

**预期结果**：首次部署在构建后运行失败（数据库变量尚未配置）。**这是正常的**，继续下一步，最后一步重新部署时会成功。

> 服务名默认是仓库名。下文"app 服务"都指这个服务。

---

## 步骤 2：添加 PostgreSQL

1. 在项目画布点 **+ New**（部分界面显示 **Create**）
2. 选择 **Database** → **PostgreSQL**
3. 等待数据库状态变为 running

**预期结果**：项目里多出一个数据库服务，名称默认 **`Postgres`**。记下这个名称——步骤 4 引用变量时要用；如果你的实例名称不同，把下文的 `Postgres` 换成实际名称。

---

## 步骤 3：生成域名并指定端口

1. 点开 app 服务 → **Settings** → **Networking**（或 **Public Networking**）
2. 点 **Generate Domain**
3. 端口填 **8080**（与容器内 nginx 一致）

**预期结果**：得到一个 `https://<名字>.up.railway.app` 域名。先做这一步，步骤 4 的 `LEAN_APP_URL` 才能引用到它。

> 此时不要配置 Healthcheck Path：安装完成前 `/` 会 302 跳转到 `/install`，可能被判定为不健康。安装完成后再按需添加。

---

## 步骤 4：给 app 服务设置变量

1. 点开 app 服务 → **Variables** 标签
2. 点 **Raw Editor**
3. 粘贴以下内容，把 `LEAN_SESSION_PASSWORD` 换成步骤 0 生成的值
4. 保存

```env
# 站点
LEAN_APP_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}
LEAN_SITENAME=Leantime
LEAN_LANGUAGE=zh-CN
LEAN_DEFAULT_TIMEZONE=Asia/Shanghai
LEAN_DEBUG=0
LEAN_LOG_CHANNELS=stderr
# 注意：不要设置 LEAN_TRUSTED_PROXIES=*（Symfony 不支持，会导致全部请求 403）。
# 默认值已信任 Railway 边缘代理，保持不设置即可。

# 数据库（引用 Postgres 服务变量；服务名不是 Postgres 时同步修改）
LEAN_DB_DEFAULT_CONNECTION=pgsql
LEAN_DB_HOST=${{Postgres.PGHOST}}
LEAN_DB_PORT=${{Postgres.PGPORT}}
LEAN_DB_DATABASE=${{Postgres.PGDATABASE}}
LEAN_DB_USER=${{Postgres.PGUSER}}
LEAN_DB_PASSWORD=${{Postgres.PGPASSWORD}}
LEAN_DB_SCHEMA=public
LEAN_DB_PERSISTENT_CONNECTIONS=true

# Session（务必固定，否则每次重部署都会掉登录）
LEAN_SESSION_PASSWORD=把步骤0生成的openssl_rand_hex_32填这里
LEAN_SESSION_EXPIRATION=480
LEAN_SESSION_SECURE=true

# 单卷持久化（配合步骤 5 挂载到 /data 的卷）
LEAN_PERSIST_ROOT=/data

# 让 Railway 的端口探测与容器内 nginx 监听端口一致
PORT=8080
```

**预期结果**：保存后 Railway 检测到变量变化，自动触发一次重新部署（但此时还没挂卷，先等它跑，或直接做步骤 5 后一起部署）。

---

## 步骤 5：挂载持久卷

1. 点开 app 服务 → **Settings** → **Volumes**（或右键服务 → **Attach Volume**）
2. 点 **Add Volume** / **Attach**
3. **Mount Path** 填 `/data`

**预期结果**：附加卷会触发一次重新部署。容器启动时 `entrypoint.sh` 会把下列目录软链到 `/data`：

| 容器内路径 | 卷内路径 |
| --- | --- |
| `/var/www/html/userfiles` | `/data/userfiles` |
| `/var/www/html/public/userfiles` | `/data/public_userfiles` |
| `/var/www/html/app/Plugins` | `/data/plugins` |
| `/var/www/html/storage` | `/data/storage` |

> 该卷同时保存 session/cache（除非启用 Redis），**不能删除**，否则会丢失上传文件与登录状态。

---

## 步骤 6：重新部署并等待成功

1. 点开 app 服务 → **Deployments** → 右上角 **Deploy**（触发一次全新部署，确保带上步骤 4/5 的配置）
2. 点开这次部署 → **View Logs** 观察构建与启动日志
3. 首次构建较慢（要编译 PHP 扩展并打包前端资源），耐心等待状态变为 **Success**

**预期结果**：部署状态变为 Success，日志中能看到 nginx / php-fpm / scheduler 启动。

---

## 步骤 7：完成首次安装

1. 浏览器打开：

   ```
   https://<步骤3的域名>/install
   ```

2. 安装向导会读取环境变量里的数据库信息（主机为 Railway 内网地址），创建管理员账号并建表

**预期结果**：安装完成后跳到登录页，用刚创建的管理员账号登录成功。

---

## 步骤 8：验证

1. 打开 `https://<域名>/` 能正常登录
2. 在 app 服务里确认后台调度进程在跑：

   Railway 控制台 → app 服务 → **View Logs**，应能看到 scheduler 的日志

3. 上传一张图片/附件，然后到步骤 6 的 **Deployments** 里重新部署一次，重新登录后确认文件还在

**预期结果**：重启后上传文件不丢，说明 `/data` 卷工作正常。

---

## 步骤 9（可选）：启用 Redis

Railway 托管 Redis 可用于 session 与缓存，避免把 session 写进卷：

1. 项目里点 **+ New** → **Database** → **Redis**
2. 在 app 服务 **Variables** 中追加（服务名不是 `Redis` 时同步修改）：

   ```env
   LEAN_USE_REDIS=true
   LEAN_REDIS_HOST=${{Redis.REDISHOST}}
   LEAN_REDIS_PORT=${{Redis.REDISPORT}}
   LEAN_REDIS_PASSWORD=${{Redis.REDISPASSWORD}}
   LEAN_REDIS_SCHEME=tcp
   LEAN_REDIS_SESSION_DB=1
   ```

   > `LEAN_REDIS_SCHEME` 必须设为 `tcp`：Leantime 默认值是 `tls`，而 Railway 内网 Redis 不启用 TLS，不设置会连不上。

**预期结果**：保存后自动重新部署，登录状态改由 Redis 保存。

---

## 步骤 10：备份

Railway 卷**不能**被第二个服务共享，因此无法像单机那样跑独立备份容器。可行方式：

1. **从 Railway 导出数据库**：Postgres 服务 → **Data** → 导出 SQL；卷内文件可通过 app 服务临时执行 `tar` 下载。
2. **改用 S3 保存上传文件**（推荐）：设置 `LEAN_USE_S3=true` 及 `LEAN_S3_*`，上传文件直接进对象存储，这样只有数据库需要备份。Leantime 内置支持。

> 无论哪种方式，都要定期导出数据库，这是唯一无法重建的数据。

---

## 步骤 11：后续更新

- 把改动 `git push` 到 Railway 关联分支 → 自动重新部署
- 也可在 **Deployments** 手动点 **Deploy**
- 带卷的服务重部署会有短暂停机（Railway 限制，正常现象）

---

## 成本参考

Railway 按**实际用量**秒级计费（2026 现行价）：CPU $20/vCPU/月、内存 $10/GB/月、卷 $0.15/GB/月、出站 $0.05/GB。Hobby $5/月、Pro $20/月，月费可抵扣用量，最终按 `max(月费, 用量)` 收费。

| 场景 | 预估月成本 |
| --- | --- |
| 低流量/内部工具（大部分空闲） | 约 $12–18 |
| 小团队活跃使用 | 约 $20–30 |
| 含独立 Redis + 较高流量 | 约 $35–50 |

> 建议先用 Hobby 跑一周，在 **Workspace → Usage** 查看实际用量再决定是否升级 Pro。

---

## 排错

| 现象 | 处理 |
| --- | --- |
| 构建失败，找不到 Dockerfile | 确认仓库根存在 `railway.json` 且 `dockerfilePath` 为 `deploy/Dockerfile.prod` |
| 首次部署失败 | 正常现象：数据库/变量尚未配置。完成步骤 4、5 后重新部署即可 |
| 部署成功但打不开 | 确认已 Generate Domain，且目标端口为 8080、变量 `PORT=8080` |
| 卷内写入权限错误 | 本镜像以 root 启动并 `chown`，一般不会遇到；若 Railway 强制非 root，添加变量 `RAILWAY_RUN_UID=0` |
| 登录后重部署掉线 | 确认 `LEAN_SESSION_PASSWORD` 已固定；建议按步骤 9 启用 Redis |
| 数据库连不上 | 确认变量引用的服务名与 Postgres 服务名一致，且 `LEAN_DB_DEFAULT_CONNECTION=pgsql` |
| 首次部署健康检查失败 | 不要配置 Healthcheck Path，等安装完成后再加 |
