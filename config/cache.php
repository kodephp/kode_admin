<?php

/*
 * 缓存配置（kode/cache）
 *
 * 支持 file / redis / memory（进程内）/ memcached / apcu / sqlite 等驱动。
 * 键名口径：这里写 `driver`，框架 CacheServiceProvider 会归一化成 kode/cache 认识的 `type`。
 * `array` 与 `memory` 等价（kode/cache >= 1.5.1 起 driverAliases 真正接线）。
 * 与 kode/session 不同：cache 的连接参数是**扁平**的（stores.redis.host…），
 * session 读的是**嵌套**的 drivers.redis.redis 段，两个包别互相抄。
 *
 * 路径一律由 __DIR__ 推出绝对路径：配置加载期 app() 尚未就绪，base_path()/storage_path()
 * 会退化成「相对当前工作目录」，FPM 下（cwd=public/）会把缓存写到 public/storage。
 */

return [
    'default' => env('CACHE_DRIVER', 'file'),

    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => dirname(__DIR__) . '/storage/cache',
        ],
        'memory' => [
            'driver' => 'memory',
        ],
        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD'),
            'database' => (int) env('REDIS_DB', 0),
            'prefix' => 'kode:cache:',
        ],
    ],
];
