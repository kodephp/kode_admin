<?php

/*
 * CSRF 防护配置
 *
 * 防线立场：CSRF 是「按需挂载」的企业级中间件——仅被 #[Csrf] 标记的路由（或
 * auto_apply_unsafe 命中的非安全路由）才触发令牌校验；其余路由（含 /ping、
 * 纯 JWT 接口）在全局中间件里 O(1) 早退，零开销，故「加上企业中间件也不影响响应」。
 *
 * 前置依赖：会话（LazySessionMiddleware）。令牌存于会话；无会话承载时非安全方法
 * fail-closed 拒绝（419），不再静默放行（静默放行等于标了 #[Csrf] 却零防护）。
 */

return [
    // 是否启用 CSRF 全局中间件（默认关，opt-in：CSRF_ENABLED=true 开启）。
    // 对标 webman 默认裸内核（路由+响应+容器DI）：跨切面能力一律默认关闭，开发者按业务开启。
    // 开启后全局中间件本身对未被 #[Csrf] 标记的路由仍 O(1) 早退、零开销。
    'enabled' => (bool) env('CSRF_ENABLED', false),

    // 会话中存储令牌的键名。
    'token_key' => env('CSRF_TOKEN_KEY', '_csrf_token'),

    // 引导令牌回传的响应头（SPA / 表单从此头读取）。
    'header' => env('CSRF_HEADER', 'X-CSRF-Token'),

    // Angular 等框架的 XSRF 双提交 cookie 头（亦被接受为提交令牌来源）。
    'xsrf_header' => env('CSRF_XSRF_HEADER', 'X-XSRF-Token'),

    // 表单 / JSON 体中提交令牌的字段名（v1.0.0 起不再接受查询参数载体，防令牌入 URL/日志/Referer）。
    'token_param' => env('CSRF_TOKEN_PARAM', '_token'),

    // 校验失败响应（419 为 Laravel 惯例，贴合前端拦截器预期）。
    'error_status' => (int) env('CSRF_ERROR_STATUS', 419),
    'error_message' => env('CSRF_ERROR_MESSAGE', 'CSRF token mismatch'),

    // 自动对所有非安全方法（POST/PUT/PATCH/DELETE）路由套用 CSRF（默认关，推荐用 #[Csrf] 精确标记）。
    'auto_apply_unsafe' => (bool) env('CSRF_AUTO_APPLY_UNSAFE', false),

    // auto_apply_unsafe 模式下的排除路径（探针 / 健康检查等无需防护）。
    'exclude_paths' => [
        '/health', '/health/live', '/health/ready', '/ping',
        '/metrics', '/favicon.ico',
    ],

    // 显式跳过 CSRF 校验的路径（即便被 #[Csrf] 标记也豁免，用于需跨站调用的 Webhook 等）。
    'skip_paths' => [],

    // 校验失败时是否经离路径异步审计管线记录安全事件（默认开；与 auth.failed 同源，SOC 可统一监测）。
    // 仅在失败时触发，不污染正常请求热路径；审计未启用则静默跳过。
    'audit_on_failure' => (bool) env('CSRF_AUDIT_ON_FAILURE', true),

    // 失败审计事件名（默认 csrf.failed，可由 SIEM/告警规则统一订阅）。
    'audit_action' => env('CSRF_AUDIT_ACTION', 'csrf.failed'),
];
