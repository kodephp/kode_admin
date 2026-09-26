<?php

declare(strict_types=1);

namespace app\tests;

use app\support\PluginHealthChecker;
use app\support\PluginMarket;
use app\support\PluginRuntimeDetail;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use Kode\Framework\Testing\TestCase;

/**
 * 插件运行时诊断的回归：健康报告的每一条文案都得是真数出来的，不是模板没填完。
 *
 * 这一面曾同时坏在两处，而且都是「看起来正常」的坏法：
 *  1. `checkCrons()` / `PluginRuntimeDetail::crons()` 调 `ScheduleDispatcher::tasks()`——
 *     调度器上没有这个方法（`tasks()` 属于 `Scheduler`），foreach 处抛 \Error，
 *     被 `catch (\Throwable)` 吞成「调度器不可解析」/ 空数组。
 *     插件页的定时任务 tab 因此**恒空**，而空数组和「这个插件确实没任务」长得一样；
 *     FrameworkReferenceGateTest 只核对类名存在，方法名打错它全绿。
 *  2. detail 文案里的 `{count}` / `{declared}` / `{missing}` 占位符没有任何一处插值
 *     （全仓 grep 无命中），健康弹窗原样显示「已注册 {count} 条路由」。
 *
 * 所以这里三条都测：方法真的能查到东西（注册→查到→注销→查不到，正负对照）、
 * 文案不含未替换的花括号、以及调度器方法名写错时先红在这里。
 */
final class PluginHealthReportTest extends TestCase
{
    /** 探针任务名：带 zz 前缀确保不与任何真实任务重名（重名会顶掉别人的调度）。 */
    public const PROBE_TASK = 'zz-regression-cron-probe';

    public const PROBE_SOURCE = 'plugin:zzprobe';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        $this->dispatcher()->unregister(self::PROBE_TASK);

        parent::tearDown();
    }

    private function dispatcher(): ScheduleDispatcher
    {
        return resolve(ScheduleDispatcher::class);
    }

    /** 声明了一条 cron 的桩插件（真实插件里目前没有 crons()，桩才能覆盖「声明了」分支）。 */
    private function stubPlugin(): object
    {
        return new class {
            /** @return list<array{name: string, expression: string}> */
            public function crons(): array
            {
                return [['name' => PluginHealthReportTest::PROBE_TASK, 'expression' => '*/5 * * * *']];
            }
        };
    }

    /** @return array<string, mixed> */
    private function callCheckCrons(string $name): array
    {
        $ref = new \ReflectionMethod(PluginHealthChecker::class, 'checkCrons');
        $ref->setAccessible(true);

        return (array) $ref->invokeArgs(null, [$name, $this->stubPlugin()]);
    }

    /**
     * 调度器必须真的有这个方法。
     *
     * 写错方法名不会有任何报错——它正是本用例要防的那类缺陷，
     * 所以先把「契约方法存在」钉死，再谈行为。
     */
    public function test_dispatcher_exposes_the_method_the_checker_calls(): void
    {
        self::assertTrue(
            method_exists(ScheduleDispatcher::class, 'bySource'),
            'ScheduleDispatcher::bySource() 不在了：健康检查与运行时详情都按它取插件任务'
        );
        self::assertFalse(
            method_exists(ScheduleDispatcher::class, 'tasks'),
            '若上游补了 tasks()，请连同两处调用点一起复核（本文件按 bySource 写死）'
        );
    }

    public function test_cron_check_counts_tasks_registered_by_the_plugin(): void
    {
        $this->dispatcher()->addCron(
            self::PROBE_TASK,
            '*/5 * * * *',
            static fn () => null,
            ['source' => self::PROBE_SOURCE, 'description' => '回归探针']
        );

        $result = $this->callCheckCrons('zzprobe');

        self::assertTrue($result['applicable'], '声明了 crons() 就必须是真 applicable：' . (string) $result['detail']);
        self::assertTrue($result['ok'], '调度器里真有这条任务却判不健康：' . (string) $result['detail']);
        self::assertSame(1, $result['count']);
        self::assertSame(self::PROBE_TASK, $result['tasks'][0]['name']);
        self::assertSame('*/5 * * * *', $result['tasks'][0]['expression']);
        self::assertSame('全部 1 条定时任务已注册', $result['detail']);
    }

    /**
     * 负向对照：注销后必须回落到 0。
     *
     * 只有上一条正向断言的话，「把声明数当注册数返回」也能过——
     * 那等于健康检查只是在念插件自己的声明，从没看过调度器。
     */
    public function test_cron_check_reports_missing_registration_as_unhealthy(): void
    {
        $result = $this->callCheckCrons('zzprobe');

        self::assertTrue($result['applicable']);
        self::assertFalse($result['ok'], '声明了却没注册，必须判异常而不是「查不到就算了」');
        self::assertSame(0, $result['count']);
        self::assertSame('0/1 条定时任务已注册', $result['detail']);
    }

    /** 运行时详情侧同一个缺陷的第二处：面板 tab 直接读它。 */
    public function test_runtime_detail_lists_plugin_crons(): void
    {
        $this->dispatcher()->addCron(
            self::PROBE_TASK,
            '*/5 * * * *',
            static fn () => null,
            ['source' => self::PROBE_SOURCE]
        );

        $ref = new \ReflectionMethod(PluginRuntimeDetail::class, 'crons');
        $ref->setAccessible(true);
        $rows = (array) $ref->invokeArgs(null, ['zzprobe']);

        self::assertCount(1, $rows, '定时任务 tab 拿不到调度器里的任务');
        self::assertSame(self::PROBE_TASK, $rows[0]['name']);
    }

    /**
     * 全量扫描：每只插件的每条 detail 都不留花括号占位符。
     *
     * 判据用 `{\w+}` 而不是逐个词表，因为占位符是随手写出来的——
     * 下次新增 check 时再漏一个词，词表型断言发现不了。
     */
    public function test_no_detail_string_keeps_an_uninterpolated_placeholder(): void
    {
        $seen = 0;
        foreach (PluginMarket::discover() as $name => $class) {
            $plugin = new $class();
            $health = PluginHealthChecker::check($plugin);

            self::assertMatchesRegularExpression(
                '/^(healthy|degraded|unhealthy)$/',
                $health['status'],
                "插件 {$name} 的状态取值非法"
            );

            foreach ($health['checks'] as $key => $check) {
                $seen++;
                $detail = (string) ($check['detail'] ?? '');
                self::assertNotSame('', $detail, "插件 {$name} 的 {$key} 没有 detail 文案");
                self::assertDoesNotMatchRegularExpression(
                    '/\{[a-z_]+\}/',
                    $detail,
                    "插件 {$name} 的 {$key} 文案里留着未插值的占位符：{$detail}"
                );
            }
        }

        // 防空转：一只插件都没扫到就等于什么都没测。
        self::assertGreaterThan(20, $seen, '健康检查项总数异常偏低，用例可能没真跑');
    }

    /**
     * 未注册任何东西的插件不得被整只判成异常。
     *
     * 路由/配置/菜单这些面「没有」是正常形态（组件插件本就 0 路由）。
     * 一旦把「没有」算成 failed，列表里每只新装插件都是黄色，
     * 黄色就失去意义了——真断了反而没人看。
     *
     * 期望是「1 项通过」：routes 是唯一恒 applicable 的信息行（契约里没有 routes 能力，
     * 没有「声明了却没注册」的对账基准），它只报数、不判健康。
     */
    public function test_a_minimal_plugin_is_not_reported_degraded_for_empty_surfaces(): void
    {
        $stub = new class {
            public function name(): string
            {
                return 'zzprobe';
            }
        };

        $health = PluginHealthChecker::check($stub);

        self::assertSame('healthy', $health['status'], (string) json_encode($health['checks'], JSON_UNESCAPED_UNICODE));
        self::assertSame('1 项通过 / 0 项异常 / 8 项不适用', $health['summary']);
    }
}
