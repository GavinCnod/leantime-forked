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
# 内置插件同步：
#   镜像把随源码发布的插件快照放在 /opt/leantime/plugins.builtin。
#   因为 app/Plugins 会被命名卷或 /data 软链遮蔽（卷首次初始化为空后
#   不再跟随镜像更新），每次启动把快照幂等同步进运行时插件目录：
#   只新增/覆盖同名内置插件，不删除市场安装的其他插件。
#
# 自动启用插件（Railway 等无法 docker exec 的平台）：
#   设置 LEAN_PLUGINS_ENABLE=mindrose/costtracking,leantime/xxx，
#   启动 web 前幂等安装并启用（未注册才装、未启用才启），带数据库等待重试。
#
set -e

PERSIST_ROOT="${LEAN_PERSIST_ROOT:-}"
BUILTIN_PLUGINS="/opt/leantime/plugins.builtin"
PLUGIN_ENSURE="/opt/leantime/plugin_ensure.php"

link_into_persist() {
    # $1 = 容器内路径；$2 = PERSIST_ROOT 下的子目录名
    path="$1"
    name="$2"
    mkdir -p "${PERSIST_ROOT}/${name}"
    rm -rf "${path}"
    mkdir -p "$(dirname "${path}")"
    ln -sfn "${PERSIST_ROOT}/${name}" "${path}"
}

# $1 = 运行时插件目录（命名卷挂载点或 /data/plugins）
sync_builtin_plugins() {
    target="$1"
    [ -d "${BUILTIN_PLUGINS}" ] || return 0
    mkdir -p "${target}"
    echo "[entrypoint] syncing built-in plugins into ${target}"
    for src in "${BUILTIN_PLUGINS}"/*; do
        [ -d "${src}" ] || continue
        name="$(basename "${src}")"
        mkdir -p "${target}/${name}"
        # 覆盖同名内置插件的文件，保留目标目录内其他插件与新增文件
        cp -a "${src}/." "${target}/${name}/"
    done
}

# 幂等安装/启用 LEAN_PLUGINS_ENABLE 列出的插件，等待数据库就绪
ensure_plugins() {
    [ -n "${LEAN_PLUGINS_ENABLE}" ] || return 0
    [ -f "${PLUGIN_ENSURE}" ] || {
        echo "[entrypoint] WARNING: LEAN_PLUGINS_ENABLE set but ${PLUGIN_ENSURE} missing"
        return 0
    }

    echo "[entrypoint] ensuring plugins: ${LEAN_PLUGINS_ENABLE}"
    i=0
    while ! php "${PLUGIN_ENSURE}" "${LEAN_PLUGINS_ENABLE}"; do
        i=$((i + 1))
        if [ "${i}" -ge 30 ]; then
            echo "[entrypoint] WARNING: plugin ensure failed after ${i} attempts, continuing startup"
            return 0
        fi
        echo "[entrypoint] database/plugins not ready (attempt ${i}), retrying in 2s..."
        sleep 2
    done
    echo "[entrypoint] plugins ensured"
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

    sync_builtin_plugins "${PERSIST_ROOT}/plugins"
else
    sync_builtin_plugins /var/www/html/app/Plugins
fi

# 必须在修正属主之前：脚本会写 storage 缓存，root 写入的文件随后统一 chown
ensure_plugins

if [ -n "${PERSIST_ROOT}" ] && [ -d "${PERSIST_ROOT}" ]; then
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
