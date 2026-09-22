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
            // 黑名单条目寿命由代码按 ttl + refresh_ttl 推导（SsoGuard），配 blacklist_ttl 不生效：
            // 写了会被误解为「能单独调」，故此处不再声明。
            // SSO 守卫要求：签发与校验都必须携带 platform 声明
            'platform' => env('JWT_PLATFORM', 'web'),
            'clock_skew' => 30,
            'expected_claims' => [],
        ],
    ],

    // 存储段：StorageFactory 按「键名」派发（storage.redis → RedisStorage），键里的 driver
    // 从来没人读；但配置为空数组会抛 "Storage 'x' not found"，所以每段至少留一个真实键。
    'storage' => [
        // memory 可选 limit / blacklist_limit（MemoryStorage），缺省即够用。
        'memory' => ['limit' => 10000],
        // 连接参数是扁平的（RedisStorage 直接读 host/port/password/database/prefix/timeout），
        // 与 kode/session 的嵌套写法不同，别照搬。
        'redis' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD'),
            'database' => (int) env('REDIS_DB', 0),
            'prefix' => 'kode:jwt:',
        ],
    ],
];
