<?php

/*
 * 健康检查配置（就绪探针 / 聚合巡检）
 *
 * 框架内置四类探针，经 /health、/health/live、/health/ready、/ping 端点与 health:check 命令暴露：
 *
 *  1) 配置驱动探针（本文件的 `checks`）：db / cache / queue 布尔开关 + 任意自定义闭包；
 *  2) 能力感知探针（自动，无需配置）：对已接线的企业级子系统做只读可达性检查——
 *     config_center / service_discovery / tracing / tenant_storage；
 *     子系统未启用（助手返回 null）即 not_configured，不计入失败，无需在此声明；
 *  3) app 自身：永远 ok；
 *  4) /health/live：liveness（仅存活判定，不含外部依赖）。
 *
 * /health/ready 会对「启用」的探针做连通性检查：任一 error 即 503（流量摘除）；
 * not_configured 不计入失败（未使用的组件不会误报）。
 *
 * 内置探针：db / cache / queue（布尔开关）。
 * 自定义探针：以闭包形式追加（签名 fn(ContainerInterface $c) => 'ok' | 'error: ...'）。
 *
 *   'redis' => function ($c) {
 *       $r = $c->get(\Kode\Cache\CacheManager::class)->connection('redis');
 *       return $r->ping() ? 'ok' : 'error: no pong';
 *   },
 *
 * 能力感知探针默认随对应子系统自动纳入（如已启用 config/center 则 config_center 探针出现于结果）；
 * 若希望某个已接线能力不计入就绪判定，可在 checks 中以同名键显式置 false 覆盖（例如 'tracing' => false）。
 */

return [
    'checks' => [
        'db' => (bool) env('HEALTH_CHECK_DB', true),
        'cache' => (bool) env('HEALTH_CHECK_CACHE', false),
        'queue' => (bool) env('HEALTH_CHECK_QUEUE', false),
    ],
];
