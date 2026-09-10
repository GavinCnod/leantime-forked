#!/usr/bin/env bash
#
# Leantime 备份脚本（Docker Compose 部署）
#
# 备份内容：数据库 + userfiles + public/userfiles
# 用法：
#   chmod +x deploy/backup.sh
#   ./deploy/backup.sh
# 定时（每天凌晨 3 点）：
#   sudo crontab -e
#   0 3 * * * /opt/leantime/deploy/backup.sh >> /var/log/leantime-backup.log 2>&1
#
# 可通过环境变量覆盖：
#   BACKUP_DIR=/opt/leantime-backup   # 备份存放目录
#   RETENTION_DAYS=14                 # 保留天数
#
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${BACKUP_DIR:-/opt/leantime-backup}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
STAMP="$(date +%F_%H%M%S)"
COMPOSE=(docker compose -f "${DEPLOY_DIR}/docker-compose.prod.yml")

if [ ! -f "${DEPLOY_DIR}/.env" ]; then
    echo "错误：找不到 ${DEPLOY_DIR}/.env" >&2
    exit 1
fi

# 读取部署变量
set -a
# shellcheck disable=SC1091
source "${DEPLOY_DIR}/.env"
set +a

mkdir -p "${BACKUP_DIR}"

echo "[$(date '+%F %T')] 备份数据库 (${LEAN_DB_DEFAULT_CONNECTION:-mysql}) ..."
if [ "${LEAN_DB_DEFAULT_CONNECTION:-mysql}" = "pgsql" ]; then
    "${COMPOSE[@]}" exec -T db \
        pg_dump -U "${LEAN_DB_USER}" "${LEAN_DB_DATABASE}" \
        | gzip > "${BACKUP_DIR}/db_${STAMP}.sql.gz"
else
    "${COMPOSE[@]}" exec -T -e MYSQL_PWD="${LEAN_DB_PASSWORD}" db \
        mysqldump -u "${LEAN_DB_USER}" --single-transaction --routines --triggers "${LEAN_DB_DATABASE}" \
        | gzip > "${BACKUP_DIR}/db_${STAMP}.sql.gz"
fi

echo "[$(date '+%F %T')] 备份 userfiles ..."
"${COMPOSE[@]}" run --rm -T --no-deps --entrypoint sh app \
    -c "tar czf - -C /var/www/html/userfiles ." \
    > "${BACKUP_DIR}/userfiles_${STAMP}.tar.gz"

echo "[$(date '+%F %T')] 备份 public userfiles ..."
"${COMPOSE[@]}" run --rm -T --no-deps --entrypoint sh app \
    -c "tar czf - -C /var/www/html/public/userfiles ." \
    > "${BACKUP_DIR}/public_userfiles_${STAMP}.tar.gz"

echo "[$(date '+%F %T')] 清理 ${RETENTION_DAYS} 天前的备份 ..."
find "${BACKUP_DIR}" -type f -mtime "+${RETENTION_DAYS}" -delete

echo "[$(date '+%F %T')] 完成。备份目录：${BACKUP_DIR}"
