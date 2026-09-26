<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Plugins\frontend\WorkflowBridgeController;
use Psr\Http\Message\ResponseInterface;

/**
 * 「这一步算成功还是失败」在同一个插件里曾有五条各自实现的判据（AGENTS.md §3「判据只写一份」）。
 *
 *  - `stepPerformance()`（SQL）认 `(value->>'success') = 'true'` —— 没有 `success` 键就不算成功；
 *  - `stepHealth()`、`stepStats()`（PHP）认 `!isset($stepResult['error'])` —— 只要没 error 就算成功；
 *  - `apiCallAggregation()`（PHP）在上一条之外又自己 AND 了一个 `http_code < 400`；
 *  - `executionStepTrace()`（PHP）再写一遍 error / success 两条 if。
 *
 * 写侧只有 api_call 分支会落 `success` 键（`execute()` 原样存 `executeStep()` 的返回），
 * 所以 log / condition / calc 这类步骤**根本没有这个键** —— 第一条规则把它们一律判成失败。
 * 实测库里 73 条执行：`log_input` 在步骤健康页是 100% 成功，在同一页的性能分布表是 **0%**；
 * `calc_bonus` 93% vs 50%。两个数由同一批行算出，都印在「工作流桥」上，谁都不报错。
 *
 * 第二条规则反向错：api_call 拿到 HTTP 5xx 时 `executeApiCall()` 回
 * `{http_code, data, success: false}`，**不带 error 键**（:5544），于是面板把它算成成功。
 * 今天库里还没有这样一行（上游没挂过），所以这条只表现为「和别处不一致」而不是已经在骗人 ——
 * 一次上游 5xx 之后就会：失败率最高的步骤排行里看不到它，健康页给它记成功。
 *
 * 期望判据 = `stepOutcome()` / `stepOutcomeSql()`（键缺失算成功、显式 false 算失败、
 * 有非空 error 算失败），五个端点必须对同一个步骤给出同一个答案。
 */
final class WorkflowStepOutcomeTest extends TestCase
{
    protected bool $independentApp = true;

    private const WF = 'zz-outcome';

    // 步骤名带 zz- 前缀：stepStats / apiCallAggregation 按**全站**近 N 天聚合、不按 workflow 过滤，
    // 撞名会把别的行算进来，断言就不再只看着我造的这批行。
    private const S_SILENT = 'zz-silent';
    private const S_FLAGGED = 'zz-flagged';
    private const S_ERRORED = 'zz-errored';
    private const S_SOFTFAIL = 'zz-softfail';
    private const S_OKCALL = 'zz-ok-call';

    /** @var list<int> */
    private array $execIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->execIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_workflow_executions WHERE id = ?', [$id]);
        }

        HttpRequest::clear();

        parent::tearDown();
    }

    /**
     * 五种步骤形状，每种恰好被一条错法判歪（五条同时在场，任何一条错法复活都会变红）：
     *  - `silent`   只有耗时，没有 success / error —— 旧性能分布判失败，正确判成功；
     *  - `flagged`  显式 success:true —— 各条规则都说成功，当对照；
     *  - `errored`  带 error —— 各条规则都说失败，当对照；
     *  - `softfail` 显式 success:false 且不带 error —— 旧健康页/统计页判成功，正确判失败；
     *  - `okcall`   api_call 成功形状（2xx + success:true）—— apiCallAggregation 的正对照，
     *               少了它那张表只有「全失败」一种输出，判据写反也照样绿。
     *
     * @return int 执行记录 id
     */
    private function seedExecution(): int
    {
        $steps = [
            self::S_SILENT => ['_duration_ms' => 10, 'result' => 'ok'],
            self::S_FLAGGED => ['_duration_ms' => 20, 'success' => true],
            self::S_ERRORED => ['_duration_ms' => 30, 'error' => '上游 500'],
            self::S_SOFTFAIL => ['_duration_ms' => 40, 'http_code' => 502, 'success' => false],
            self::S_OKCALL => ['_duration_ms' => 15, 'http_code' => 200, 'data' => ['ok' => 1], 'success' => true],
        ];

        $id = (int) Db::insert(
            "INSERT INTO kode_lowcode_workflow_executions
                (workflow_name, workflow_id, status, step_count, duration_ms, step_results_json)
             VALUES (?, ?, 'succeeded', 5, 100, ?::jsonb)",
            [self::WF, 0, json_encode($steps, JSON_UNESCAPED_UNICODE)]
        );
        $this->execIds[] = $id;

        return $id;
    }

    /** @param array<string, bool[]> $outcomes */
    private function assertOutcome(array $outcomes, string $step, bool $success, bool $failed, string $from): void
    {
        self::assertArrayHasKey($step, $outcomes, "{$from} 没列出步骤 {$step}：造行没生效");
        self::assertSame(
            [$success, $failed],
            $outcomes[$step],
            "{$from} 对 {$step} 的 (成功, 失败) 判定不符"
        );
    }

    /**
     * 性能分布表（SQL 侧）：没有 success 键的步骤必须算成功，显式 false 必须算失败。
     */
    public function test_the_performance_breakdown_scores_steps_the_canonical_way(): void
    {
        $this->seedExecution();

        $data = $this->call('stepPerformance', ['workflow' => self::WF])['data'];

        $outcomes = [];
        foreach ((array) ($data['by_step'] ?? []) as $row) {
            $outcomes[(string) $row['step_name']] = [
                (int) ($row['success_count'] ?? -1) > 0,
                (int) ($row['failed_count'] ?? -1) > 0,
            ];
        }

        // 旧写法 `(value->>'success') = 'true'` 在这里判红：silent 被记成「不成功」。
        $this->assertOutcome($outcomes, self::S_SILENT, true, false, '性能分布');
        $this->assertOutcome($outcomes, self::S_FLAGGED, true, false, '性能分布');
        $this->assertOutcome($outcomes, self::S_ERRORED, false, true, '性能分布');
        $this->assertOutcome($outcomes, self::S_SOFTFAIL, false, true, '性能分布');
        $this->assertOutcome($outcomes, self::S_OKCALL, true, false, '性能分布');
    }

    /**
     * 步骤健康页（PHP 侧）：必须认显式的 `success: false`，不能只看有没有 error 键。
     */
    public function test_the_step_health_scores_steps_the_canonical_way(): void
    {
        $this->seedExecution();

        $data = $this->call('stepHealth', ['workflow' => self::WF])['data'];

        // 旧写法 `!isset($stepResult['error'])` 在这里判红：softfail 被记成成功。
        $this->assertOutcome($this->outcomesFrom($data['steps'] ?? [], 'success', 'failed'),
            self::S_SOFTFAIL, false, true, '步骤健康');
        $this->assertOutcome($this->outcomesFrom($data['steps'] ?? [], 'success', 'failed'),
            self::S_SILENT, true, false, '步骤健康');
    }

    /**
     * 步骤统计页（PHP 侧，同一份 error-only 判据的第三份拷贝）：
     * 这条线以前没有任何测试覆盖，改回旧写法不会有人知道。
     */
    public function test_the_step_stats_panel_scores_steps_the_canonical_way(): void
    {
        $this->seedExecution();

        $data = $this->call('stepStats', ['days' => 7])['data'];
        $outcomes = $this->outcomesFrom($data['items'] ?? [], 'succeeded', 'failed');

        // stepStats 不按 workflow 过滤，所以只断言自己造的五行；每行恒 1 次执行。
        foreach ([
            self::S_SILENT => [true, false],
            self::S_FLAGGED => [true, false],
            self::S_ERRORED => [false, true],
            self::S_SOFTFAIL => [false, true],
            self::S_OKCALL => [true, false],
        ] as $step => [$ok, $bad]) {
            $this->assertOutcome($outcomes, $step, $ok, $bad, '步骤统计');
            self::assertSame(1, $this->execCount($data['items'] ?? [], $step),
                "步骤统计里 {$step} 的次数不是 1：造行没被干净地隔离");
        }
    }

    /**
     * API 聚合面板（PHP 侧，第四份拷贝，且多带一个 http_code 条件）：
     * 5xx 的 api_call 不带 error 键，旧判据把它算成成功。
     */
    public function test_the_api_call_panel_scores_steps_the_canonical_way(): void
    {
        $this->seedExecution();

        $data = $this->call('apiCallAggregation', ['days' => 7])['data'];

        $byStep = [];
        foreach ((array) ($data['endpoints'] ?? []) as $row) {
            foreach ((array) ($row['steps'] ?? []) as $step) {
                $byStep[(string) $step] = [
                    (int) ($row['success_count'] ?? -1) > 0,
                    (int) ($row['failed_count'] ?? -1) > 0,
                ];
            }
        }

        // 正对照：2xx 的调用必须算成功，否则「全判失败」这种反向写错也能过。
        $this->assertOutcome($byStep, self::S_OKCALL, true, false, 'API 聚合');
        $this->assertOutcome($byStep, self::S_SOFTFAIL, false, true, 'API 聚合');
    }

    /**
     * 执行链路时间线（第五份拷贝）：每步的 status 文案必须由同一个判据出。
     */
    public function test_the_step_trace_labels_steps_the_canonical_way(): void
    {
        $id = $this->seedExecution();

        $data = $this->call('executionStepTrace', [], ['id' => \app\support\HashId::enc($id)])['data'];

        $statuses = [];
        foreach ((array) ($data['steps'] ?? []) as $row) {
            $statuses[(string) $row['name']] = (string) ($row['status'] ?? '');
        }

        self::assertSame('succeeded', $statuses[self::S_SILENT] ?? null, '链路时间线把无 success 键的步骤标成失败');
        self::assertSame('failed', $statuses[self::S_SOFTFAIL] ?? null, '链路时间线把 success:false 标成成功');
        self::assertSame(2, (int) ($data['stats']['failed_steps'] ?? -1),
            '链路时间线的失败步数不是 2（errored + softfail）');
    }

    /**
     * 两个聚合面板对同一个步骤必须给同一个成功数 —— 这条是「两处不许自相矛盾」的本体，
     * 任一侧被改回旧写法都会在这里变红（上面各条只挡自己那一侧的错法）。
     */
    public function test_the_dashboards_agree_on_every_step(): void
    {
        $this->seedExecution();

        $perf = [];
        foreach ((array) ($this->call('stepPerformance', ['workflow' => self::WF])['data']['by_step'] ?? []) as $row) {
            $perf[(string) $row['step_name']] = (int) ($row['success_count'] ?? 0);
        }

        $health = [];
        foreach ((array) ($this->call('stepHealth', ['workflow' => self::WF])['data']['steps'] ?? []) as $row) {
            $health[(string) $row['step_name']] = (int) ($row['success'] ?? 0);
        }

        $stats = [];
        foreach ((array) ($this->call('stepStats', ['days' => 7])['data']['items'] ?? []) as $row) {
            $name = (string) ($row['step_name'] ?? '');
            if (str_starts_with($name, 'zz-')) {
                $stats[$name] = (int) ($row['succeeded'] ?? 0);
            }
        }

        self::assertNotSame([], $perf, '性能分布没返回任何步骤');
        // 三者键的**顺序**本来就不同（SQL 的 GROUP BY 序 vs PHP 的遍历序），所以各自排序再整体比。
        // 不用 array_intersect_key：那会把「只在一侧出现的步骤」从两边同时抹掉，
        // 少一步反而判绿 —— 而少一步正是这次修掉的那个引用覆盖 bug 的表现。
        ksort($perf);
        ksort($health);
        ksort($stats);
        self::assertSame($perf, $health, '同一个步骤在性能分布与步骤健康里的成功数不一致');
        self::assertSame($perf, $stats, '同一个步骤在性能分布与步骤统计里的成功数不一致');
    }

    /**
     * 失败判据也要一致：`error` 为空串时算不算失败，两侧必须同一个答案。
     * （旧的性能分布用 `IS NOT NULL AND <> ''`，旧的 stepStats 只用 `isset` —— 后者把空串算成失败。）
     */
    public function test_the_failure_rule_ignores_an_empty_error_string(): void
    {
        $this->execIds[] = (int) Db::insert(
            "INSERT INTO kode_lowcode_workflow_executions
                (workflow_name, workflow_id, status, step_count, duration_ms, step_results_json)
             VALUES (?, ?, 'succeeded', 1, 10, ?::jsonb)",
            [self::WF . '-empty', 0, json_encode(['zz-blank' => ['_duration_ms' => 5, 'error' => '']])]
        );

        $perf = $this->outcomesFrom(
            (array) ($this->call('stepPerformance', ['workflow' => self::WF . '-empty'])['data']['by_step'] ?? []),
            'success_count',
            'failed_count',
            'step_name'
        );
        self::assertSame(
            ['zz-blank' => [true, false]],
            $perf,
            '空串 error 在性能分布里被判成失败：两侧判据的「有没有 error」口径不同'
        );

        $health = $this->outcomesFrom(
            (array) ($this->call('stepHealth', ['workflow' => self::WF . '-empty'])['data']['steps'] ?? []),
            'success',
            'failed'
        );
        self::assertSame(
            ['zz-blank' => [true, false]],
            $health,
            '空串 error 在步骤健康里被判成失败：与性能分布不同源'
        );

        // stepStats 全站聚合、不按 workflow 过滤，所以只看自己造的这一行。
        $blank = $this->outcomesFrom(
            (array) ($this->call('stepStats', ['days' => 7])['data']['items'] ?? []),
            'succeeded',
            'failed'
        )['zz-blank'] ?? null;
        self::assertSame(
            [true, false],
            $blank,
            '空串 error 在步骤统计里被判成失败：与性能分布不同源'
        );
    }

    /**
     * 「判据只写一份」的静态面：五条判据里那三种旧写法不许在插件源码里复活。
     *
     * 这里刻意只禁**成败判定**的写法，不禁 `isset($stepResult['error'])` 本身 ——
     * 另有四处问的是「这条结果带不带错误文案」（收集 error 串、判断是否超时），
     * 那是另一个问题，把它们一起禁掉只会逼人把判据藏进更绕的写法。
     */
    public function test_no_second_copy_of_the_outcome_rule_survives(): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__) . '/plugins/frontend/WorkflowBridgeController.php');
        self::assertNotSame('', $raw, '读不到控制器源码：这条门禁会空转');

        // 注释里写着「旧写法是 (value->>'success') = 'true'」是**说明**，不是实现。
        // 不剥注释的话这条门禁会为了让测试变绿而逼着人删掉解释——正好删掉最该留下的那段。
        $src = self::codeOnly($raw);

        // 正对照：判据本体必须在文件里，否则「扫到 0 处」和「全部合规」长得一模一样。
        self::assertStringContainsString('private static function stepOutcome(', $src);
        self::assertStringContainsString('private static function stepOutcomeSql(', $src);
        self::assertGreaterThan(8, substr_count($src, 'self::stepOutcome('),
            '调用点少于 8 处：判据被改回各写一遍了');
        // 反向对照：剥注释确实剥掉了那段引用旧写法的说明（否则上面三条等于没做事）。
        self::assertLessThan(
            substr_count($raw, "->>'success') = 'true'"),
            substr_count($src, "->>'success') = 'true'"),
            '注释没被剥掉：静态扫描正在读文档而不是代码'
        );

        $forbidden = [
            // SQL 侧只认 success 键
            "/->>\s*'success'\s*\)\s*=\s*'true'/",
            // PHP 侧：把 error 键的有无直接当成成败判定
            "/\\\$(?:isFailed|stepFailed|success|isOk|stepOk)\\s*=\\s*[^;\\n]*isset\\(\\s*\\\$stepResult\\['error'\\]\\s*\\)/",
            // PHP 侧：自己重写一份 success !== false && !hasError
            "/\\(\\\$stepResult\\['success'\\]\\s*\\?\\?\\s*true\\)\\s*!==\\s*false/",
            // 写侧：落库前用「有没有 error 键」盖掉 executeApiCall 已经写好的 success:false
            // （四处执行器都曾这么写，api_call 的 5xx 因此在入库那一刻被翻成成功）
            "/\\['success'\\]\\s*=\\s*!\\s*isset\\(\\s*\\\$stepResult\\['error'\\]\\s*\\)/",
        ];

        foreach ($forbidden as $pattern) {
            self::assertSame(0, preg_match_all($pattern, $src),
                "判据又出现了一份独立实现：{$pattern}");
        }
    }

    /**
     * 用 token 流去掉注释与字符串，只留代码骨架。
     */
    private static function codeOnly(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /**
     * 把「一行聚合结果」摊成 step_name => [有成功, 有失败]。
     *
     * @param mixed $rows
     * @return array<string, bool[]>
     */
    private function outcomesFrom($rows, string $okKey, string $badKey, string $nameKey = 'step_name'): array
    {
        $out = [];
        foreach ((array) $rows as $row) {
            $out[(string) ($row[$nameKey] ?? '')] = [
                (int) ($row[$okKey] ?? -1) > 0,
                (int) ($row[$badKey] ?? -1) > 0,
            ];
        }

        return $out;
    }

    /**
     * @param mixed $rows
     */
    private function execCount($rows, string $step): int
    {
        foreach ((array) $rows as $row) {
            if ((string) ($row['step_name'] ?? '') === $step) {
                return (int) ($row['executions'] ?? -1);
            }
        }

        return -1;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $routeParams
     * @return array{status: int, body: array<string, mixed>, data: array<string, mixed>}
     */
    private function call(string $handler, array $query, array $routeParams = []): array
    {
        $request = (new ServerRequest('GET', '/api/workflow/probe?' . http_build_query($query)))
            ->withQueryParams($query)
            ->withAttribute('_route_params', $routeParams)
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => self::WF, 'roles' => ['admin']]);
        HttpRequest::setRequest($request);

        $response = (new WorkflowBridgeController())->{$handler}();
        self::assertInstanceOf(ResponseInterface::class, $response);

        $body = (array) json_decode((string) $response->getBody(), true);
        $payload = $body['data'] ?? [];

        return [
            'status' => $response->getStatusCode(),
            'body' => $body,
            'data' => (array) ($payload['data'] ?? $payload),
        ];
    }
}
