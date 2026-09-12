<?php

/*
 * 幂等配置（薄壳层）
 *
 * 框架内置「契约 + 内置静态存储」，不重新发明分布式 KV：
 *
 *  - driver = memory：进程内静态表，覆盖单实例与同进程内并发（Fiber / 协程 / 同请求多次调用）；
 *  - driver = file：文件落盘（默认 storage_path('framework/idempotency')），覆盖同主机多进程去重。
 *
 * 跨主机共享去重（Redis / etcd / DB）不在此实现——在应用层实现
 * Kode\Framework\Idempotency\IdempotencyStore 并经 config/app.php 的 providers 绑定即可零改动替换。
 *
 * 与分布式锁的边界：锁 = 并发互斥（同一时刻仅一个持有者运行）；幂等 = 重试安全
 * （同一 key 在 TTL 内只成功处理一次，重放返回一致语义）。两者解决不同问题，常配合使用。
 *
 * 用法：
 *   idempotency()?->once($reqId, fn () => charge(), 3600);   // 首次执行，重复抛 DuplicateRequest
 *   if (idempotency()?->seen($key, 3600)) { ... }            // 首次返回 true，重复返回 false
 *   idempotency()?->forget($key);                            // 重试放行 / 运维清理
 *
 * TTL 到期自动失效（惰性过期，无需后台 reaper）；业务抛异常时 once() 回滚记录，允许重试。
 */

return [
    'driver' => (string) env('IDEMPOTENCY_DRIVER', 'memory'),

    // file 模式的自定义目录（留空则默认 storage_path('framework/idempotency')）
    'path' => env('IDEMPOTENCY_PATH', ''),

    // ------------------------------------------------------------------
    // HTTP 幂等中间件（可选，默认开启；仅对携带幂等键的请求生效，缺头零开销放行）
    // ------------------------------------------------------------------
    'http' => [
        'enabled' => (bool) env('IDEMPOTENCY_HTTP_ENABLED', false),

        // 客户端携带的幂等键请求头（Stripe 风格）
        'header' => (string) env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),

        // 缺头时是否强制 400（写接口防重复提交建议开启；默认 false = 缺头直接放行）
        'enforce' => (bool) env('IDEMPOTENCY_ENFORCE', false),

        // 重放缓存的 TTL（秒），与底层存储一致；到期自动失效
        'ttl' => (int) env('IDEMPOTENCY_TTL', 3600),

        // 键作用域：global = 仅用键（键即身份，跨端点唯一）；route = 叠加 METHOD path 防跨端点碰撞
        'scope' => (string) env('IDEMPOTENCY_SCOPE', 'global'),

        // 键前缀（多应用共用存储时命名空间隔离）
        'prefix' => (string) env('IDEMPOTENCY_PREFIX', ''),

        // 重放响应头（标识本次为缓存重放）
        'replay_header' => (string) env('IDEMPOTENCY_REPLAY_HEADER', 'Idempotency-Replay'),

        // 首次记录响应头（标识本次已写入幂等记录）
        'recorded_header' => (string) env('IDEMPOTENCY_RECORDED_HEADER', 'Idempotency-Recorded'),
    ],
];
