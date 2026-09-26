<?php

declare(strict_types=1);

namespace Kode\Plugins;

use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;
use Kode\Plugins\demo\DemoService;

/**
 * 示例插件（演示插件机制）
 *
 * 在 config/plugins.php 的 plugins 数组里声明本类即可启用：
 *   'plugins' => [\Kode\Plugins\DemoPlugin::class],
 *
 * 插件可注册路由（自动打 plugin:demo 来源标签）、绑定服务、注册监听器/命令。
 */
final class DemoPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'demo';
    }

    public function register(PluginManager $manager): void
    {
        // 注册一条路由：GET /plugin/demo
        $manager->addRoute('demo.hello', 'GET', '/plugin/demo', static fn(): array => [
            'plugin' => 'demo',
            'hello' => 'world',
        ]);

        // 绑定一个服务到容器
        $manager->bind('demo.service', static fn(): DemoService => new DemoService());
    }

    public function boot(PluginManager $manager): void
    {
        // 插件启动逻辑（预热缓存、注册定时任务等）写在这里。
    }
}
