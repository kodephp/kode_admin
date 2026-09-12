<?php

/*
 * 跨域（CORS）配置
 *
 * 启用后，框架会在全局中间件层自动补全 CORS 响应头，并处理浏览器
 * 的 OPTIONS 预检请求（直接返回 204，不再进入路由）。
 *
 * production 环境建议把 allowed_origins 收敛为具体域名，并谨慎开启
 * allow_credentials（携带凭证时不允许通配来源）。
 */

return [
    // 是否启用 CORS（开发期默认开，生产按需关或收敛来源）
    'enabled' => (bool) env('CORS_ENABLED', false),

    // 允许的来源：'*' 表示任意；或指定数组 ['https://a.com', 'https://b.com']
    'allowed_origins' => env('CORS_ALLOWED_ORIGINS', '*'),

    // 允许的请求方法
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // 允许的请求头
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-Request-Id'],

    // 暴露给浏览器的响应头
    'exposed_headers' => ['X-Request-Id', 'X-Trace-Id'],

    // 预检结果缓存秒数
    'max_age' => 86400,

    // 是否允许携带凭证（Cookie / Authorization）；开启时 origins 不可为 '*'
    'allow_credentials' => (bool) env('CORS_ALLOW_CREDENTIALS', false),
];
