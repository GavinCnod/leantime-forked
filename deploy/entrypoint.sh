#!/bin/sh
#
# Leantime 生产容器入口脚本。
#
# 两种持久化模式：
#   1) Compose 模式（默认）：命名卷直接挂载到应用目录，这里只修正属主。
#   2) 单卷模式（Railway）：Railway 每个服务只允许一个卷，挂载到 /data，
#      由本脚本把需要持久化的目录软链到 /data 下。启用方式：
#        LEAN_PERSIST_ROOT=/data
#
set -e

PERSIST_ROOT="${LEAN_PERSIST_ROOT:-}"

link_into_persist() {
    # $1 = 容器内路径；$2 = PERSIST_ROOT 下的子目录名
    path="$1"
    name="$2"
    mkdir -p "${PERSIST_ROOT}/${name}"
    rm -rf "${path}"
    mkdir -p "$(dirname "${path}")"
    ln -sfn "${PERSIST_ROOT}/${name}" "${path}"
}

if [ -n "${PERSIST_ROOT}" ] && [ -d "${PERSIST_ROOT}" ]; then
    echo "[entrypoint] 单卷模式：持久化根目录 ${PERSIST_ROOT}"
    mkdir -p \
        "${PERSIST_ROOT}/storage/logs" \
        "${PERSIST_ROOT}/storage/framework/cache" \
        "${PERSIST_ROOT}/storage/framework/sessions" \
        "${PERSIST_ROOT}/storage/framework/views" \
        "${PERSIST_ROOT}/storage/app"
    link_into_persist /var/www/html/userfiles            userfiles
    link_into_persist /var/www/html/public/userfiles     public_userfiles
    link_into_persist /var/www/html/app/Plugins          plugins
    link_into_persist /var/www/html/storage              storage

    # 卷根目录可能属于 root，直接对真实路径授权（-R 默认不跟随软链）
    chown -R www-data:www-data "${PERSIST_ROOT}" 2>/dev/null || true
    chmod -R 775 "${PERSIST_ROOT}" 2>/dev/null || true
fi

# 修正挂载目录属主（首次挂载的卷可能属于 root）
for d in \
    /var/www/html/userfiles \
    /var/www/html/public/userfiles \
    /var/www/html/app/Plugins \
    /var/www/html/storage
do
    if [ -e "${d}" ]; then
        chown -R www-data:www-data "${d}" 2>/dev/null || true
        chmod -R 775 "${d}" 2>/dev/null || true
    fi
done

exec /start.sh
