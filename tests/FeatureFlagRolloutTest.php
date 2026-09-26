<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\FeatureFlagController;
use app\tests\Support\SourceScan;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * 灰度比例（`kode_feature_flags.rollout`）的入参口径与分桶主体口径（AGENTS.md §4）。
 *
 * 挡的是两件事，都不是「参数不合法」这么抽象：
 *
 *  1. `store()`/`update()` 里的 `max(0, min(100, (int) $this->input('rollout')))` 是**替操作者
 *     决定灰度开多大**。这个列直接喂给 `check()` 的分桶比较（`bucket < rollout`），
 *     所以每一次静默夹取都是一次真实的放量动作，实测：
 *      - `rollout: 1000` → 100 → **对全量用户放开**（打错一个 0 就把 10% 灰度变成全量）；
 *      - `rollout: -1` → 0 → 对全量关闭；
 *      - `rollout: "50人"` → 50（尾部垃圾被吃掉，操作者以为设的是别的数）；
 *      - `rollout: "abc"` / `true` → 0 / 1 → 一个「什么都没开」、一个「开了 1%」，
 *        而两者都回 `{code:200, action:"updated"}`，页面与审计账本都不会说「你给的数我没看懂」。
 *     口径与 `stepsFor()`/`retentionDays()` 同族：要么是一个 0..100 的整数，要么 400，
 *     判据只写一份（两个写入口共用），并且**先拒再写**。
 *
 *  2. `check()` 在 `user_id` 缺失时返回 `bucket: 0`，于是任何 `0<rollout<100` 的开关
 *     对**每一个不带主体的调用**都回 `enabled: true, reason: "in_rollout"`。管理端的
 *     「判定测试」面板就是这么发的（`user_id` 留空即 `undefined`），所以它是这个假象的
 *     一线消费者：灰度 10% 的开关在页面上显示「命中」，而这句话的主语是「没有这个人」。
 *     分桶的前提是有一个人可以分桶，缺主体只能拒答，不能替所有人回答「在」。
 *
 * 本文件的写侧用例只碰自己那一行（`self::KEY`，tearDown 按 key 删），
 * 且畸形值用例在派发前先核对守卫与写入口径（见 `requireGuarded()`）。
 */
final class FeatureFlagRolloutTest extends TestCase
{
    private const KEY = 'zz-rollout-probe';

    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        HttpRequest::clear();
        if (Db::tableExists('kode_feature_flags')) {
            Db::table('kode_feature_flags')->where('key', self::KEY)->delete();
        }

        parent::tearDown();
    }

    /* ==================== 判据本身（离线，不碰库） ==================== */

    /**
     * 合法形状：整数、区间内的整数、整数值的浮点/字符串（JSON 里 `100` 与 `"100"` 都真实存在）。
     */
    public function test_the_judge_accepts_every_in_range_integer_shape(): void
    {
        foreach ([0, 1, 37, 100, '0', '37', '100', 37.0] as $good) {
            $verdict = $this->judge($good);
            self::assertNull($verdict['error'], var_export($good, true) . ' 是合法的灰度值：' . $verdict['error']);
            self::assertSame((int) $good, $verdict['value']);
        }
    }

    /**
     * 畸形形状：越界、非整数、以及会被 `(int)` 吃成一个数的那些 —— 每种都必须给出一句能改的参数错误。
     */
    public function test_the_judge_refuses_everything_that_is_not_an_in_range_integer(): void
    {
        foreach ([101, 1000, -1, -100, 12.5, 'abc', '50人', '', true, false, [], null, '1e3'] as $bad) {
            $verdict = $this->judge($bad);
            self::assertNotNull(
                $verdict['error'],
                var_export($bad, true) . ' 被判成合法灰度（value=' . var_export($verdict['value'], true) . '）'
            );
            self::assertStringContainsString(
                '0-100',
                (string) $verdict['error'],
                '拒绝原因没写清允许区间，操作者无从改参数'
            );
        }
    }

    /* ==================== 写侧：先拒再写 ==================== */

    public function test_store_refuses_a_malformed_percent_without_writing_a_row(): void
    {
        $this->requireGuarded();

        foreach ([1000, -1, 'abc', true] as $bad) {
            $result = $this->send('store', [
                'key' => self::KEY,
                'rollout' => $bad,
            ]);
            self::assertSame(
                400,
                $result['status'],
                'POST rollout=' . var_export($bad, true) . ' 没被拒：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE)
            );
            self::assertSame(
                0,
                $this->probeRows(),
                'POST rollout=' . var_export($bad, true) . ' 的 400 之前已经把这一行建出来了'
            );
        }
    }

    public function test_update_refuses_a_malformed_percent_and_keeps_the_stored_value(): void
    {
        $this->requireGuarded();
        $this->seedProbeRow(37);

        foreach ([1000, -1, 'abc', 12.5] as $bad) {
            $result = $this->send('update', ['rollout' => $bad], ['key' => self::KEY]);
            self::assertSame(
                400,
                $result['status'],
                'PUT rollout=' . var_export($bad, true) . ' 没被拒：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE)
            );
            self::assertSame(
                37,
                $this->storedRollout(),
                'rollout=' . var_export($bad, true) . ' 改了库里已存的灰度：' . $this->storedRollout()
            );
        }
    }

    /** 正对照：守卫不能变成「一律拒」，合法值必须照常写进去。 */
    public function test_a_valid_percent_still_writes(): void
    {
        $this->requireGuarded();
        $this->seedProbeRow(37);

        $result = $this->send('update', ['rollout' => 8], ['key' => self::KEY]);
        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(8, $this->storedRollout());
    }

    /** PUT 的「缺键保留原值」不能因为加守卫而被改掉。 */
    public function test_an_update_without_percent_leaves_it_untouched(): void
    {
        $this->requireGuarded();
        $this->seedProbeRow(37);

        $result = $this->send('update', ['description' => '只改描述'], ['key' => self::KEY]);
        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(37, $this->storedRollout());
    }

    /** 两个写入口都不许自己留一份夹取（判据只有一份）。 */
    public function test_no_write_entry_clamps_the_percent_on_its_own(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/app/admin/controllers/FeatureFlagController.php') ?: '';
        self::assertNotSame('', $src, '读不到 FeatureFlagController.php');

        foreach (['store', 'update'] as $method) {
            $bodies = SourceScan::publicMethodBodies($src, '/^' . $method . '$/');
            self::assertArrayHasKey($method, $bodies, 'FeatureFlagController 没有 ' . $method . '()');
            self::assertStringNotContainsString(
                'min(100',
                $bodies[$method],
                $method . '() 还在自己夹取灰度值：静默放量/关量的那条线又回来了'
            );
            self::assertStringContainsString(
                'self::percentOf(',
                $bodies[$method],
                $method . '() 没走共用的灰度判据'
            );
        }
    }

    /* ==================== 读侧：没有主体就没有分桶 ==================== */

    public function test_a_partial_rollout_refuses_to_answer_without_a_subject(): void
    {
        $this->seedProbeRow(10);

        $result = $this->send('check', ['key' => self::KEY]);

        self::assertSame(
            400,
            $result['status'],
            '灰度 10% 的开关在调用方没给 user_id 时照样给了结论：'
            . json_encode($result['body'], JSON_UNESCAPED_UNICODE)
            . ' —— bucket 恒为 0，等于对每个匿名主体都回「在灰度里」'
        );
    }

    /** 全量/零灰度与主体无关，不能被这条守卫一起拒掉（否则开关页的试算框常驻红）。 */
    public function test_full_and_zero_rollout_still_answer_without_a_subject(): void
    {
        foreach ([100 => true, 0 => false] as $percent => $expected) {
            $this->seedProbeRow((int) $percent);
            $result = $this->send('check', ['key' => self::KEY]);

            self::assertSame(200, $result['status'], 'rollout=' . $percent . '：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE));
            self::assertSame($expected, (bool) ($result['body']['data']['enabled'] ?? null), 'rollout=' . $percent . ' 的判定反了');
        }
    }

    /** 给了主体就照常分桶，并且回显那次判定用的数（页面要靠它对账）。 */
    public function test_a_subject_still_gets_a_bucket(): void
    {
        $this->seedProbeRow(10);

        $result = $this->send('check', ['key' => self::KEY, 'user_id' => 'zz-user-1']);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $data = (array) ($result['body']['data'] ?? []);
        self::assertArrayHasKey('bucket', $data);
        self::assertSame(10, $data['rollout'] ?? null, '没回显这次判定用的灰度值');
        self::assertGreaterThanOrEqual(0, (int) $data['bucket']);
        self::assertLessThan(100, (int) $data['bucket']);
    }

    /* ==================== 工具 ==================== */

    /**
     * 派发前的口径核对：判据在位、且写入口拿的是判据的结论而不是原始入参。
     *
     * 这里拦的是「未修复版」——本轮的写侧用例都是会真落库的，
     * 所以未修复时它必须先变红而不是先把畸形值写进库里再让下面的断言追认。
     */
    private function requireGuarded(): void
    {
        self::assertTrue(
            method_exists(FeatureFlagController::class, 'percentOf'),
            'FeatureFlagController::percentOf() 不存在：写入口还在自己 max/min 夹取灰度值'
        );

        $src = file_get_contents(dirname(__DIR__) . '/app/admin/controllers/FeatureFlagController.php') ?: '';
        foreach (['store', 'update'] as $method) {
            $bodies = SourceScan::publicMethodBodies($src, '/^' . $method . '$/');
            self::assertArrayHasKey($method, $bodies);
            self::assertMatchesRegularExpression(
                '/self::percentOf\(\s*\$this->input\(\s*[\'"]rollout[\'"]/',
                $bodies[$method],
                $method . '() 交给写库的灰度值不是 percentOf() 的结论：守卫成了装饰'
            );
        }
    }

    /** @return array{value: int|null, error: string|null} */
    private function judge(mixed $raw): array
    {
        self::assertTrue(
            method_exists(FeatureFlagController::class, 'percentOf'),
            'FeatureFlagController::percentOf() 不存在：灰度入参还没有判据'
        );

        return FeatureFlagController::percentOf($raw);
    }

    private function seedProbeRow(int $rollout): void
    {
        Db::table('kode_feature_flags')->where('key', self::KEY)->delete();
        Db::table('kode_feature_flags')->insert([
            'key' => self::KEY,
            'description' => '灰度入参回归用例',
            'enabled' => true,
            'rollout' => $rollout,
            'environment' => '',
        ]);
    }

    private function storedRollout(): int
    {
        $row = Db::table('kode_feature_flags')->where('key', self::KEY)->first();
        self::assertNotNull($row, '探针行不见了：本文件的断言没有对象');

        return (int) (($row['rollout'] ?? 0));
    }

    private function probeRows(): int
    {
        return (int) (Db::select(
            'SELECT COUNT(*) AS c FROM kode_feature_flags WHERE key = ?',
            [self::KEY]
        )[0]['c'] ?? 0);
    }

    /**
     * @param array<string, mixed> $body        查询串（check）或请求体（store/update）
     * @param array<string, mixed> $routeParams 路由参数（`{key}` 走 `_route_params`，与 dispatch 后一致）
     * @return array{status:int, body:array<string,mixed>}
     */
    private function send(string $handler, array $body = [], array $routeParams = []): array
    {
        $request = (new ServerRequest($handler === 'check' ? 'GET' : 'POST', '/api/feature-flags'))
            ->withAttribute('auth', (object) [
                'uid' => 1,
                'username' => 'zz-rollout-probe',
                'roles' => ['admin'],
            ])
            ->withAttribute('_route_params', $routeParams);

        $request = $handler === 'check'
            ? $request->withQueryParams($body)
            : $request->withParsedBody($body);

        HttpRequest::setRequest($request);

        $response = (new FeatureFlagController())->{$handler}();
        self::assertInstanceOf(ResponseInterface::class, $response);
        HttpRequest::clear();

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }
}
