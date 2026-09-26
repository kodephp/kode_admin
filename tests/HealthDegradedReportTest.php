<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Application;
use Kode\Framework\Health\HealthChecker;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Kode;

/**
 * `/health` 的降级路径必须真的会亮红灯。
 *
 * 本仓用 `app/routes.php` 里那条 `/health` **覆盖**了框架内置的同名探针（覆盖这件事本身
 * 记在 `tests/RouteReachabilityTest.php` 的 INTENTIONAL_SHADOWS 里）。覆盖的理由有两条，
 * 而这两条都属于「覆盖之后很容易忘记补」的那类：
 *
 *  1. 框架那条的 `status` 是写死的 `'ok'`（`HttpServiceProvider::registerHealthEndpoints()`），
 *     探针全挂也照样回 200 + ok —— 监控上等于装了一个永远不亮的灯；本仓那条按 `healthy`
 *     翻成 `degraded` + 503。
 *  2. 版本口径：`version` 必须是框架内核版本，运维还要能看到骨架版本（`app_version`）
 *     和 HTTP 内核版本（`http`），覆盖时漏掉任何一个，`kode route:list` 之外唯一的外部
 *     巡检口就说谎了。
 *
 * 此前这两条只有注释，没有任何一条用例真的走到 503 那一支：`HEALTH_CHECK_DB=false`
 * 让 db 探针在测试环境直接跳过，而能力感知探针「未接线即 not_configured、不计入失败」，
 * 于是 `/health` 在测试里恒绿。恒绿的探针和被覆盖掉的常量探针在断言上长得一模一样。
 *
 * 所以这里把 `HealthChecker` 换成一个带**失败闭包探针**的实例（`config/health.php` 的
 * `checks` 支持任意闭包，签名 `fn($c) => 'ok' | 'error: ...'`，与运维真配出来的坏探针同形），
 * 然后逐条验：判定、状态码、明细脱敏、以及「探针健康时仍是 200」的对照。
 *
 * 注意别把「脱敏」写成「隐藏判定」：§4 要求失败明细仅 debug 可见，但 503/degraded 必须照出，
 * 否则 k8s 的 readinessProbe 摘不到流。
 */
final class HealthDegradedReportTest extends TestCase
{
    /** 探针失败文本：故意带上连接串与凭证形状，用来验它不会原样出网。 */
    private const PROBE_ERROR = 'error: SQLSTATE[08006] connection to server at 10.255.255.1 port 5432 failed: password=Sup3rSecretNotForExport';

    protected bool $independentApp = true;

    private ?HealthChecker $original = null;

    private mixed $originalDebug = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));

        // 覆盖后必须原样归还：容器与 config 都是进程级的，
        // 下一个复用该实例的测试类不该看到一个坏探针。
        $this->original = resolve(HealthChecker::class);
        $this->originalDebug = config('app.debug');
    }

    protected function tearDown(): void
    {
        if ($this->original !== null) {
            app()->container->instance(HealthChecker::class, $this->original);
        }
        if ($this->originalDebug !== null) {
            $this->app()->config()->set('app.debug', $this->originalDebug);
        }

        parent::tearDown();
    }

    /** @param array<string, callable|bool> $checks */
    private function installChecker(array $checks): void
    {
        app()->container->instance(HealthChecker::class, new HealthChecker(['checks' => $checks]));
    }

    public function test_a_failing_probe_turns_the_report_red(): void
    {
        $this->installChecker(['zz_dep' => static fn (): string => self::PROBE_ERROR]);

        $response = $this->get('/health');
        $body = $response->json();

        self::assertSame(503, $response->status(), '探针失败仍回 200：readiness 摘不到流');
        self::assertSame('degraded', $body['status'] ?? null);
        // 被覆盖的那条框架实现把 status 写死成 ok；出现 'ok' 说明覆盖没生效（或路由顺序变了）。
        self::assertNotSame('ok', $body['status'] ?? null);
    }

    public function test_the_verdict_is_driven_by_the_probe_not_a_constant(): void
    {
        // 正对照：同一个端点、换一个 ok 探针 —— 必须 200 + ok。
        // 少了这条，上面那个断言可以靠「这条路由恒回 503」蒙过去。
        $this->installChecker(['zz_dep' => static fn (): string => 'ok']);

        $response = $this->get('/health');

        self::assertSame(200, $response->status());
        self::assertSame('ok', $response->json()['status'] ?? null);
    }

    public function test_the_failure_detail_is_masked_when_debug_is_off(): void
    {
        $this->app()->config()->set('app.debug', false);
        $this->installChecker(['zz_dep' => static fn (): string => self::PROBE_ERROR]);

        $components = $this->get('/health')->json()['components'] ?? [];

        self::assertSame('error', $components['zz_dep'] ?? null, '未脱敏：探针文本会原样出网');
        $raw = $this->get('/health')->body();
        self::assertStringNotContainsString('10.255.255.1', $raw);
        self::assertStringNotContainsString('Sup3rSecretNotForExport', $raw);
        self::assertStringNotContainsString('SQLSTATE', $raw);
        // 脱敏只针对明细，不针对判定本身。
        self::assertStringContainsString('degraded', $raw);
    }

    public function test_the_failure_detail_is_verbatim_when_debug_is_on(): void
    {
        // 排障时看得到的必须就是探针说的那句话，否则 §6「看 location/chain」的路走不通。
        $this->app()->config()->set('app.debug', true);
        $this->installChecker(['zz_dep' => static fn (): string => self::PROBE_ERROR]);

        $components = $this->get('/health')->json()['components'] ?? [];

        self::assertSame(self::PROBE_ERROR, $components['zz_dep'] ?? null);
    }

    public function test_an_unreachable_component_is_reported_but_not_counted_as_failure(): void
    {
        // 能力感知探针的口径（config/health.php 头注）：未接线 = not_configured，不误报。
        // 这一条挡住「把 not_configured 也翻成 error」的改动 —— 那会让每个新环境都起红灯。
        $this->app()->config()->set('app.debug', false);
        $this->installChecker([]);

        $response = $this->get('/health');
        $body = $response->json();

        self::assertSame(200, $response->status());
        self::assertSame('ok', $body['status'] ?? null);
        self::assertContains($body['components']['config_center'] ?? '', ['not_configured', 'ok']);
        self::assertSame('ok', $body['components']['app'] ?? null, 'app 自身探针恒 ok，覆盖时必须留着');
    }

    public function test_the_override_still_states_which_versions_it_is_running(): void
    {
        // 版本口径是被覆盖掉最容易丢的一半：内核 / 骨架 / HTTP 三个版本各有用途。
        // app_version 走 config 而非硬编码，所以这里比对的是「同源值」而不是字面量。
        $this->installChecker([]);

        $body = $this->get('/health')->json();

        self::assertSame('kode', $body['framework'] ?? null);
        self::assertSame(Application::VERSION, $body['version'] ?? null);
        self::assertSame(Kode::VERSION, $body['http'] ?? null);
        self::assertSame(config('app.version'), $body['app_version'] ?? null);
    }
}
