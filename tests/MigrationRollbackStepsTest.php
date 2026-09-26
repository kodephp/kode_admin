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
 * `POST /api/migrations/rollback` 的 step 入参（AGENTS.md §4「破坏性入参禁止静默夹取」）。
 *
 * 这一条的代价不是「数字不对」而是「库没了」，所以三点先说清：
 *
 * 1. `step` 越界在框架侧是**放大**而不是夹紧：`Migrator::rollbackOnConnection()` 算的是
 *    `range($maxBatch - $steps + 1, $maxBatch)` 再滤掉 `< 1`，于是 `step=9999` 得到
 *    `[1..maxBatch]` —— 一次「回滚最近一批」的请求变成 `reset()`，全库每一个批次的
 *    `down()` 挨个执行。管理页现在的按钮恒发 `{step:1}`，所以这个入口只有手工请求
 *    （或前端将来加一个「回滚 N 批」输入框）会撞上，撞上之后没有任何报错指向它。
 * 2. `step=0` / 负数是另一头：`range(7, 6)` 是**倒着数**的（实测 `[7,6]`），
 *    过滤后等价于「回滚 1 批」，也就是把 0 静默当成 1 —— 用户写 0 想表达的
 *    是「别动」，得到的是一次真实回滚。
 * 3. `step` 传超大值时上面那条算式溢出成 float，`range()` 抛
 *    `ValueError: The supplied range exceeds the maximum array size`，
 *    被 handler 的 `catch (\Throwable)` 拼进 500 文案 —— 内部异常文本原样回给客户端。
 *
 * 所以口径是：`step` 必须是 1..已执行批次总数 的整数，越界一律 400 且**先拒再动引擎**，
 * 「回滚全部」这个动作只属于 `/api/migrations/reset`（它自己那条线，带自己的确认文案）。
 *
 * 测试侧的安全线要说清两条，且两条都在**发请求之前**：
 *  1. `requireGuardInPlace()` 断言「守卫在调引擎之前」并且真的把 `$maxBatch` 交给了判据 ——
 *     未修复版的 handler 会把请求原样交给 `Migrator`，而 `step=0` 在框架侧等价于「回滚最近
 *     一批」、`step` 越界等价于全库 reset，那都是不可逆的 schema 变更。断言失败就直接抛。
 *  2. `requireJudgeRefuses()` 逐个问判据「这些入参你拒不拒」（用本机真实的 `MAX(batch)`）。
 *     第 1 条只证明「调了判据」，证明不了「判据此刻还对不对」：判据被改成放行 9999 时，
 *     请求会先真回滚一批、再由行数断言事后变红 —— 用例本身变成事故。
 */
final class MigrationRollbackStepsTest extends TestCase
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

    /* ==================== 1. 纯判据：越界一律拒，不夹取 ==================== */

    public function test_the_judge_accepts_only_a_bounded_positive_integer(): void
    {
        // 缺省 = 回滚最近一批（按钮现在的行为）
        self::assertSame(1, \app\admin\controllers\MigrationController::stepsFor(null, 40));
        self::assertSame(1, \app\admin\controllers\MigrationController::stepsFor('', 40));
        self::assertSame(2, \app\admin\controllers\MigrationController::stepsFor('2', 40));
        self::assertSame(40, \app\admin\controllers\MigrationController::stepsFor(40, 40));
        self::assertSame(40, \app\admin\controllers\MigrationController::stepsFor(40.0, 40));
    }

    public function test_the_judge_refuses_everything_outside_the_batch_range(): void
    {
        self::assertNull(\app\admin\controllers\MigrationController::stepsFor(0, 40), '0 被夹成了 1');
        self::assertNull(\app\admin\controllers\MigrationController::stepsFor(-3, 40));
        self::assertNull(\app\admin\controllers\MigrationController::stepsFor('abc', 40));
        self::assertNull(\app\admin\controllers\MigrationController::stepsFor('1.5', 40));
        self::assertNull(\app\admin\controllers\MigrationController::stepsFor(41, 40), '越界=回滚全部还放行');
        self::assertNull(\app\admin\controllers\MigrationController::stepsFor(9999, 40));
        self::assertNull(\app\admin\controllers\MigrationController::stepsFor(PHP_INT_MAX, 40));
        self::assertNull(\app\admin\controllers\MigrationController::stepsFor(true, 40));
        // 一个批次都没有：任何 step 都没有可回滚的目标，不该放行。
        self::assertNull(\app\admin\controllers\MigrationController::stepsFor(1, 0));
    }

    /** 反例守卫：判据不能顺手把「数字字符串」拒了，那是表单实际发来的形状。 */
    public function test_the_judge_does_not_refuse_the_forms_a_real_client_sends(): void
    {
        self::assertSame(3, \app\admin\controllers\MigrationController::stepsFor(3, 3));
        self::assertSame(3, \app\admin\controllers\MigrationController::stepsFor(' 3 ', 3));
        self::assertSame(7, \app\admin\controllers\MigrationController::stepsFor('7', 40));
    }

    /* ==================== 2. handler 必须先过守卫再碰引擎 ==================== */

    public function test_the_guard_runs_before_the_engine_is_called(): void
    {
        $this->requireGuardInPlace();
        self::assertDoesNotMatchRegularExpression(
            '/\(\s*int\s*\)\s*\$this->input\(\s*[\'"]step/',
            $this->handlerBody('rollback'),
            '(int) $this->input(\'step\') 又回来了：非数字串会被转成 0，然后被静默当成 1 批'
        );
    }

    /**
     * 守卫在不在，先问一次再发请求。
     *
     * 每条真发 handler 的用例开头都要调它：未修复版的 `rollback()` 会把请求原样交给
     * `Migrator`，而 `step=0` 在框架侧等价于「回滚最近一批」—— 那是**不可逆**的 schema 变更。
     * 于是「守卫缺失」必须在这里变红，而不是靠一条断言去事后核对行数。
     */
    private function requireGuardInPlace(): void
    {
        $body = $this->handlerBody('rollback');

        self::assertStringContainsString(
            '$migrator->rollback(',
            $body,
            'rollback() 里没有引擎调用：这条「守卫排在调用之前」的比对没有对象'
        );
        self::assertStringContainsString(
            'self::stepsFor(',
            $body,
            'rollback() 没有调用 step 判据：请求会直接进 Migrator，所以本文件不会替你发这个请求'
        );
        self::assertLessThan(
            (int) strpos($body, '$migrator->rollback('),
            (int) strpos($body, 'self::stepsFor('),
            '守卫写在了引擎调用之后：越界的 step 已经执行过了'
        );
        // 判据收到的是「这次查出来的批次上界」，不是别的什么数：把 400 文案里的数字
        // 与判据的分母钉成同一个变量，否则「msg 说上限 42、判据按 999999 放行」照样全绿。
        self::assertMatchesRegularExpression(
            '/self::stepsFor\(\s*\$this->input\(\s*[\'"]step[\'"]\s*\)\s*,\s*\$maxBatch\s*\)/',
            $body,
            'stepsFor() 的第二个参数不再是 $maxBatch：判据的上界与响应文案已经不是同一个数'
        );
    }

    /**
     * 发请求之前的第二道线：判据必须对**这批真实入参**逐个说「拒」。
     *
     * `requireGuardInPlace()` 证明的是 handler 调了判据，证明不了判据此刻还对不对。
     * 判据哪天被改松（比如把越界夹成上限），这条会当场变红且不发出任何请求；
     * 少了它，红的是下面那条「行数没变」—— 而那时真实回滚已经跑完了。
     *
     * @param list<mixed> $bads
     */
    private function requireJudgeRefuses(array $bads): void
    {
        $max = $this->maxBatch();
        self::assertGreaterThan(0, $max, '本机 migrations 表没有任何批次：判据预检没有对象');

        foreach ($bads as $bad) {
            self::assertNull(
                \app\admin\controllers\MigrationController::stepsFor($bad, $max),
                "判据对 step=" . var_export($bad, true) . "（最大批次 {$max}）放行：真发请求就是一次不可逆的回滚，所以本用例不发"
            );
        }
    }

    /** 越界一律 400，且 migrations 表一行都不能少。 */
    public function test_an_out_of_range_step_is_refused_and_changes_nothing(): void
    {
        $this->requireGuardInPlace();
        $bads = [0, -3, 'abc', 9999, PHP_INT_MAX, 1.5];
        $this->requireJudgeRefuses($bads);
        $before = $this->migrationRows();

        foreach ($bads as $bad) {
            $result = $this->sendPost('rollback', ['step' => $bad]);
            self::assertSame(
                400,
                $result['status'],
                'step=' . var_export($bad, true) . ' 没被拒：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE)
            );
            self::assertSame(
                $before,
                $this->migrationRows(),
                'step=' . var_export($bad, true) . ' 的 400 之后，migrations 表还是被改了'
            );
        }
    }

    /** 越界时响应要说清上限是多少，否则用户只能瞎猜下一个值。 */
    public function test_a_refusal_names_the_allowed_range(): void
    {
        $this->requireGuardInPlace();
        $max = $this->maxBatch();
        $this->requireJudgeRefuses([$max + 1]);

        $result = $this->sendPost('rollback', ['step' => $max + 1]);

        self::assertSame(400, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString(
            (string) $max,
            (string) ($result['body']['msg'] ?? ''),
            '400 的文案里没点名允许的上限'
        );
        self::assertStringNotContainsString(
            'ValueError',
            (string) ($result['body']['msg'] ?? ''),
            '内部异常文本漏进了响应'
        );
    }

    /* ==================== 工具 ==================== */

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
            self::fail('migrations 表不存在：本文件的行数不变断言没有对象');
        }

        return (int) (Db::select('SELECT COUNT(*) AS c FROM migrations')[0]['c'] ?? 0);
    }

    private function batchTotal(): int
    {
        return (int) (Db::select('SELECT COUNT(DISTINCT batch) AS c FROM migrations')[0]['c'] ?? 0);
    }

    /**
     * handler 自己算的那个上界（`max($row['batch'])`），测试侧只许跟着它，不要另立口径。
     *
     * 不取 `COUNT(DISTINCT batch)`：批次号一旦不连号（回滚过再跑就是这种库），
     * 两者立刻分叉，预检就会对着一个 handler 根本没用过的数放行。
     */
    private function maxBatch(): int
    {
        return (int) (Db::select('SELECT COALESCE(MAX(batch), 0) AS c FROM migrations')[0]['c'] ?? 0);
    }
}
