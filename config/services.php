<?php

declare(strict_types=1);

/**
 * 服务发现（Service Discovery）薄壳层配置。
 *
 * 设计立场（薄壳原则）：
 *   - 框架不内置任何分布式发现客户端（Consul / Nacos / ZooKeeper / Etcd 等），那是基础设施决策；
 *   - 框架只提供「可插拔注册表」抽象（ServiceRegistry）+ 运行时解析/负载均衡/健康检查 + 事件；
 *   - 内置 StaticServiceRegistry 把 config/services.php 里的静态声明当作注册表，立即可用；
 *   - 真实分布式发现：实现一个 ServiceRegistry 注入容器即可，框架零改动（见底部示例）。
 *
 * 负载均衡策略（resolve 时使用）：
 *   - round_robin：轮询（默认，跨请求均匀分摊）；
 *   - random：随机挑选；
 *   - first：取第一个健康实例。
 */

return [
    // 总开关。关闭时 ServiceDiscoveryServiceProvider 完全不接线（零开销）。
    'enabled' => (bool) env('SERVICE_DISCOVERY_ENABLED', true),

    // 默认负载均衡策略（resolve 未显式指定时使用）。可选：round_robin | random | first。
    'default_strategy' => (string) env('SERVICE_DISCOVERY_STRATEGY', 'round_robin'),

    // 静态服务声明（内置后端）。每项：name => [实例列表]。
    // 实例字段：host(必填) / port(默认80) / scheme(默认http) / weight(默认1) / metadata(可选) / id(可选,自动生成)。
    // 接入真实发现后端（Consul/Nacos）时，本表可留空，由注入的 ServiceRegistry 提供实例。
    'services' => [
        // 示例（删除或替换为真实上游）。
        'example-upstream' => [
            [
                'host'   => env('EXAMPLE_UPSTREAM_HOST', '127.0.0.1'),
                'port'   => (int) env('EXAMPLE_UPSTREAM_PORT', 8080),
                'scheme' => 'http',
                'weight' => 1,
            ],
        ],
    ],
];

/*
 * 接入真实分布式发现（零框架改动示例）：
 *
 *   // app\discovery\ConsulRegistry implements \Kode\Framework\ServiceDiscovery\Contracts\ServiceRegistry
 *   // 构造接收 ['address'=>..., 'token'=>...]，discover() 调 Consul Catalog API 返回 ServiceInstance[]。
 *   // 然后在 ServiceDiscoveryServiceProvider 的 boot() 中把内置 StaticServiceRegistry
 *   // 换成 $this->container->instance(ServiceRegistry::class, new ConsulRegistry($cfg));
 *   // 即可。service()/service_url() 助手与事件机制无需任何改动。
 */
