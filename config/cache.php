<?php

/*
 * 缓存配置（kode/cache）
 *
 * 支持 file / redis / memcached / apcu / sqlite 等多种驱动。
 */

return [
    'default' => env('CACHE_DRIVER', 'file'),

    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => base_path('storage/cache'),
        ],
        'array' => [
            'driver' => 'array',
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
