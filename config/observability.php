<?php

/*
 * 可观测性配置（指标 + 链路追踪）
 *
 *  - metrics：Prometheus 抓取端点 /metrics（标准 exposition 文本格式）。
 *    生产务必开启保护（protect=token/local），避免指标外泄。
 *  - tracing：分布式链路（trace_id/span_id），基于 kode/context，兼容 W3C traceparent。
 *    同时回写 $_SERVER[HTTP_X_TRACE_ID]，使异常响应的 trace_id 与正常响应一致。
 */

return [
    // ------------------------------------------------------------------
    // 指标（Prometheus）
    // ------------------------------------------------------------------
    'metrics' => [
        // 是否启用 /metrics 端点与自动请求指标采集
        'enabled' => (bool) env('OBS_METRICS_ENABLED', false),

        // 端点路径
        'path' => env('OBS_METRICS_PATH', '/metrics'),

        // 可选：是否挂载请求指标采集中间件（默认 true）。关闭后保留 registry / 端点 / 门面，
        // 仅移除每请求采集（压测隔离用：区分「中间件每请求成本」与「observability 组其余副作用」）。
        // 'middleware_enabled' => (bool) env('OBS_METRICS_MIDDLEWARE_ENABLED', true),

        // 可选：是否注册指标抓取端点 /metrics（默认 true）。纯内网旁路采集（独立 metric agent
        // 定时 scrape / 网关统一抓取）可关闭，避免业务路由表多一行匹配。
        // 'register_endpoint' => (bool) env('OBS_METRICS_REGISTER_ENDPOINT', true),

        /*
         * 端点保护策略（指标含 QPS / 错误率等敏感运营数据，生产勿公开）：
         *  - 'token'：需携带 ?token=<TOKEN> 或 Authorization: Bearer <TOKEN>
         *  - 'local'：仅允许回环地址（127.0.0.0/8、::1）访问
         *  - 'none' ：完全公开（仅建议内网/调试）
         */
        'protect' => env('OBS_METRICS_PROTECT', 'token'),

        // 访问令牌；为空时框架启动时生成随机令牌并打印到 stderr（便于一次性使用）。
        'token' => env('OBS_METRICS_TOKEN', ''),

        // 自动请求指标跳过的路径（基础设施端点，避免污染业务指标）
        'skip_paths' => [
            '/metrics', '/health', '/health/live', '/health/ready', '/ping',
        ],

        // 时延直方图采样比例 0..1（1 = 全采；高流量生产务必调低，避免每请求都付出
        // HDR 分位维护开销）。计数（吞吐/错误率）始终 100% 采集不受影响；采样后
        // P50/P95/P99 仍统计有效（标准 Prometheus 实践）。默认 0.1。
        'sample_ratio' => (float) env('OBS_METRICS_SAMPLE_RATIO', 0.1),
    ],

    // ------------------------------------------------------------------
    // 链路追踪（分布式追踪 / OTLP 导出）
    // ------------------------------------------------------------------
    // 框架已内置 W3C traceparent 传播（TraceContext + TraceMiddleware）与跨服务
    // 串联（outgoingHeaders）。本段补齐「span 录制 + OTLP 导出」——把进程内链路
    // 真正送到 APM（Jaeger / Tempo / OpenTelemetry Collector），实现端到端可观测。
    //
    // 设计立场：框架只给「Span 抽象 + 导出契约 + 内置 OTLP/HTTP(JSON) 与文件导出器」，
    // 不内置 OpenTelemetry SDK；真实 exporter（OTLP/gRPC、protobuf、第三方 APM）实现
    // SpanExporter 注入即可零改动接入。采样、导出时机、端点交给配置。
    'tracing' => [
        // 是否启用 span 录制（关闭后 tracer() 返回 no-op，不产生任何 span）
        'enabled' => (bool) env('OBS_TRACING_ENABLED', false),

        // 写入 OTLP resource.service.name 的服务标识
        'service_name' => env('APP_NAME', 'kode-app'),

        // 采样比例 0..1（1 = 全采；高流量生产务必调低，避免 100% 请求都付出 span 录制开销）。
        // 默认 0.1：仅 10% 请求录制 span（足够定位问题），其余走「无 span 创建」快路径，
        // 吞吐影响从「全采 ~45%」降为「采样 ~5%」。需要全链路时显式设 1.0。
        'sample_ratio' => (float) env('OBS_TRACING_SAMPLE_RATIO', 0.1),

        // 请求（根 span）结束时自动 flush 缓冲 span（HTTP 场景推荐开启）
        'flush_on_request_end' => (bool) env('OBS_TRACING_FLUSH_ON_END', true),

        /*
         * 响应是否回写 W3C 链路头（traceparent + X-Trace-Id + X-Span-Id）。
         *  - true（默认）：每个响应都带链路头，网关 / 日志 / APM 可直接串联；
         *    代价是每请求多一次 W3C 字符串拼接 + 3× 响应头写入（约 0.77µs）。
         *  - false：仅建立内部 trace 上下文（供日志关联、下游 outgoingHeaders 串联、
         *    kode/exception 异常 tracer 桥接），不回写响应头——省去上述每请求开销，
         *    适合「不依赖 W3C 传播、仅内部可观测」的高吞吐部署。
         *    Note：内部 trace_id/span_id 仍照常生成，trace()/logger 关联不受影响。
         */
        'attach_headers' => (bool) env('OBS_TRACING_ATTACH_HEADERS', true),

        // 异步导出（默认开）：请求结束仅把 span 内存入队（µs 级），真实网络发送由
        // 定时器 / shutdown / 优雅停机钩子离请求路径批量执行——避免每请求同步阻塞
        // OTLP POST 拖垮吞吐（OTel BatchSpanProcessor 同范式）。关闭则退化为请求结束同步导出。
        'async' => (bool) env('OBS_TRACING_ASYNC', true),

        // 进程级 outbox 容量上限（超出丢弃最旧），防内存膨胀 / collector 长期不可用时堆积
        'max_outbox' => (int) env('OBS_TRACING_MAX_OUTBOX', 4096),

        // 常驻进程（Swoole / Workerman）周期性 drain 间隔（毫秒）
        'flush_interval_ms' => (int) env('OBS_TRACING_FLUSH_INTERVAL_MS', 2000),

        // 单执行单元缓冲上限（超出丢弃最旧），防内存膨胀
        'max_batch' => (int) env('OBS_TRACING_MAX_BATCH', 512),

        // 导出器：'otlp_http' | 'file' | 自定义(实现 SpanExporter 的类名)
        'exporter' => env('OBS_TRACING_EXPORTER', 'otlp_http'),

        // OTLP/HTTP JSON 导出器（OTel Collector / Jaeger / Tempo 均支持 /v1/traces）
        'otlp' => [
            'endpoint' => env('OTLP_TRACES_ENDPOINT', 'http://localhost:4318/v1/traces'),
            'headers'  => [
                'Authorization' => env('OTLP_TRACES_AUTH', ''),
            ],
            'timeout' => (int) env('OTLP_TRACES_TIMEOUT', 2),
        ],

        // 文件导出器（开发调试用 NDJSON，无需 collector）
        'file' => [
            'path' => (string) (env('OBS_TRACING_FILE') ?? (sys_get_temp_dir() . '/kode-traces.ndjson')),
        ],
    ],
];
