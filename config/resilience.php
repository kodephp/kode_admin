<?php

/*
 * 熔断器配置（算法由 kode/fibers CircuitBreaker 提供，经 FiberBreaker 薄适配）
 *
 * failure_threshold ：连续失败达到该次数后熔断（进入 open）。
 * recovery_timeout  ：熔断后多久进入半开探活（秒）。
 * half_open_max_calls：半开状态允许的试探请求数。
 *
 * 熔断器为进程内状态，多 worker 各自独立；按服务名隔离（如 'user-service'）。
 */

return [
    'enabled' => false,
    'failure_threshold' => (int) env('BREAKER_FAILURE_THRESHOLD', 5),
    'recovery_timeout' => (float) env('BREAKER_RECOVERY_TIMEOUT', 30.0),
    'half_open_max_calls' => (int) env('BREAKER_HALF_OPEN_CALLS', 1),

    /*
     * HTTP 熔断中间件（可选，默认开启；薄壳层，复用本文件基础配置与 Breaker 注册表）。
     * 把「故障隔离」接进 HTTP 流量：保护下游依赖不因故障级联雪崩，与 RetryMiddleware
     * （瞬态抖动恢复）、IdempotencyMiddleware（防重复提交）同属边缘韧性三件套。
     *
     *   enabled              : 是否启用
     *   service_name         : 固定服务名（derive_from=fixed 时生效）；否则按请求推导
     *   derive_from          : 服务名推导方式 path（默认，按路径隔离）| host | fixed
     *   status_threshold     : 响应状态码 >= 该值即记为下游失败（默认 500，即仅 5xx 熔断）
     *   open_status          : 熔断打开时短路返回的 HTTP 状态（默认 503）
     *   record_4xx_as_success: 4xx 是否记为健康（默认 true，避免被客户端错误误熔断）
     *   exclude              : 跳过熔断的路径前缀（健康 / 指标 / 静态资源）
     */
    'breaker.http' => [
        'enabled' => (bool) env('BREAKER_HTTP_ENABLED', false),
        'service_name' => env('BREAKER_HTTP_SERVICE'),
        'derive_from' => (string) env('BREAKER_HTTP_DERIVE', 'path'),
        'status_threshold' => (int) env('BREAKER_HTTP_STATUS_THRESHOLD', 500),
        'open_status' => (int) env('BREAKER_HTTP_OPEN_STATUS', 503),
        'record_4xx_as_success' => (bool) env('BREAKER_HTTP_4XX_SUCCESS', true),
        'exclude' => explode(',', (string) env('BREAKER_HTTP_EXCLUDE', '/health,/health/ready,/metrics,/favicon.ico')),
    ],

    /*
     * 重试默认退避策略（瞬态故障恢复，与熔断互补）。
     * retry() 未显式传 backoff 时，使用此处的默认退避；不依赖任何外部库。
     *
     *   backoff : fixed | exponential | decorrelated（默认 exponential）
     *   base    : 首跳退避秒数
     *   cap     : 退避上限秒数（避免长尾）
     *   jitter  : 指数退避是否对称抖动（抗惊群，默认 true）
     */
    'retry' => [
        'attempts' => (int) env('RETRY_ATTEMPTS', 3),
        'backoff' => (string) env('RETRY_BACKOFF', 'exponential'),
        'base' => (float) env('RETRY_BACKOFF_BASE', 0.1),
        'cap' => (float) env('RETRY_BACKOFF_CAP', 10.0),
        'jitter' => (bool) env('RETRY_BACKOFF_JITTER', true),
    ],

    /*
     * HTTP 重试中间件（可选，默认开启；薄壳层，复用本文件 retry 段的默认退避）。
     * 把 Retry 原语接到 HTTP 流量：对「安全方法（默认 GET/HEAD/PUT/DELETE/OPTIONS）」
     * 的失败响应（默认 502/503/504）或指定异常自动重试，把上游瞬态抖动对调用方屏蔽。
     *
     *   enabled            : 是否启用
     *   methods            : 允许重试的 HTTP 方法（默认仅幂等/安全方法，POST 不重试避免副作用重复）
     *   attempts           : 最大尝试次数（含首次）
     *   timeout            : 总预算（秒），null = 不限
     *   retry_on_status    : 命中这些状态码即重试（上游瞬态）
     *   retry_on_exception : 命中这些异常类即重试（如自定义 UpstreamUnavailableException）
     */
    'retry.http' => [
        'enabled' => (bool) env('RETRY_HTTP_ENABLED', false),
        'methods' => explode(',', (string) env('RETRY_HTTP_METHODS', 'GET,HEAD,PUT,DELETE,OPTIONS')),
        'attempts' => (int) env('RETRY_HTTP_ATTEMPTS', 3),
        'timeout' => env('RETRY_HTTP_TIMEOUT') === null ? null : (float) env('RETRY_HTTP_TIMEOUT'),
        'retry_on_status' => array_map('intval', explode(',', (string) env('RETRY_HTTP_STATUS', '502,503,504'))),
        'retry_on_exception' => [], // 应用层注入（如 [app\exception\UpstreamUnavailableException::class]）
    ],

    /*
     * 超时原语（操作级执行预算，与熔断 / 重试 / 幂等共构「稳定性四件套」）。
     * timeout() 未显式传 seconds/scheduler 时，使用此处默认。底层抢占由 active runtime
     * （kode/fibers）提供——对「会挂起（I/O、sleep）」的协作式任务真实生效；无 fiber 时退化 sync。
     *
     *   seconds   : 默认允许的操作秒数
     *   scheduler : 超时后端 auto|fiber|pcntl|sync
     *               auto  = 有 fiber 走 fiber，否则 sync（默认，推荐）
     *               fiber = 强制 kode/fibers 协程调度器（真实抢占协作式任务）
     *               pcntl = CLI 下 pcntl_alarm 硬中断（opt-in，避免与事件循环信号冲突）
     *               sync  = 退化实现，运行后比对耗时，仅做越界检测、不做抢占
     *   throw     : true（默认）= 超时抛 TimeoutExceeded；false 且未配 fallback 则返回 null
     */
    'timeout' => [
        'seconds' => (float) env('TIMEOUT_SECONDS', 5.0),
        'scheduler' => (string) env('TIMEOUT_SCHEDULER', 'auto'),
        'throw' => (bool) env('TIMEOUT_THROW', true),
    ],
];
