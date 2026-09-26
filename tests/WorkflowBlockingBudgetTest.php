<?php

declare(strict_types=1);

namespace app\tests;

use app\tests\Support\LocalFetchServer;
use Kode\Framework\Testing\TestCase;
use Kode\Plugins\frontend\WorkflowBridgeController;

/**
 * 工作流步骤里的三处阻塞（curl 超时、内层重试退避、外层重试退避）必须看剩余预算。
 *
 * 治的是这个形状：`timeout_ms` 只在工作流**步骤之间**查，步骤内部的阻塞全都不查，
 * 于是各层预算相乘 —— 单个 api_call 最坏 timeout(60s) ×（1 + 3 次重试）+ 退避，
 * 外层再乘（1 + 5），一个坏上游能把 worker 按住二十多分钟。11 worker 的常驻服务里
 * 五个人同时点「执行」就等于把整个后台锁死（AGENTS.md §5 热路径禁止无界阻塞）。
 *
 * 为什么不是「把 max_request 调小」或「交给队列」：native 运行时没有任何按请求的看门狗
 * （kode/process 只有 SIGTERM 后排空），`SERVER_MAX_REQUEST` 又是**处理完请求之后**才回收，
 * 对一个正卡在 curl 上的 worker 完全无效。同步执行是既有形态，能改的是「等过界」。
 *
 * 因此这里锁的是判据本身：`clampWaitMs()` 一处算清「允许等多久」，三处阻塞都问它；
 * 预算耗尽时**少试而不是试完**，出参必须把两者区分开（排障结论不同）。
 */
final class WorkflowBlockingBudgetTest extends TestCase
{
    private const CEILING = 10_000;

    private ?LocalFetchServer $server = null;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            $this->server->stop();
            $this->server = null;
        }
        foreach ($this->envBackup as $key => $value) {
            $this->putEnv($key, $value);
        }
        $this->envBackup = [];

        parent::tearDown();
    }

    /* ==================== 判据：clampWaitMs ==================== */

    public function test_clamp_uses_the_ceiling_when_no_deadline_was_passed(): void
    {
        // 拿不到 deadline（0）也必须有个顶：否则「忘了传」等于回到无界等待
        self::assertSame(self::CEILING, $this->clamp(0, 60_000));
        self::assertSame(500, $this->clamp(0, 500));
        self::assertSame(0, $this->clamp(0, 0));
        self::assertSame(0, $this->clamp(0, -1_000));
    }

    public function test_clamp_returns_zero_once_the_budget_is_gone(): void
    {
        $past = microtime(true) - 1.0;
        self::assertSame(0, $this->clamp($past, 5_000), '预算已尽还允许睡 5 秒就是无界等待');
        self::assertSame(0, $this->clamp(microtime(true), 5_000));
    }

    public function test_clamp_shrinks_to_the_remaining_budget(): void
    {
        $left = 800;
        $deadline = microtime(true) + $left / 1000;
        $got = $this->clamp($deadline, 5_000);
        // 取整只向下，所以允许值只会比剩余时间小；上限留 300ms 余量防调度抖动
        self::assertLessThanOrEqual($left, $got, '给多了：睡完会越过 deadline');
        self::assertGreaterThanOrEqual($left - 300, $got, '给少了：还没到点就放弃重试');
    }

    /* ==================== delay 步骤 ==================== */

    public function test_delay_step_sleeps_fully_when_unconstrained(): void
    {
        $t0 = microtime(true);
        $r = $this->delay(['seconds' => 0.3], 0);
        $elapsed = (int) ((microtime(true) - $t0) * 1000);

        self::assertSame(300, (int) $r['target_ms']);
        self::assertGreaterThanOrEqual(250, $elapsed, '对照组：没 deadline 就该睡满');
        self::assertFalse((bool) $r['cut_by_deadline']);
    }

    public function test_delay_step_does_not_sleep_past_the_budget(): void
    {
        $deadline = microtime(true) + 0.1;
        $t0 = microtime(true);
        $r = $this->delay(['seconds' => 2], $deadline);
        $elapsed = (int) ((microtime(true) - $t0) * 1000);

        self::assertLessThan(1_000, $elapsed, "睡了 {$elapsed}ms：delay 无视 deadline（应为 ~100ms）");
        self::assertSame(2000, (int) $r['target_ms'], '目标时长仍要如实上报，别把掐断伪装成作者本意');
        self::assertTrue((bool) $r['cut_by_deadline']);
        self::assertLessThan((int) $r['target_ms'], (int) $r['delayed_ms']);
    }

    /* ==================== api_call：三处阻塞 ==================== */

    public function test_api_call_with_expired_budget_never_touches_the_network(): void
    {
        $past = microtime(true) - 1.0;

        // 连闸门都还没问：预算没了就不该有第二次「先算再拒」的余地
        $r = $this->apiCall(['url' => 'file:///etc/passwd', 'timeout' => 30], $past);
        self::assertTrue((bool) ($r['skipped_by_deadline'] ?? false), json_encode($r, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('预算已用尽', (string) ($r['error'] ?? ''));
        self::assertArrayNotHasKey('attempts', $r, '未发起的请求不该报告尝试次数');
    }

    public function test_api_call_backoff_stops_at_the_deadline_instead_of_sleeping_it_out(): void
    {
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'true');
        // 拒绝连接的端口：每次尝试几乎瞬时失败，于是耗时只可能来自退避
        $config = [
            'url' => 'http://127.0.0.1:1/nope',
            'timeout' => 5,
            'max_retries' => 3,
            'backoff_ms' => 5_000,
        ];

        $t0 = microtime(true);
        $r = $this->apiCall($config, microtime(true) + 0.6);
        $elapsed = (int) ((microtime(true) - $t0) * 1000);

        self::assertLessThan(2_000, $elapsed, "等了 {$elapsed}ms：5s/10s/20s 的退避照睡不误");
        self::assertTrue((bool) ($r['retries_cut_by_deadline'] ?? false), json_encode($r, JSON_UNESCAPED_UNICODE));
        self::assertLessThan(4, (int) $r['attempts'], '被预算掐断却报「试满 4 次」= 谎报');
    }

    public function test_curl_timeout_is_compressed_into_remaining_budget(): void
    {
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'true');
        $server = $this->server();

        $t0 = microtime(true);
        $r = $this->apiCall(
            ['url' => $server->url('/slow?ms=2500'), 'timeout' => 30],
            microtime(true) + 1.0
        );
        $elapsed = (int) ((microtime(true) - $t0) * 1000);

        self::assertFalse((bool) ($r['success'] ?? false), json_encode($r, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('请求失败', (string) ($r['error'] ?? ''));
        self::assertLessThan(2_000, $elapsed, "等了 {$elapsed}ms：timeout=30 原样递给 curl，等于 deadline 形同虚设");
    }

    /* ==================== executeStep：外层重试 ==================== */

    public function test_execute_step_returns_at_once_when_the_budget_is_already_gone(): void
    {
        $config = [
            'url' => 'http://127.0.0.1:1/nope',
            'timeout' => 5,
            'max_retries' => 5,
            'backoff_ms' => 5_000,
        ];

        $t0 = microtime(true);
        $r = $this->step('api_call', $config, microtime(true) - 1.0);
        $elapsed = (int) ((microtime(true) - $t0) * 1000);

        self::assertLessThan(500, $elapsed, "单步耗时 {$elapsed}ms：预算耗尽还在重试循环里");
        // 「一次都没发出去」和「发过但不再重试」要能分开：只有内层知道前者
        self::assertStringContainsString('未发起请求', (string) ($r['error'] ?? ''), json_encode($r, JSON_UNESCAPED_UNICODE));
        self::assertTrue((bool) ($r['skipped_by_deadline'] ?? false));
        // 「被预算掐断」和「试完都失败」是两种结论：前者要查 timeout_ms，后者要查上游
        self::assertArrayNotHasKey('_retries_exhausted', $r, json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    public function test_execute_step_still_reports_exhausted_when_retries_truly_run_out(): void
    {
        // 反空转：上一例断言「不带 _retries_exhausted」，这里必须带上，否则是标记写丢了
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'true');
        $config = [
            'url' => 'http://127.0.0.1:1/nope',
            'timeout' => 5,
            'max_retries' => 1,
            'backoff_ms' => 100,
        ];

        $r = $this->step('api_call', $config, 0.0);

        self::assertTrue((bool) ($r['_retries_exhausted'] ?? false), json_encode($r, JSON_UNESCAPED_UNICODE));
        self::assertSame(2, (int) ($r['_attempt'] ?? 0));
        self::assertStringContainsString('请求失败', (string) ($r['error'] ?? ''));
    }

    public function test_step_level_timeout_is_attributed_before_the_workflow_budget(): void
    {
        // 两级 deadline 同时过期时，作者显式写的 `timeout_ms` 必须先解释这步为什么停：
        // 换成「工作流预算」的消息，排障就会被引去查 timeout_ms 以外的地方。
        // （运行时两种分支都是即时返回，耗时分不出差异，所以钉的是判定顺序本身。）
        $src = $this->source();
        $step = strpos($src, '$result[\'error\'] = "步骤超时');
        $flow = strpos($src, "'工作流预算已用尽，未再重试'");
        self::assertNotFalse($step, '步骤超时的归类被删了');
        self::assertNotFalse($flow, '工作流预算的归类被删了：越过 deadline 会伪装成普通失败');
        self::assertLessThan($flow, $step, '判定顺序被调换');
    }

    /* ==================== 静态门禁：不许再出现裸 sleep ==================== */

    public function test_every_sleep_on_the_step_path_asks_the_budget_first(): void
    {
        $src = $this->source();

        $sleeps = substr_count($src, 'usleep(');
        $clamped = substr_count($src, '$this->clampWaitMs(');
        self::assertGreaterThan(0, $clamped);
        self::assertSame(
            $clamped,
            $sleeps,
            "有 {$sleeps} 处 usleep 但只有 {$clamped} 处问过预算：新增阻塞必须走 clampWaitMs"
        );
        self::assertStringContainsString('private const MAX_WAIT_MS', $src, '天花板不能拆：拆了 deadline=0 就是无界');
    }

    public function test_blocking_primitives_are_thread_the_deadline_through_the_dispatch(): void
    {
        // 判据写在 clampWaitMs 里，但前提是 deadline 真传到了各阻塞点。
        // dispatch 是唯一的调用面，这里钉住它别把参数漏回去（漏了就等于静默失效）。
        $src = $this->source();
        foreach (['api_call', 'delay', 'loop', 'sub_workflow', 'switch'] as $type) {
            self::assertMatchesRegularExpression(
                '/\'' . $type . '\' => \$this->execute\w+\(\s*\$config,\s*\$context([^\n]*?)\),/',
                $src,
                $type . ' 分支未接 dispatch'
            );
            preg_match(
                '/\'' . $type . '\' => \$this->execute\w+\(\s*\$config,\s*\$context([^\n]*?)\),/',
                $src,
                $m
            );
            self::assertStringContainsString(
                '$deadline',
                (string) ($m[1] ?? ''),
                $type . ' 没把 deadline 传进去：clamp 只能拿到 0，等于不判预算'
            );
        }
    }

    /* ==================== 口径：编辑器照着填，校验器照着拒 ==================== */

    public function test_schema_bounds_match_the_validator(): void
    {
        // 可视化编辑器照 stepTypeCatalog() 出输入框，validateSteps() 照另一份数字拒收。
        // 两份数字各写一遍就会漂移（此前 seconds 标着 1..3600、校验器只放行 0..5：
        // 作者按界面提示填 60 秒，存盘才发现被拒，而被拒的理由没人看得见）。
        $catalog = $this->catalog();
        $api = $catalog['api_call']['config_schema'];
        $delay = $catalog['delay']['config_schema'];

        $bounds = [
            ['api_call', 'timeout', (int) $api['timeout']['min'], (int) $api['timeout']['max']],
            ['api_call', 'max_retries', (int) $api['max_retries']['min'], (int) $api['max_retries']['max']],
            ['api_call', 'backoff_ms', (int) $api['backoff_ms']['min'], (int) $api['backoff_ms']['max']],
            ['delay', 'seconds', (int) $delay['seconds']['min'], (int) $delay['seconds']['max']],
        ];

        foreach ($bounds as [$type, $field, $min, $max]) {
            self::assertNull(
                $this->validate($type, $field, $max),
                "{$type}.{$field} 取 schema 上限 {$max} 应当存得下去（否则编辑器恒出一个填不进的值）"
            );
            self::assertNotNull(
                $this->validate($type, $field, $max + 1),
                "{$type}.{$field} 越过上限 {$max} 必须被拒：静默夹取等于替作者决定等多久"
            );
            self::assertGreaterThanOrEqual(
                $min,
                (int) ($catalog[$type]['default_config'][$field] ?? $min),
                "{$type}.{$field} 的默认值落在自己的区间之外"
            );
        }
    }

    /* ==================== 工具 ==================== */

    /** @return array<string, array<string, mixed>> */
    private function catalog(): array
    {
        /** @var array<string, array<string, mixed>> $r */
        $r = $this->invoke('stepTypeCatalog', []);

        return $r;
    }

    /**
     * 只把被测字段推到边界，其余必填项照 default 补齐。
     *
     * @return array<string, mixed>
     */
    private function stepConfig(string $type, string $field, int|float $value): array
    {
        $base = $type === 'api_call'
            ? ['url' => 'https://example.invalid/x', 'timeout' => 5, 'max_retries' => 0, 'backoff_ms' => 100]
            : ['seconds' => 1];
        $base[$field] = $value;

        return $base;
    }

    private function validate(string $type, string $field, int|float $value): ?string
    {
        $steps = [['type' => $type, 'name' => 's1', 'config' => $this->stepConfig($type, $field, $value)]];
        $r = $this->invoke('validateSteps', [$steps, true]);

        return $r === null ? null : (string) $r;
    }

    private function source(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__) . '/plugins/frontend/WorkflowBridgeController.php'
        );
    }

    private function clamp(float $deadline, int $wantMs): int
    {
        return (int) $this->invoke('clampWaitMs', [$deadline, $wantMs]);
    }

    /** @return array<string, mixed> */
    private function delay(array $config, float $deadline): array
    {
        /** @var array<string, mixed> $r */
        $r = $this->invoke('executeDelay', [$config, [], $deadline]);

        return $r;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function apiCall(array $config, float $deadline): array
    {
        /** @var array<string, mixed> $r */
        $r = $this->invoke('executeApiCall', [$config, [], $deadline]);

        return $r;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function step(string $type, array $config, float $deadline): array
    {
        /** @var array<string, mixed> $r */
        $r = $this->invoke('executeStep', [$type, $config, [], 0, $deadline, false]);

        return $r;
    }

    private function invoke(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(WorkflowBridgeController::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs(new WorkflowBridgeController(), $args);
    }

    private function server(): LocalFetchServer
    {
        if ($this->server === null) {
            $root = sys_get_temp_dir() . '/kode-budget-root-' . bin2hex(random_bytes(6));
            mkdir($root, 0775, true);
            try {
                $this->server = new LocalFetchServer($root);
                $this->server->start();
            } catch (\Throwable $e) {
                $this->removeTree($root);
                self::markTestSkipped('本地 php -S 起不来：' . $e->getMessage());
            }
        }

        return $this->server;
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->envBackup)) {
            $this->envBackup[$key] = getenv($key);
        }
        $this->putEnv($key, $value);
    }

    private function putEnv(string $key, string|false $value): void
    {
        // env() 读 $_ENV / $_SERVER / getenv 三处，必须一起改，否则留着上一例的值
        if ($value === false) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
