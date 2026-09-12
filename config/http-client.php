<?php

declare(strict_types=1);

/**
 * kode/http-client 配置
 *
 * 通过 Factory::createSimple() 构建 PSR-18 客户端（自动选驱动：
 * fiber / swoole / swow / amp）。
 */

return [
    // 基础地址：后续请求可写相对路径
    'base_uri' => env('HTTP_CLIENT_BASE_URI', ''),

    // 默认请求头
    'headers' => [
        'Accept' => 'application/json',
        'User-Agent' => 'Kode-Framework/1.0',
    ],

    // 超时（秒）
    'timeout' => (float) env('HTTP_CLIENT_TIMEOUT', 5.0),

    // 失败重试
    'retry' => [
        'max' => 1,
        'codes' => [429, 500, 502, 503, 504],
    ],
];
