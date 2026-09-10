# 腾讯云轻量应用服务器部署指南

本指南用于把本仓库（Leantime fork）以 Docker Compose 的方式部署到**腾讯云轻量应用服务器（Lighthouse）**，单机运行：

```
浏览器 ──HTTPS──> Caddy(80/443) ──HTTP──> app(nginx + php-fpm + Laravel scheduler, :8080)
                                             │
                                             ├──> PostgreSQL (:5432)
                                             └──> Redis (:6379)
```

所有组件跑在同一台服务器、同一个 Compose 项目里。

> 本指南只新增文件，不改动仓库已有代码。相关文件都在 `deploy/` 目录：
> - `deploy/Dockerfile.prod` —— 从源码构建生产镜像
> - `deploy/entrypoint.sh` —— 容器入口（修正挂载卷权限）
> - `deploy/docker-compose.prod.yml` —— 生产编排
> - `deploy/Caddyfile` —— 反向代理与自动 HTTPS
> - `deploy/.env.prod.example` —— 环境变量模板
> - `deploy/backup.sh` —— 备份脚本
> - `.dockerignore` —— 构建时排除无关文件

---

## 1. 前置条件

| 项目 | 建议 |
| --- | --- |
| 套餐 | 「锐驰型 2核4G / 60G SSD / 200Mbps 无限流量」约 72 元/月；预算有限可用活动套餐 2核4G 5M |
| 系统 | Ubuntu 24.04 LTS（全新安装） |
| 域名 | 已解析 A 记录到服务器公网 IP；国内地域需完成 ICP 备案 |
| 内存 | 2GB 的机器**务必**按 3.3 节创建 swap；4GB 建议创建 |

---

## 2. 网络与安全组

登录轻量控制台 → 服务器 → **防火墙**，放行：

- `22`（SSH，建议把来源限制为你的固定 IP）
- `80`、`443`（Web）

其余端口一律关闭。SSH 建议使用密钥登录并禁用密码认证。

---

## 3. 服务器初始化

### 3.1 更新系统

```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install curl git ufw fail2ban
```

### 3.2 安装 Docker 与 Compose 插件

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"
newgrp docker          # 或者退出重新登录
docker compose version # 应输出 v2.x
```

国内拉镜像慢，可配置镜像加速器 `/etc/docker/daemon.json`：

```json
{
  "registry-mirrors": ["https://docker.m.daocloud.io"],
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
```

```bash
sudo systemctl restart docker
```

### 3.3 创建 swap（2GB 机器必做）

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
free -h   # 确认 swap 已生效
```

### 3.4 主机防火墙

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80,443/tcp
sudo ufw --force enable
```

---

## 4. 获取代码与准备配置

```bash
sudo mkdir -p /opt/leantime && sudo chown "$USER" /opt/leantime
git clone <你的 fork 仓库地址> /opt/leantime
cd /opt/leantime

# 生成配置模板
cp deploy/.env.prod.example deploy/.env

# 生成随机密钥（把输出填进 .env）
echo "LEAN_SESSION_PASSWORD=$(openssl rand -hex 32)"
echo "LEAN_DB_PASSWORD=$(openssl rand -hex 24)"
echo "LEAN_REDIS_PASSWORD=$(openssl rand -hex 24)"
```

> 以上命令在服务器（Linux）上执行，`openssl` 已预装。若想在 Windows 本地先生成再粘贴，用 PowerShell：
>
> ```powershell
> $b=New-Object byte[] 32;[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b);($b|ForEach-Object{$_.ToString('x2')}) -join ''
> ```
>
> （把 `32` 改成 `24` 即可生成 24 字节的数据库/Redis 密码。）

编辑 `deploy/.env`：

```bash
nano deploy/.env
```

至少要改：

- `LEAN_APP_URL` → 你的 `https://域名`
- `LEAN_DB_PASSWORD` / `LEAN_SESSION_PASSWORD` / `LEAN_REDIS_PASSWORD` → 上面生成的值
- `LEAN_DEFAULT_TIMEZONE`、`LEAN_LANGUAGE`、`LEAN_SITENAME`

编辑 `deploy/Caddyfile`，把示例域名替换为你的域名：

```
leantime.example.com {
	encode gzip
	reverse_proxy app:8080
}
```

---

## 5. 构建并启动

```bash
cd /opt/leantime/deploy
docker compose -f docker-compose.prod.yml up -d --build
```

查看启动日志（首次构建较慢，需编译 PHP 扩展并打包前端资源）：

```bash
docker compose -f docker-compose.prod.yml logs -f app
```

确认三个进程都在跑（nginx / php-fpm / scheduler）：

```bash
docker compose -f docker-compose.prod.yml exec app ps aux
```

---

## 6. 首次安装

1. 浏览器访问 `https://你的域名/install`
2. 向导会读取 `.env` 中的数据库信息，创建管理员账号并建表
3. 完成后访问 `https://你的域名` 登录

> 若 HTTPS 证书尚未签发成功，先确认域名已解析到本机、安全组已放行 80/443，然后：
> `docker compose -f docker-compose.prod.yml logs caddy`

---

## 7. 备份

备份数据库 + 上传文件。脚本位于 `deploy/backup.sh`：

```bash
chmod +x /opt/leantime/deploy/backup.sh
BACKUP_DIR=/opt/leantime-backup /opt/leantime/deploy/backup.sh
```

设置每天凌晨 3 点自动备份：

```bash
sudo crontab -e
# 加入下面一行
0 3 * * * /opt/leantime/deploy/backup.sh >> /var/log/leantime-backup.log 2>&1
```

同时在**腾讯云控制台**为该云硬盘开启**自动快照**（每天一次，保留 7 天），做第二层保护。

---

## 8. 更新

```bash
cd /opt/leantime
git pull
cd deploy
docker compose -f docker-compose.prod.yml up -d --build app
```

访问站点，`Updated` 中间件检测到数据库版本落后时会提示升级，按提示执行即可。

---

## 9. 运维与排错

### 查看状态与日志

```bash
cd /opt/leantime/deploy
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs -f --tail=200 app
docker stats
```

### 常见问题

| 现象 | 排查方向 |
| --- | --- |
| 首次页面加载慢 | 前端资源约 8MB。锐驰型 200Mbps 无压力；普通 3–5Mbps 套餐建议把 `public/dist`、`public/theme` 挂到腾讯云 CDN |
| 登录后一重启就掉线 | `LEAN_SESSION_PASSWORD` 未固定，或 Redis 未连通。确认 `.env` 中已设固定值且 `LEAN_USE_REDIS=true` |
| 上传文件失败 | 确认 `userfiles`、`public/userfiles` 卷可写。`docker compose ... exec app sh -c 'ls -ld userfiles public/userfiles'` 应属 `www-data` |
| 证书申请失败 | 域名解析、80/443 放行、Caddy 日志三处依次确认 |
| 内存吃紧 | Postgres 调低 `shared_buffers`；PHP-FPM 调小 `.docker/config/php-fpm.conf` 的 `pm.max_children`；确认 swap 已启用 |
| 构建时 composer 报 license 错误 | 在 `deploy/Dockerfile.prod` 的 `composer install` 后追加 `--no-plugins` 重新构建 |

### 安全加固

- SSH 仅密钥登录，禁用 root 密码登录
- 开启自动安全更新：`sudo apt -y install unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades`
- 定期清理：`docker system prune -f`
- 在腾讯云控制台配置告警：CPU > 80%、内存 > 85%、磁盘 > 85%

---

## 10. 改用 MySQL（可选）

PostgreSQL 在低负载下更省内存（详见仓库根 `CLAUDE.md` 与部署讨论）。若坚持用 MySQL：

1. 编辑 `deploy/docker-compose.prod.yml`，用文件末尾注释里的 `db`（MySQL）替换默认的 Postgres `db`。
2. 编辑 `deploy/.env`，改用 MySQL 段：

   ```env
   LEAN_DB_DEFAULT_CONNECTION=mysql
   LEAN_DB_PORT=3306
   MYSQL_ROOT_PASSWORD=<强密码>
   ```

3. 重新启动：

   ```bash
   cd /opt/leantime/deploy
   docker compose -f docker-compose.prod.yml up -d --build
   ```
