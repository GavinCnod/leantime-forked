#!/usr/bin/env php
<?php

/**
 * 幂等安装并启用指定插件（供容器 entrypoint 在启动 web 服务前调用）。
 *
 * 为什么需要它：
 *   Railway 等平台没有 `docker exec` 入口，无法在容器启动后手工执行
 *   `php bin/leantime plugin:install/enable`；而直接重复执行 install
 *   会向 zp_plugins 插入重复行（addPlugin 不去重）。本脚本按 composer
 *   包名幂等处理：未注册才 install，未启用才 enable，重复执行无副作用。
 *
 * 用法：
 *   php plugin_ensure.php leantime/costtracking,leantime/other
 *   php plugin_ensure.php leantime/costtracking leantime/other
 *
 * 退出码：全部成功 0；任一失败 1（供 entrypoint 重试与日志判定）。
 */

define('LEAN_CLI', true);
define('ARTISAN_BINARY', 'bin/leantime');

// 镜像中本脚本位于 /opt/leantime/plugin_ensure.php，应用根目录固定为
// /var/www/html；允许用 LEAN_APP_ROOT 覆盖以便非容器环境复用。
$appRoot = getenv('LEAN_APP_ROOT') ?: '/var/www/html';
chdir($appRoot);

require $appRoot . '/vendor/autoload.php';

$app = require $appRoot . '/bootstrap/app.php';

/** @var \Illuminate\Foundation\Console\Kernel $kernel */
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rawArgs = array_slice($argv, 1);
$packageNames = array_values(array_filter(
    array_map('trim', preg_split('/[\s,]+/', implode(',', $rawArgs)) ?: []),
    fn ($v) => $v !== ''
));

if ($packageNames === []) {
    fwrite(STDERR, "usage: php plugin_ensure.php vendor/package[,vendor/package2 ...]\n");
    exit(1);
}

/**
 * 扫描 app/Plugins/*\/composer.json，建立 composer 包名 => 插件目录名 映射。
 */
$discoverFolders = function (): array {
    $map = [];
    $pluginsRoot = app_path('Plugins');
    foreach (glob($pluginsRoot . '/*/composer.json') ?: [] as $manifest) {
        $json = json_decode((string) file_get_contents($manifest), true);
        if (!is_array($json) || empty($json['name']) || !is_string($json['name'])) {
            continue;
        }
        $map[$json['name']] = basename(dirname($manifest));
    }
    return $map;
};

$db = app('db');
$plugins = app(\Leantime\Domain\Plugins\Services\Plugins::class);
$folders = $discoverFolders();

$failed = false;

foreach ($packageNames as $package) {
    try {
        $row = $db->table('zp_plugins')->where('name', $package)->first();

        if ($row === null) {
            if (!isset($folders[$package])) {
                fwrite(STDERR, sprintf("[plugin:ensure] %s: not found under app/Plugins (sync failed?)\n", $package));
                $failed = true;
                continue;
            }

            $folder = $folders[$package];
            $newId = $plugins->installPlugin($folder);

            if ($newId === false) {
                fwrite(STDERR, sprintf("[plugin:ensure] %s: install failed (see application log)\n", $package));
                $failed = true;
                continue;
            }

            echo sprintf("[plugin:ensure] %s: installed (folder=%s, id=%s)\n", $package, $folder, $newId);
            $row = $db->table('zp_plugins')->where('name', $package)->first();
        } else {
            echo sprintf("[plugin:ensure] %s: already registered (id=%s)\n", $package, $row->id);
        }

        if ((int) $row->enabled !== 1) {
            $ok = $plugins->enablePlugin((int) $row->id);
            if (!$ok) {
                fwrite(STDERR, sprintf("[plugin:ensure] %s: enable failed\n", $package));
                $failed = true;
                continue;
            }
            echo sprintf("[plugin:ensure] %s: enabled\n", $package);
        } else {
            echo sprintf("[plugin:ensure] %s: already enabled\n", $package);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf("[plugin:ensure] %s: error: %s\n", $package, $e->getMessage()));
        $failed = true;
    }
}

exit($failed ? 1 : 0);
