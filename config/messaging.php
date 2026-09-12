<?php

declare(strict_types=1);

/**
 * kode/messaging 配置
 *
 * 通过 Messaging::configure() 加载后，全局静态门面可用。
 * 演示用 memory 总线（进程内），生产可切 redis / 外部 broker。
 *
 *   messaging()->pubsub('memory')->publish('orders:created', $data);
 */

return [
    'default' => env('MESSAGING_DEFAULT', 'memory'),

    'logger' => null,

    'transport' => env('MESSAGING_TRANSPORT', 'auto'),

    // 内存总线（进程内发布/订阅，演示与单进程场景）
    'memory' => [
        'enabled' => true,
    ],

    // 若需跨进程，可启用 redis 总线：
    // 'redis' => [
    //     'host' => env('REDIS_HOST', '127.0.0.1'),
    //     'port' => (int) env('REDIS_PORT', 6379),
    // ],

    'websocket' => ['host' => '0.0.0.0', 'port' => 8080],
    'sse' => ['host' => '0.0.0.0', 'port' => 8081],
    'mqtt' => ['host' => '127.0.0.1', 'port' => 1883],

    // 发布订阅总线驱动配置（messaging:consume 通过 pubsub($driver, $config) 读取）。
    'pubsub' => [
        'default' => env('MESSAGING_DEFAULT', 'memory'),
        'memory' => ['enabled' => true],
        // 'redis' => [
        //     'host' => env('REDIS_HOST', '127.0.0.1'),
        //     'port' => (int) env('REDIS_PORT', 6379),
        //     'password' => env('REDIS_PASSWORD'),
        //     'database' => (int) env('REDIS_DB', 0),
        // ],
    ],

    // ------------------------------------------------------------------
    // 消费端（messaging:consume 命令使用）
    // ------------------------------------------------------------------
    // 频道 => 处理器类（处理器提供 handle(array $payload) / __invoke / run / execute 之一）。
    'consumers' => [
        // 'orders:created' => \app\listeners\OrderCreatedListener::class,
    ],
];
