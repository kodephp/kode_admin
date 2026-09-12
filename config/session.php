<?php

declare(strict_types=1);

/**
 * 会话配置（kode/session，薄壳委托）
 *
 * 框架此前装了 kode/session 却没有任何 ServiceProvider / 中间件接线，会话能力「静默失接」。
 * 本文件 + SessionServiceProvider 把会话接进生命周期：默认文件驱动 + SessionMiddleware
 * 自动启停会话，业务侧用 session() 助手读写（见 src/Support/helpers.php）。
 *
 * 驱动：file（默认）/ array（测试）/ redis / cookie / database。
 */

return [
    // 是否启用会话中间件（关闭则 session() 返回 null，不启停会话）。
    'enabled' => env('SESSION_ENABLED', false),

    // 默认驱动名（对应 drivers.* 的键）。
    'default' => env('SESSION_DRIVER', 'file'),

    // Cookie 相关（SessionMiddleware 写出 Set-Cookie 时使用）。
    // 注意：不要在此放顶层 'path' 键 —— kode/session 会把整个 session 配置透传给驱动工厂，
    // 顶层 path（Cookie 路径）会覆盖 drivers.file.path（存储目录）导致锁目录解析成 '/locks'。
    // Cookie 路径由 FileDriver/SessionMiddleware 默认取 '/'。
    'name' => env('SESSION_NAME', 'KODE_SESSION'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),        // 分钟
    'domain' => env('SESSION_DOMAIN', null),
    'secure' => env('SESSION_SECURE', false),
    'http_only' => true,
    'samesite' => env('SESSION_SAMESITE', 'Lax'),

    // 会话 ID 来源（v1.0.0 安全默认：仅 cookie）。query / body / header 载体允许
    // 客户端自选 ID 且易经 URL 泄露，属会话固定攻击面；确需（如无 cookie 客户端）
    // 再显式开启：['cookie', 'query', 'body', 'header']。
    'id_sources' => ['cookie'],

    // 垃圾回收概率（与 divisor 比值，命中才 GC，避免每次请求都扫）。
    'gc_probability' => 1,
    'gc_divisor' => 100,
    'gc_lifetime' => (int) env('SESSION_LIFETIME', 120) * 60,

    // 各驱动配置（驱动类按 drivers[<name>] 读取）。
    // 注意：此处不可调用 storage_path()/base_path() —— config 加载时机 app() 尚未就绪，
    // 会退化成相对路径导致 FileDriver 锁目录解析失败（见 SessionServiceProvider 解析为绝对路径）。
    'drivers' => [
        'file' => [
            'path' => env('SESSION_FILE_PATH'),   // null → 由 Provider 解析为 storage_path('sessions')（匹配 .gitignore 约定）
        ],
        'array' => [
            // 进程内内存存储，适合测试 / CLI 一次性场景（不持久化）。
        ],
        'redis' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD'),
            'database' => (int) env('REDIS_DB', 0),
            'prefix' => 'kode:sess:',
        ],
        'cookie' => [
            // 会话数据直接加密托管在客户端 Cookie（无服务端存储）。
        ],
        'database' => [
            'table' => env('SESSION_TABLE', 'sessions'),
        ],
    ],
];
