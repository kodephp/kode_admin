<?php

/*
 * 日志配置（Monolog，遵循 PSR-3）
 */

return [
    'name' => env('APP_NAME', 'kode'),
    'path' => base_path('storage/logs/app.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'rotate' => true,

    // 访问日志（结构化请求日志）：method/uri/status/latency/request_id/client_ip。
    // 生产建议开启，便于接 ELK/Loki 做流量观测与慢请求定位。
    'access_log' => [
        'enabled' => (bool) env('ACCESS_LOG_ENABLED', false),
        // 异步离路径导出（默认开）：中间件热路径仅内存入队，真实格式化 + 文件写入由
        // 响应后的 shutdown / 优雅停机钩子批量执行，绝不阻塞客户端响应（与追踪同范式）。
        // 设 false 则退化为请求内同步写 logger（审计强一致、或需即时落盘的场景）。
        'async' => (bool) env('ACCESS_LOG_ASYNC', true),
    ],
];
