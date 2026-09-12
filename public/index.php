<?php

/*
 * HTTP 入口
 *
 * 本地开发：composer serve  （等价于 php -S 127.0.0.1:8000 -t public public/index.php）
 * 生产常驻：配合 Swoole / Workerman，kode/http 会自动检测并切换运行模式。
 */

use Kode\Framework\Application;

require __DIR__ . '/../vendor/autoload.php';

$app = Application::make(basePath: dirname(__DIR__));

$app->http()->run();
