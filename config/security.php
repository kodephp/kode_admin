<?php

/*
 * 安全响应头配置
 *
 * 默认对所有响应追加工业级安全头（防嗅探、防点击劫持、Referrer 策略、
 * HSTS）。这些头对纯 API 服务也基本无害；如不需要可整体关闭。
 */

return [
    // 是否启用安全响应头
    'enabled' => (bool) env('SECURITY_HEADERS_ENABLED', false),

    // X-Content-Type-Options: nosniff
    'nosniff' => true,

    // X-Frame-Options（防点击劫持）：DENY / SAMEORIGIN
    'frame_options' => env('SECURITY_FRAME_OPTIONS', 'DENY'),

    // Referrer-Policy
    'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

    // HSTS（仅 HTTPS 生效；HTTP 下浏览器会忽略，但建议统一下发以便前置 TLS 终止后生效）
    'hsts' => env('SECURITY_HSTS', 'max-age=31536000; includeSubDomains'),

    // X-XSS-Protection（老旧浏览器兼容）
    'xss_protection' => '0',

    // 是否下发 X-Request-Id（链路追踪）。opt-in：默认关，需显式开启才在响应回写链路头。
    'request_id' => (bool) env('SECURITY_REQUEST_ID', false),

    // X-Request-Id 是否允许客户端用同名请求头覆盖（便于跨服务透传）
    'request_id_allow_client' => (bool) env('SECURITY_REQUEST_ID_ALLOW_CLIENT', true),

    // ------------------------------------------------------------------
    // 进阶安全头（合规 / 加固）
    // ------------------------------------------------------------------

    // Content-Security-Policy：默认开启一套「纯 API 友好」基线（不含 unsafe-inline，
    // 因 API 通常只返回 JSON，无需执行脚本）。按需收紧 / 放宽。
    // 设为 false 关闭 CSP 下发。
    'csp' => env('SECURITY_CSP', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"),

    // Permissions-Policy：禁用浏览器冗余特性（减少攻击面）。
    'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY', 'geolocation=(), microphone=(), camera=(), payment=(), usb=()'),

    // Cross-Origin-Opener-Policy：隔离浏览上下文，缓解 XS-Leaks / 跨源攻击。
    'cross_origin_opener_policy' => env('SECURITY_COOP', 'same-origin'),

    // Cross-Origin-Resource-Policy：限制跨源资源加载（仅同源 / 同源+同站）。
    'cross_origin_resource_policy' => env('SECURITY_CORP', 'same-origin'),

    // Cross-Origin-Embedder-Policy：需配合 COOP 开启跨源隔离（凭据类 API 可开 require-corp）。
    'cross_origin_embedder_policy' => env('SECURITY_COEP', false),

    /*
     * 受信反向代理列表（v1.0.0 新增，H4 修复的配置锚点）。
     *
     * 仅当直连对端（REMOTE_ADDR）命中此列表时，框架才采信 X-Forwarded-For / X-Real-IP
     * 等转发头，用于限流真实 IP、审计溯源、灰度分桶。默认 [] = 不信任任何代理，
     * 一律用 REMOTE_ADDR——客户端伪造 XFF / X-User-Id 等头将无法绕过限流或操纵分桶。
     *
     * 支持三种写法（可混合）：
     *   - 精确 IP：'203.0.113.10'
     *   - CIDR：'10.0.0.0/8'、'2001:db8::/32'
     *   - '*'：信任一切直连（仅限内网网关 / 无法枚举代理出口的场景）
     *
     * 常见部署：前置一层 Nginx/LB 时填写其出口网段，如 ['127.0.0.1', '10.0.0.0/8']；
     * 多层代理时填写全部可直连的代理网段（XFF 解析从右往左取第一个非受信地址）。
     */
    'trusted_proxies' => env('TRUSTED_PROXIES')
        ? explode(',', (string) env('TRUSTED_PROXIES'))
        : [],
];
