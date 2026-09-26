<?php

declare(strict_types=1);

namespace app\tests;

use app\tests\Support\SourceScan;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * `POST /api/migrations/run` 的 step 入参（AGENTS.md §4「破坏性入参禁止静默夹取」）。
 *
 * 与 `tests/MigrationRollbackStepsTest` 同族，但**边界不同**，所以判据也不同：
 *  - 回滚侧越界是放大（框架的批次算式会把 step 变成「全部」），必须钉上界；
 *  - 执行侧 `Migrator::run($steps)` 走的是 `array_slice($pending, 0, $steps)`，
 *    step 大于待执行数**无害**（slice 自己夹），缺省恒等于「全部待执行」。
 * 这里真正要挡的是另外两件事，实测（`array_slice(['a','b','c'], 0, $n)`）：
 *  - `step = 0` → slice 为空 → 一个迁移都不跑，而 handler 回的是
 *    `{executed: [], message: "没有需要执行的迁移"}` —— 明明还有 3 个待执行，
 *    操作者被告知「没东西可跑」。这是把「参数写错了」渲染成「系统是新的」。
 *  - `step = -1` → slice **砍掉最后一个** → 部分执行，而且部分执行是真实落库：
 *    跑到第 2 个迁移就停，第 3 个不跑，批次号照样写进 migrations 表。
 *    一个负数参数换来一个「前一半迁移已应用」的库，且响应里没有任何提示。
 *  - `(int) '2.9' === 2`、`(int) 'abc' === 0`、`(int) true === 1` —— 旧的
 *    `(int) $this->input('step')` 写法把这三种都吃成「看着像用户想要的那个」，
 *    于是 `step: "abc"` 变成「什么都不跑」而 `step: true` 变成「只跑一个」。
 *
 * 所以口径：`step` 要么不带（= 全部），要么必须是正整数；`0`、负数、非整数字面量
 * 一律 400，且**先拒再动引擎**（这条线上的失败不该以「跑了几个算几个」收场）。
 *
 * 测试侧的两条安全线（本文件自己踩过一次）：
 *  1. 真发 handler 的用例先断言「守卫排在 `$migrator->run(` 之前」**且引擎拿的是判据的结论**，
 *     断言失败就直接抛 —— 未修复版会真的按一个畸形参数去执行迁移。
 *  2. **发请求前先数待执行数**（`requireNothingPending()`）：只要有迁移还挂着没跑就 skip 并写明原因。
 *     第 1 条治不了这一种：守卫在位而判据写错（比如把 -1 放行成 1）时，请求照样会落库。
 *     这一条不是假想 —— 本版第一稿的「没带 step」正对照就本机真跑出过一条待执行迁移，
 *     而它自己的行数断言是在**已经落库之后**才变红的。
 */
final class MigrationRunStepsTest extends TestCase
{
    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        // 断言失败时 sendPost() 走不到 clear()，陈请求会串给下一个用例。
        HttpRequest::clear();

        parent::tearDown();
    }

    /* ==================== 1. 纯判据 ==================== */

    public function test_absent_step_still_means_run_everything(): void
    {
        // 管理页那个按钮就是不带 step 的：这条不能变，否则一键迁移会变成只跑一步。
        self::assertNull(self::runJudge(null)['steps']);
        self::assertNull(self::runJudge(null)['error']);
        self::assertNull(self::runJudge('')['steps']);
        self::assertNull(self::runJudge('')['error']);
    }

    public function test_the_judge_accepts_a_positive_integer_in_any_arriving_shape(): void
    {
        self::assertSame(1, self::runJudge(1)['steps']);
        self::assertSame(3, self::runJudge('3')['steps']);
        self::assertSame(3, self::runJudge(' 3 ')['steps']);
        self::assertSame(3, self::runJudge(3.0)['steps']);
        // 上界故意不设：array_slice 自己夹，且「多要一点」不等于「做了一件你没说的事」。
        self::assertSame(9999, self::runJudge(9999)['steps']);
    }

    public function test_the_judge_refuses_the_shapes_framework_would_misrun(): void
    {
        // 缺省（null）已经是「全部」这个合法语义，所以畸形值不能再回落成 null：
        // 那等于把「参数写错了」执行成「把你所有待执行的迁移全跑掉」，代价比夹取更大。
        foreach ([0, -1, -3, '0', '-2', 'abc', '2.9', 2.5, true, false, [], ['1']] as $bad) {
            $verdict = self::runJudge($bad);
            self::assertNotNull(
                $verdict['error'],
                '畸形 step 没被拒：' . var_export($bad, true)
            );
            self::assertNotSame(
                null,
                $verdict['steps'] ?? 'absent',
                '畸形 step 既给了 error 又给了 steps：调用方该信哪个'
            );
        }
    }

    /** 400 的文案得点名是哪个键、要什么，否则操作者只能瞎猜下一个值。 */
    public function test_the_refusal_says_what_it_wanted(): void
    {
        $verdict = self::runJudge(-1);
        self::assertStringContainsString('step', (string) $verdict['error']);
    }

    /**
     * 前提核对：这些「畸形」不是我在臬造 —— 框架侧真的会各跑出一件不同的事。
     */
    public function test_the_malformed_values_would_each_do_something_different(): void
    {
        $pending = ['a.php', 'b.php', 'c.php'];
        self::assertSame([], array_slice($pending, 0, 0), '前提变了：0 不再是「什么都不跑」');
        self::assertSame(['a.php', 'b.php'], array_slice($pending, 0, -1), '前提变了：-1 不再砍掉最后一个');
        self::assertSame(2, (int) '2.9', '前提变了：截断不再是静默的');
        self::assertSame(0, (int) 'abc');
        self::assertSame(1, (int) true);
    }

    /* ==================== 2. handler 必须先过守卫 ==================== */

    public function test_the_guard_runs_before_the_engine_is_called(): void
    {
        $this->requireGuardInPlace();
        self::assertDoesNotMatchRegularExpression(
            '/\$\s*step\s*=\s*\$step\s*!==\s*null\s*\?\s*\(int\)\s*\$step/',
            $this->handlerBody('run'),
            '旧的 `(int) $step` 写法又回来了：畸形值会被吃成 0 或 1'
        );
    }

    private function requireGuardInPlace(): void
    {
        $body = $this->handlerBody('run');

        self::assertStringContainsString(
            '$migrator->run(',
            $body,
            'run() 里没有引擎调用：这条「守卫排在调用之前」的比对没有对象'
        );
        self::assertStringContainsString(
            'self::runStepFor(',
            $body,
            'run() 没有调用 step 判据：请求会直接进 Migrator，所以本文件不会替你发这个请求'
        );
        self::assertLessThan(
            (int) strpos($body, '$migrator->run('),
            (int) strpos($body, 'self::runStepFor('),
            '守卫写在引擎调用之后：畸形参数的迁移已经执行过了'
        );
        // 判完还得**把结论交给引擎**：只查「调了判据」的话，
        // `$migrator->run($this->input('step'))`（算完就扔）照样全绿。
        self::assertMatchesRegularExpression(
            '/\$migrator->run\(\s*\$verdict\[.steps.\]\s*\)/',
            $body,
            'run() 交给 Migrator 的不是 runStepFor() 的结论：守卫成了装饰'
        );
    }

    /** 畸形 step 一律 400，且 migrations 表一行都不许多。 */
    public function test_a_malformed_step_is_refused_and_applies_nothing(): void
    {
        $this->requireGuardInPlace();
        $this->requireNothingPending();
        $before = $this->migrationRows();

        foreach ([0, -1, 'abc', '2.9', true] as $bad) {
            $result = $this->sendPost('run', ['step' => $bad]);
            self::assertSame(
                400,
                $result['status'],
                'step=' . var_export($bad, true) . ' 没被拒：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE)
            );
            self::assertSame(
                $before,
                $this->migrationRows(),
                'step=' . var_export($bad, true) . ' 的 400 之前已经落库了迁移记录'
            );
        }
    }

    /**
     * 「没带 step」这条真路径不能被守卫误伤：它必须继续走到引擎并按真实结果回话。
     *
     * 正对照的意义在于守卫把畸形值拒了的同时，没有把正常请求一起拒掉。而它是本文件唯一
     * 一次「语义上允许真跑迁移」的请求，所以只在**确实没有待执行迁移**时发（见类注释第 2 条）；
     * 那样的机器上引擎跑不跑都改不了 migrations 表，行数不变就是可证的。
     */
    public function test_a_request_without_step_reaches_the_engine(): void
    {
        $this->requireGuardInPlace();
        $this->requireNothingPending();
        $before = $this->migrationRows();

        $result = $this->sendPost('run', []);

        self::assertNotSame(400, $result['status'], '不带 step 被守卫挡了：一键迁移按钮会永久失效');
        self::assertSame($before, $this->migrationRows(), '没有待执行迁移却多出了行数：引擎被叫了两遍');
    }

    /* ==================== 工具 ==================== */

    /**
     * 有「引擎认为还没跑」的迁移时，绝不让本文件碰到 `Migrator::run()`。
     *
     * 安全线问的是**引擎自己**（`getPendingFiles()`），因为那正是 `run()` 要消费的清单 ——
     * 「本机已经没有待执行迁移」这件事必须在**即将执行它的那个人**嘴里成立，不能只在
     * 管理页的口径里成立。
     *
     * 顺带把 `status()` 拉来对账（两份清单逐名字比）：这条不是装饰，它钉的是
     * 「操作者点按钮前看到的那句话」与「按钮实际会做的事」必须同源 ——
     * 两边一旦分叉（各自过滤连接、各自认表名、一边认 `migrations` 一边认别的表），
     * 页面就会说「没有需要执行的迁移」而那次点击跑掉几个，且响应里没有任何提示。
     *
     * `migrator()` 是私有的，这里用反射拿 handler 即将用的那个对象：测试侧不重算迁移目录
     * 路径，也不立第三份「什么算待执行」（判据只许有一份，AGENTS.md §3）。
     */
    private function requireNothingPending(): void
    {
        $open = new \ReflectionMethod(\app\admin\controllers\MigrationController::class, 'migrator');
        $open->setAccessible(true);
        /** @var \Kode\Database\Database\Migrations\Migrator $engine */
        $engine = $open->invoke(new \app\admin\controllers\MigrationController());
        $names = array_map(
            static fn(string $f): string => basename($f, '.php'),
            $engine->getPendingFiles()
        );

        $response = (new \app\admin\controllers\MigrationController())->status();
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertSame(
            200,
            $response->getStatusCode(),
            '读不到迁移状态：' . json_encode($body, JSON_UNESCAPED_UNICODE)
        );

        self::assertSame(
            $names,
            array_column((array) ($body['data']['pending'] ?? []), 'name'),
            '引擎与管理页对「还有什么没跑」两份清单不一致：按钮会执行引擎那份，而页面显示的是另一份'
        );

        // 「还有什么没跑」= 文件 −「已经跑过」，所以这条对账要连被减数一起比：待执行为 0 时
        // 上面那条在比两个空数组，只有这条在本机每次运行都有内容。分叉的现实形状是两边认了
        // 不同的表（管理页读某张分片历史、回滚读 `migrations`），那一次点击的账就查无实据。
        $ran = array_keys($engine->getRan());
        sort($ran);
        $applied = array_column(array_merge(...array_map(
            static fn($b): array => (array) $b,
            (array) ($body['data']['batches'] ?? [])
        )), 'name');
        sort($applied);
        self::assertSame(
            $ran,
            $applied,
            '引擎与管理页读到的「已执行迁移」历史不一致（数量 ' . count($ran) . ' vs ' . count($applied) . '）'
        );

        if ($names !== []) {
            self::markTestSkipped(
                '引擎认为还有 ' . count($names) . ' 个待执行迁移（' . implode(', ', $names)
                . '）：本文件会真的执行它们，先跑 php kode migrate 再回来'
            );
        }
    }

    /** 判据派发（写成方法是为了「方法不存在」时是一条能读懂的红，而不是 12 处 fatal）。 */
    private static function runJudge(mixed $raw): array
    {
        self::assertTrue(
            method_exists(\app\admin\controllers\MigrationController::class, 'runStepFor'),
            'MigrationController::runStepFor() 不存在：run() 还在把 step 直接交给 Migrator'
        );

        return \app\admin\controllers\MigrationController::runStepFor($raw);
    }

    /** @param array<string, mixed> $body @return array{status:int, body:array<string,mixed>} */
    private function sendPost(string $handler, array $body): array
    {
        $request = (new ServerRequest('POST', '/api/migrations/' . $handler))
            ->withParsedBody($body)
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => 'zz-migration-probe', 'roles' => ['admin']]);
        HttpRequest::setRequest($request);

        $response = (new \app\admin\controllers\MigrationController())->{$handler}();
        self::assertInstanceOf(ResponseInterface::class, $response);
        HttpRequest::clear();

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }

    private function handlerBody(string $method): string
    {
        $src = file_get_contents(dirname(__DIR__) . '/app/admin/controllers/MigrationController.php') ?: '';
        // 剥注释再比：文档里那句「守卫应该长什么样」不能充当守卫本身。
        $bodies = SourceScan::publicMethodBodies($src, '/^' . $method . '$/');
        self::assertArrayHasKey($method, $bodies, 'MigrationController 没有 ' . $method . '()');

        return $bodies[$method];
    }

    private function migrationRows(): int
    {
        if (!Db::tableExists('migrations')) {
            self::fail('migrations 表不存在：本文件的行数断言没有对象');
        }

        return (int) (Db::select('SELECT COUNT(*) AS c FROM migrations')[0]['c'] ?? 0);
    }
}
