<?php

declare(strict_types=1);

/**
 * kode/messaging 配置
 *
 * 通过 Messaging::configure() 加载后，全局静态门面可用。
 * 演示用 memory 总线（进程内），生产可切 redis / 外部 broker。
 *
 *   messaging()->pubsub('memory')->publish('orders:created', $data);
 *
 * 键的取值范围由包决定，多余的键会被静默忽略：
 *  - <scheme>：以「归一化后的 URL scheme」为键（ws / sse / mqtt / udp …），
 *    作为该协议 server|client 的默认配置（Builder::start() 读 globalConfig[<scheme>]）。
 *    注意 'websocket' 这类别名不是键名（归一化后是 ws），写错等于没写。
 *  - pubsub：总线路由（default + 各驱动参数），publish/subscribe 只认这里。
 *  - cluster / redis：集群总线（withCluster()）使用的跨节点总线参数。
 *  - consumers：messaging:consume 命令的「频道 => 处理器类」映射。
 */

return [
    // 总线驱动：messaging:consume 的取值口径为 --driver > 本键 > pubsub.default > memory（框架 >= 1.7.7）。
    // 它与下面 pubsub.default 是两回事——前者决定「消费进程连哪条总线」，后者决定
    // Messaging::pubsub() 无参调用（即生产端）时的默认总线。这里刻意留空：写死值会盖住你改过的
    // pubsub.default，让生产/消费两端悄悄跑在不同总线上；只配下面一处即可（两端同步）。
    'default' => env('MESSAGING_DEFAULT', ''),

    // WebSocket（Messaging::server('ws://0.0.0.0:8080')）
    'ws' => [
        'host' => '0.0.0.0',
        'port' => 8080,
        // 传输层驱动：auto（默认，按扩展自动探测）| native | swoole | …（TransportFactory 认识的名称）
        'transport' => env('MESSAGING_TRANSPORT', 'auto'),
    ],

    // SSE 服务端（Messaging::server('https://0.0.0.0:8081')）
    'sse' => ['host' => '0.0.0.0', 'port' => 8081],

    // MQTT（Messaging::server('mqtt://127.0.0.1:1883')）
    'mqtt' => ['host' => '127.0.0.1', 'port' => 1883],

    // 集群总线（withCluster() 时生效）：driver = redis（跨机）| channel（单机多 worker）
    // 'cluster' => [
    //     'driver' => 'redis',
    //     'channel' => [],
    // ],

    // 发布订阅总线驱动配置（Messaging::pubsub($driver) 按驱动名取本段，与调用点传的配置合并）。
    'pubsub' => [
        'default' => env('MESSAGING_DEFAULT', 'memory'),
        // MemoryBus 不读任何参数（进程内实现，跨 worker 不互通），所以这里无需开关：
        // 想换总线改 default 或直接 pubsub('redis')。
        'memory' => [],
        // RedisBus 的连接参数是扁平的，且库序号的键名是 db（不是 database —— 写错等于连 0 号库）。
        // 'redis' => [
        //     'host' => env('REDIS_HOST', '127.0.0.1'),
        //     'port' => (int) env('REDIS_PORT', 6379),
        //     'password' => env('REDIS_PASSWORD'),
        //     'db' => (int) env('REDIS_DB', 0),
        //     'timeout' => 2.0,
        //     'prefix' => 'kode:messaging:',
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
