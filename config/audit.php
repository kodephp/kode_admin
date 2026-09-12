<?php

/*
 * 审计日志配置（合规 / 安全审计）
 *
 * 审计中间件记录每次请求的关键事实：method / path / query / status / 时延 /
 * 客户端 IP / 链路 ID / 当前用户 ID（需经过 AuthMiddleware 鉴权）。
 * 默认写入 PSR 日志器（框架 Monolog，落 storage/logs），可按需接入 ELK / 审计库。
 *
 * 说明：审计是「合规记录」，与 access_log（观测）互补——access_log 关注流量，
 * 审计关注「谁在什么时间对什么资源做了什么、结果如何」。
 */

return [
    // 是否启用审计（默认关闭：框架对标 webman，默认仅路由+响应+容器DI，
    // 异常/CORS/安全头/审计等跨切面能力由开发者按业务 opt-in 逐步开启）。
    'enabled' => (bool) env('AUDIT_ENABLED', false),

    // 跳过的基础设施端点（避免噪声）
    'ignore_paths' => [
        '/health', '/health/live', '/health/ready', '/ping', '/metrics',
    ],

    // 是否捕获当前用户 ID（依赖 AuthMiddleware 写入 kode/context 的 auth_user_id）
    'capture_user' => (bool) env('AUDIT_CAPTURE_USER', true),

    // 审计日志级别（info / warning / debug）
    'log_level' => env('AUDIT_LOG_LEVEL', 'info'),

    // 业务 / 安全事件（audit()->event()）的日志级别（默认 info）
    'event_log_level' => env('AUDIT_EVENT_LOG_LEVEL', 'info'),

    // 敏感字段脱敏：命中下述键的查询参数 / 事件明细值将被替换为 '***'，
    // 防止令牌、密码等凭据落进审计日志造成二次泄漏。设为 [] 即关闭脱敏。
    'mask_params' => [
        'password', 'passwd', 'pwd', 'token', 'secret', 'secrets',
        'authorization', 'api_key', 'apikey', 'access_token', 'refresh_token',
        'private_key', 'cookie', 'set-cookie', 'x-api-key', 'csrf_token', 'otp', 'pin',
    ],

    // 是否对请求体（仅当已解析为数组，如 form / json）做脱敏记录。
    // 默认关：避免读取 / 改写请求体流，绝大多数接口无需记录请求体；敏感接口可开启。
    'mask_body' => (bool) env('AUDIT_MASK_BODY', false),

    // 取证元数据：记录 User-Agent / Referer，协助安全溯源（默认开）。
    'forensic' => (bool) env('AUDIT_FORENSIC', true),
];
