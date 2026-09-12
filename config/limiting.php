<?php

/*
 * 限流配置（kode/limiting）
 *
 * driver   ：存储后端 memory | apcu | redis | memcached | pdo
 *            - memory：单进程内存（演示/单机可用，多进程不共享）
 *            - apcu  ：单机多进程共享（需 ext-apcu）
 *            - redis ：分布式共享（推荐生产，支持集群至多一次）
 *            - memcached / pdo：其他分布式后端
 * algorithm：令牌桶 token_bucket | 滑动窗口 sliding_window | 固定窗口 counter
 *            | 漏桶 leaky_bucket | 滑动窗口计数器 sliding_window_counter
 * capacity ：全局兜底额度（仅当 global.enabled=true 时对「未声明 #[RateLimit] 的路由」生效）
 * rate     ：令牌桶=每秒补充速率；滑动窗口/固定窗口=时间窗口秒数
 *
 * 规则与存储解耦：在路由/控制器上用 #[RateLimit] 声明「限制什么」，此处统一决定
 * 「状态存哪」。把 driver 改为 redis 即让所有限流（含 #[RateLimit]）变为分布式。
 */

return [
    // 总开关（默认关，opt-in：LIMITING_ENABLED=true 开启）。
    // 对标 webman 默认裸内核：限流中间件不默认进管道；开启后仅 #[RateLimit] 标记路由生效
    // （global.enabled 默认关，不兜底限流未标记路由）。
    'enabled' => (bool) env('LIMITING_ENABLED', false),
    'driver' => env('RATE_LIMIT_DRIVER', 'memory'),
    // 全局兜底限流：仅当显式开启（RATE_LIMIT_GLOBAL=true）时，对「未声明 #[RateLimit] 的路由」
    // 施加统一限额。默认关闭 —— 限流只作用于 #[RateLimit] 标记的路由，避免无意识地把整站
    // 压到极低额度（原默认 capacity=10/s 会真实限流生产流量）。
    'global' => [
        'enabled' => (bool) env('RATE_LIMIT_GLOBAL', false),
        'capacity' => (int) env('RATE_LIMIT_CAPACITY', 1000),
        'rate' => (float) env('RATE_LIMIT_RATE', 1.0),
        'algorithm' => env('RATE_LIMIT_ALGO', 'token_bucket'),
    ],
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD'),
        'database' => (int) env('REDIS_DB', 0),
        // 部署模式：standalone（默认）| sentinel（哨兵高可用）| cluster（分片）
        'mode' => env('REDIS_MODE', 'standalone'),
        // 哨兵模式：哨兵地址列表与 master 名称
        'sentinels' => env('REDIS_SENTINELS')
            ? explode(',', (string) env('REDIS_SENTINELS'))
            : ['127.0.0.1:26379'],
        'master_name' => env('REDIS_MASTER', 'mymaster'),
        // 集群模式：节点地址列表
        'cluster_nodes' => env('REDIS_CLUSTER_NODES')
            ? explode(',', (string) env('REDIS_CLUSTER_NODES'))
            : ['127.0.0.1:7000'],
        // 所有限流键前缀（便于在多业务共用 Redis 时隔离）
        'prefix' => env('REDIS_PREFIX', 'kode:limiting:'),
    ],
    // pdo 后端（可选）：需提供 dsn / 账号 / 表名
    'pdo' => [
        'dsn' => env('RATE_LIMIT_PDO_DSN', 'sqlite::memory:'),
        'username' => env('RATE_LIMIT_PDO_USER'),
        'password' => env('RATE_LIMIT_PDO_PASS'),
        'table' => env('RATE_LIMIT_PDO_TABLE', 'limiting'),
    ],
    // memcached 后端（可选，v1.0.0 补齐：旧实现误读 redis.host/port 且无本段，H6）
    'memcached' => [
        'host' => env('RATE_LIMIT_MEMCACHED_HOST', '127.0.0.1'),
        'port' => (int) env('RATE_LIMIT_MEMCACHED_PORT', 11211),
        // 限流键前缀（与 redis.prefix 同理，隔离多业务共用 Memcached 时的键空间）
        'prefix' => env('RATE_LIMIT_MEMCACHED_PREFIX', 'kode:limiting:'),
    ],
];
