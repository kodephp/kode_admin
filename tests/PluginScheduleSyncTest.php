<?php

declare(strict_types=1);

namespace app\tests;

use app\support\PluginMarket;
use app\support\PluginScheduleSync;
use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use Kode\Framework\Testing\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 插件状态 ↔ 调度器对账（PluginScheduleSync）端到端验证。
 *
 * 背景：插件用 addCron() / #[Cron] 注册的定时任务带 `plugin:<name>` 来源标签，
 * 但此前 ScheduleDispatcher 只有「注册 + 触发」，没有回收能力——插件被暂停或卸载后
 * 它的任务仍会按时派发。PluginMarket 的状态机与调度器实际行为漂移，且不重启进程无法修复
 * （与 DriverRegistry::flush() 是同一类漂移）。
 *
 * 本类验证 paused/uninstalled/enabled 三种状态都能即时对账，且不误伤主应用任务。
 * 测试环境无数据库（setStatus() 的写库会抛），故直接验证对账单元；
 * PluginMarket::setStatus() 的接线由 sync() 调用顺序保证（落库成功后才对账）。
 */
final class PluginScheduleSyncTest extends TestCase
{
    protected bool $independentApp = true;

    /** 夹具插件名（公开供 SyncFixturePlugin 复用，保持单一来源）：其任务来源标签为 plugin:sync_fixture。 */
    public const PLUGIN = 'sync_fixture';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        $this->bootFixturePlugin();
    }

    /**
     * 用全新 PluginManager 只加载夹具插件，避免连带重跑 config/plugins.php 里的既有插件。
     */
    private function bootFixturePlugin(): void
    {
        $manager = new PluginManager(resolve(\Kode\DI\Container::class));
        $manager->load([SyncFixturePlugin::class]);
    }

    private function dispatcher(): ScheduleDispatcher
    {
        return resolve(ScheduleDispatcher::class);
    }

    /** 该插件此刻会被派发的任务名（真实调度判定，而非只看 enabled 字段）。 */
    private function dueNames(): array
    {
        $now = new \DateTimeImmutable('2026-09-12 10:00:00');
        $names = [];
        foreach ($this->dispatcher()->scheduler()->dueTasks($now) as $task) {
            $names[] = $task->name();
        }

        return $names;
    }

    /** 插件在调度器里的任务名。 */
    private function pluginTaskNames(): array
    {
        return array_map(
            static fn($t): string => $t->name,
            $this->dispatcher()->bySource('plugin:' . self::PLUGIN)
        );
    }

    /** 插件之外（主应用）的任务总数，用于断言「不误伤」。 */
    private function foreignTaskCount(): int
    {
        return \count($this->dispatcher()->registered()) - \count($this->pluginTaskNames());
    }

    // ------------------------------------------------------------------
    // 初始状态
    // ------------------------------------------------------------------

    public function testFixtureRegistersTwoPluginTasksAtBoot(): void
    {
        self::assertSame(
            ['sync_fixture.sync', 'sync_fixture.digest'],
            $this->pluginTaskNames(),
            '夹具插件 boot() 内 addCron() 的两条任务必须带 plugin:sync_fixture 来源'
        );
        self::assertContains('sync_fixture.sync', $this->dueNames());
        self::assertContains('sync_fixture.digest', $this->dueNames());
        self::assertSame(0, PluginScheduleSync::sync(self::PLUGIN, 'not-a-real-status'),
            '未知状态必须原样不动');
    }

    // ------------------------------------------------------------------
    // paused：禁用但保留注册
    // ------------------------------------------------------------------

    public function testPausedPluginStopsFiringButKeepsRegistration(): void
    {
        $handled = PluginScheduleSync::sync(self::PLUGIN, PluginMarket::PAUSED);

        self::assertSame(2, $handled, '应处置该插件的两条任务');
        self::assertNotContains('sync_fixture.sync', $this->dueNames(), '禁用后不应再被派发');
        self::assertNotContains('sync_fixture.digest', $this->dueNames());
        self::assertSame(
            ['sync_fixture.sync', 'sync_fixture.digest'],
            $this->pluginTaskNames(),
            '暂停只禁用不删除：恢复时无需重新注册'
        );
        self::assertFalse($this->dispatcher()->find('sync_fixture.sync')->enabled);
        self::assertSame(
            [
                ['name' => 'sync_fixture.sync', 'expression' => '* * * * *', 'enabled' => false, 'target' => \Closure::class . '::__invoke'],
                ['name' => 'sync_fixture.digest', 'expression' => '0 * * * *', 'enabled' => false, 'target' => \Closure::class . '::__invoke'],
            ],
            PluginScheduleSync::tasksOf(self::PLUGIN),
            'tasksOf() 应给出含禁用占位的完整快照，供排障定位'
        );
    }

    public function testPausedThenEnabledRestoresFiringWithoutRestart(): void
    {
        PluginScheduleSync::sync(self::PLUGIN, PluginMarket::PAUSED);
        self::assertNotContains('sync_fixture.sync', $this->dueNames());

        $handled = PluginScheduleSync::sync(self::PLUGIN, PluginMarket::ENABLED);

        self::assertSame(2, $handled, '恢复时应把两条禁用任务翻回启用');
        self::assertContains('sync_fixture.sync', $this->dueNames(), '恢复后无需重启即重新调度');
        self::assertContains('sync_fixture.digest', $this->dueNames());
        self::assertTrue($this->dispatcher()->find('sync_fixture.digest')->enabled);
    }

    public function testTogglingStatusIsIdempotentOnRepeatedCalls(): void
    {
        PluginScheduleSync::sync(self::PLUGIN, PluginMarket::PAUSED);

        // 已是目标状态：setEnabled 不再替换记录，处置数应为 0。
        self::assertSame(0, PluginScheduleSync::sync(self::PLUGIN, PluginMarket::PAUSED));
        self::assertNotContains('sync_fixture.sync', $this->dueNames());

        self::assertSame(2, PluginScheduleSync::sync(self::PLUGIN, PluginMarket::ENABLED));
        self::assertSame(0, PluginScheduleSync::sync(self::PLUGIN, PluginMarket::ENABLED));
        self::assertContains('sync_fixture.sync', $this->dueNames());
    }

    // ------------------------------------------------------------------
    // uninstalled：彻底移除
    // ------------------------------------------------------------------

    public function testUninstalledPluginRemovesEveryTaskOfItsOwn(): void
    {
        $handled = PluginScheduleSync::sync(self::PLUGIN, PluginMarket::UNINSTALLED);

        self::assertSame(2, $handled);
        self::assertSame([], PluginScheduleSync::tasksOf(self::PLUGIN), '卸载后快照应为空');
        self::assertNull($this->dispatcher()->find('sync_fixture.sync'), '卸载后注册表不应再查到');
        self::assertFalse($this->dispatcher()->runOnce('sync_fixture.digest'),
            '卸载后 runOnce 也不应命中（避免已卸载插件的回调继续执行）');
        self::assertNotContains('sync_fixture.sync', $this->dueNames());
    }

    public function testUninstallIsReversibleByReRegistering(): void
    {
        PluginScheduleSync::sync(self::PLUGIN, PluginMarket::UNINSTALLED);

        // 重新安装 = 重新 boot 一次：同名任务应被幂等替换，而不是出现两条。
        $this->bootFixturePlugin();

        self::assertSame(
            ['sync_fixture.sync', 'sync_fixture.digest'],
            $this->pluginTaskNames(),
            '卸载→重装不应在注册表里残留重复条目'
        );
        self::assertContains('sync_fixture.sync', $this->dueNames(), '重装后任务必须重新生效');
        self::assertSame(2, PluginScheduleSync::sync(self::PLUGIN, PluginMarket::UNINSTALLED),
            '重复注册不得导致卸载时处置出多余条目');
    }

    // ------------------------------------------------------------------
    // 不误伤
    // ------------------------------------------------------------------

    public function testMainApplicationTasksAreUntouchedAcrossAllTransitions(): void
    {
        $baseline = $this->foreignTaskCount();
        self::assertGreaterThan(0, $baseline, '骨架应自带主应用定时任务作为对照组');

        $before = $this->dueNames();
        PluginScheduleSync::sync(self::PLUGIN, PluginMarket::PAUSED);
        PluginScheduleSync::sync(self::PLUGIN, PluginMarket::ENABLED);
        PluginScheduleSync::sync(self::PLUGIN, PluginMarket::UNINSTALLED);

        self::assertSame($baseline, $this->foreignTaskCount(), '主应用任务总数不得变化');
        self::assertEqualsCanonicalizing(
            array_diff($before, ['sync_fixture.sync', 'sync_fixture.digest']),
            array_diff($this->dueNames(), ['sync_fixture.sync', 'sync_fixture.digest']),
            '主应用任务的派发集合不得变化'
        );
    }

    // ------------------------------------------------------------------
    // 容错
    // ------------------------------------------------------------------

    public static function unknownInputProvider(): array
    {
        return [
            '未安装插件' => ['__no_such_plugin__', PluginMarket::PAUSED],
            '未知状态'   => [self::PLUGIN, 'archived'],
            '空插件名'   => ['', PluginMarket::ENABLED],
            '空状态'     => [self::PLUGIN, ''],
        ];
    }

    #[DataProvider('unknownInputProvider')]
    public function testSyncIsNoopForUnknownInput(string $name, string $status): void
    {
        $before = $this->dueNames();

        self::assertSame(0, PluginScheduleSync::sync($name, $status), '未知输入必须返回 0 且不抛异常');
        self::assertSame($before, $this->dueNames(), '未知输入不得改动任何任务');
        self::assertSame([], PluginScheduleSync::tasksOf('__no_such_plugin__'));
    }
}

/**
 * 夹具：boot() 内注册两条内联闭包 cron（无需容器解析，测试环境安全）。
 */
final class SyncFixturePlugin implements PluginInterface
{
    public function name(): string
    {
        return PluginScheduleSyncTest::PLUGIN;
    }

    public function register(PluginManager $manager): void
    {
    }

    public function boot(PluginManager $manager): void
    {
        // 两条表达式都在断言用的 10:00:00 到期，便于直接断言「应/不应派发」。
        $manager->addCron('* * * * *', static function (): void {}, 'sync_fixture.sync', '每分钟心跳');
        $manager->addCron('0 * * * *', static function (): void {}, 'sync_fixture.digest', '每小时汇总');
    }
}
