<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Facades\Event;
use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;
use Kode\Framework\Testing\TestCase;

/**
 * 插件启动接线回归测试。
 *
 * 为什么需要这个测试：插件机制的哑火很隐蔽——`config/plugins.php` 里登记了类，
 * 框架默认 providers 列表里有 PluginServiceProvider，插件就真被加载；任一处被
 * 删改（清 provider、改配置键名、插件类改名），`register()` 就不再执行：
 * 路由消失、事件监听器消失，而 CLI 审计与插件列表页仍能正常显示插件（它们直接
 * 实例化插件类），故障只在真实请求时暴露。本测试锁定这条链路的三个锚点：
 * 配置 → 容器绑定 → 加载完成后的真实副作用。
 */
final class PluginBootTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    /** 配置是插件清单的唯一事实来源，非空即框架有活干。 */
    public function test_plugin_config_is_not_empty(): void
    {
        $plugins = (array) config('plugins.plugins', []);

        self::assertNotSame([], $plugins, 'config(plugins.plugins) 不能为空——插件机制将整体哑火');
    }

    /** PluginServiceProvider::register() 必须把 PluginManager 绑进容器。 */
    public function test_plugin_manager_is_bound(): void
    {
        self::assertInstanceOf(PluginManager::class, resolve(PluginManager::class));
    }

    /** 配置里登记的每个插件类都要真正被 load() 过（name() 集合严格一致）。 */
    public function test_configured_plugins_are_all_loaded(): void
    {
        $configured = (array) config('plugins.plugins', []);
        $loaded = [];
        foreach (resolve(PluginManager::class)->all() as $plugin) {
            self::assertInstanceOf(PluginInterface::class, $plugin);
            $loaded[] = $plugin->name();
        }

        $expected = [];
        foreach ($configured as $class) {
            self::assertTrue(
                class_exists($class),
                "config/plugins.php 登记的插件类不存在：{$class}"
            );
            self::assertTrue(
                is_subclass_of($class, PluginInterface::class),
                "config/plugins.php 登记的类必须实现 PluginInterface：{$class}"
            );
            $expected[] = (new $class())->name();
        }

        sort($loaded);
        sort($expected);

        self::assertSame(
            $expected,
            $loaded,
            'config/plugins.php 登记的插件必须全部被 PluginManager::load() 加载；'
            . '已加载：' . implode(',', $loaded)
        );
    }

    /**
     * 路由注册副作用：NoticePlugin::register() 声明的 /api/notices 必须真实存在。
     *
     * 断言"不是 404"而非具体状态码：路由存在时 AuthMiddleware 会先拦（401），
     * 路由缺失才是 404。无需 DB / 鉴权即可验证路由表。
     */
    public function test_plugin_routes_are_registered(): void
    {
        $status = $this->get('/api/notices')->status();

        self::assertNotSame(
            404,
            $status,
            'NoticePlugin::register() 声明的 /api/notices 必须已注册（当前状态码 ' . $status . '）'
        );
    }

    /**
     * 事件监听副作用：StorageDriverPlugin::register() 必须真的挂上
     * storage_driver_resolve，否则 StorageManager::transfer() 推远端盘恒失败。
     */
    public function test_storage_driver_listener_is_registered(): void
    {
        $configured = (array) config('plugins.plugins', []);
        if (!in_array(\Kode\Plugins\StorageDriverPlugin::class, $configured, true)) {
            $this->markTestSkipped('storage_driver 插件未启用');
        }

        self::assertTrue(
            Event::hasListeners(\app\support\StorageManager::REMOTE_RESOLVE_EVENT),
            'storage_driver_resolve 监听器必须已注册（存储远端推送依赖它）'
        );
    }

    /**
     * 契约回归：存储驱动插件是纯组件插件，不声明菜单。
     *
     * 声明 menus() 即声明"存在前端页面"，但该插件没有页面——菜单会落到通用占位页，
     * 属声明⟺实现的 UI 层漂移。前端 scripts/check-menu-map.php 做跨仓校验，
     * 此断言守后端侧不变量。
     */
    public function test_component_only_plugins_declare_no_menus(): void
    {
        $plugin = new \Kode\Plugins\StorageDriverPlugin();

        self::assertSame([], $plugin->menus(), '纯组件插件不得声明菜单（前端无对应视图）');
    }

    /**
     * 事件契约端到端：StorageManager::pushRemote() 派发的事件，插件驱动必须真能应答。
     *
     * 这条链路踩过两个真坑：①`Kode\Event\Event` 没有 `__get`，监听器写
     * `$event->driver` 恒得 null，导致 local 驱动被误判为「未实现」；②核心此前
     * 只返回「需先部署 SDK」的错误、从未派发过事件。此处派发真实事件验证回报，
     * 并反证一个未实现的驱动必须如实拒绝（不得静默成功）。
     */
    public function test_storage_driver_resolve_contract_roundtrip(): void
    {
        $event = Event::dispatch(\app\support\StorageManager::REMOTE_RESOLVE_EVENT, [
            'driver' => 'local',
            'path'   => '/storage/tmp/_contract_probe.bin',
            'disk'   => ['name' => 'local', 'driver' => 'local'],
            'id'     => 0,
        ]);

        self::assertTrue(
            (bool) ($event->get('pushed') ?? false),
            'local 驱动应回报 pushed=true（驱动键取不到会误判为未实现）'
        );
        self::assertSame(
            '/storage/tmp/_contract_probe.bin',
            (string) ($event->get('url') ?? ''),
            'local 盘无需远程上传，url 应回传原路径'
        );

        // 未实现的驱动必须如实拒绝，不得静默成功。
        $ghost = Event::dispatch(\app\support\StorageManager::REMOTE_RESOLVE_EVENT, [
            'driver' => 'ghost_driver',
            'path'   => '/storage/tmp/_contract_probe.bin',
            'disk'   => ['name' => 'ghost', 'driver' => 'ghost_driver'],
            'id'     => 0,
        ]);

        self::assertFalse(
            (bool) ($ghost->get('pushed') ?? false),
            '未实现的驱动必须回报 pushed=false（禁止静默成功）'
        );
        self::assertNotEmpty((string) ($ghost->get('msg') ?? ''));
    }
}
