<?php

/*
 * JWT 配置（kode/jwt）
 *
 * 与 kode/jwt 配置结构对齐；使用 HS256 时必须提供非空 secret。
 */

return [
    'defaults' => [
        'guard' => 'api',
        'storage' => 'memory',
    ],

    'guards' => [
        'api' => [
            'driver' => 'sso',
            // 黑名单存储：多 worker 下必须用 redis（memory 只隔离单进程，revoke/refresh 跨进程失效）。
            'storage' => env('JWT_STORAGE', 'redis'),
            'algo' => env('JWT_ALGO', 'HS256'),
            // 安全（v1.0.0）：不再内置明文兜底密钥——公开可知的默认密钥等于任何人可自签
            // 令牌通过认证。secret 缺失时 JwtGuard 构造即抛异常（启动期 fail-fast）。
            'secret' => env('JWT_SECRET', ''),
            'ttl' => (int) env('JWT_TTL', 3600),
            'refresh_ttl' => 604800,
            'blacklist_enabled' => true,
            'blacklist_ttl' => 604800,
            // SSO 守卫要求：签发与校验都必须携带 platform 声明
            'platform' => env('JWT_PLATFORM', 'web'),
            'clock_skew' => 30,
            'expected_claims' => [],
        ],
    ],

    'storage' => [
        'memory' => ['driver' => 'memory'],
        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD'),
            'database' => (int) env('REDIS_DB', 0),
            'prefix' => 'kode:jwt:',
        ],
    ],
];
