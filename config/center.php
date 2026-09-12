<?php

declare(strict_types=1);

/**
 * 配置中心（Config Center）薄壳层配置。
 *
 * 设计立场（薄壳原则）：
 *   - 框架不内置任何远程配置中心客户端（Nacos / Apollo / etcd 等），那是应用/基础设施决策；
 *   - 框架只提供「可插拔配置源」抽象 + 运行时热重载 + 事件通知；
 *   - 应用把远程中心实现为 ConfigSource 加进 sources 即可，框架零改动。
 *
 * 优先级（高 → 低）：配置中心 sources 覆盖 config/*.php 文件配置。
 *   seed() 在启动期把 sources 合并进 Config；reload() 在运行期重新拉取并合并。
 */

return [
    // 总开关。关闭时 ConfigCenterServiceProvider 完全不接线（零开销）。
    'enabled' => (bool) env('CONFIG_CENTER_ENABLED', false),

    // 配置源列表（按声明顺序合并，后者覆盖前者）。
    // 每个源：['class' => ConfigSource 实现类名, 'config' => 传给构造的数组]
    'sources' => [
        // 内置文件源：把一份本地 PHP/JSON 文件当作「配置覆盖层」。
        // 用途：
        //   - 远程中心拉取到本地的镜像缓存（应用侧 watch 后写此文件，再调 config:center:reload）；
        //   - 运维不重新部署即可改线上配置（写 center.local.php，gitignore 不提交）。
        // 远程中心接入示例（应用自行实现）：
        //   ['class' => app\config\NacosConfigSource::class, 'config' => ['server' => '...', 'dataId' => '...']]
        [
            'class' => \Kode\Framework\Config\FileConfigSource::class,
            'config' => [
                'path' => env('CONFIG_CENTER_FILE', __DIR__ . '/center.local.php'),
                'name' => 'file',
            ],
        ],
    ],
];
