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
 * `GET /api/workflow/budgets` 每行那条「接近上限」的橙色标签（AGENTS.md §7 的工作流桥面板契约）。
 *
 * 修之前的判据是 `'warning' => !$alerts && $ratio >= 0.8`，而 `$alerts` 是**正在往里追加**的那个数组，
 * 于是 `!$alerts` 只对**第一条**告警成立 —— 四条指标里只有排在最前的 `daily_exec` 有机会被标成「接近」，
 * `hourly_exec`/`avg_duration`/`daily_api` 三条永远是 false。后果不是少一个颜色：
 * 标签是 `v-if="exceeded_count > 0"` / `v-else-if="alerts.some(a => a.warning)"` / `v-else` 三段，
 * 于是「API 调用量已经用到上限的 90%」这一行渲染成 **✅ 正常**，而这一列在页面上就一行字，
 * 没有任何别的地方会说出这个数（同 §4「读不到与没有这些数据必须是两件事」的另一半：这里连读都读错了）。
 * 反方向同样错：`daily_exec` 超限时它是第一条告警，于是 `exceeded` 与 `warning` 同时为 true，
 * 一个已经越界的指标还被标成「只是接近」。
 *
 * 现在的口径：`warning` 与 `exceeded` 互斥 —— 达到上限算 exceeded，80%..100% 算 warning，
 * 且**每一条指标都能拿到 warning**。
 *
 * 造行挂在自己的工作流名下，tearDown 按主键精删（预算一行 + 它自己那批执行流水），不碰库存量行。
 */
final class WorkflowBudgetAlertTest extends TestCase
{
    protected bool $independentApp = true;

    private const TAG = 'zz-budget';

    private string $wf = '';

    /** @var list<int> */
    private array $budgetIds = [];

    /** @var list<int> */
    private array $execIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        $this->wf = self::TAG . '-' . substr(md5(uniqid('', true)), 0, 8);
    }

    protected function tearDown(): void
    {
        foreach ($this->budgetIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_workflow_budgets WHERE id = ?', [$id]);
        }
        foreach ($this->execIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_workflow_executions WHERE id = ?', [$id]);
        }
        $this->budgetIds = $this->execIds = [];

        HttpRequest::clear();

        parent::tearDown();
    }

    /**
     * 「接近上限」必须对每一条指标都成立，而不是只对 `alerts` 数组里的第一条。
     *
     * 夹具是刻意配成不对称的：`daily_exec` 用掉 1%、`daily_api` 用掉 90%，
     * 另两条上限留 0（`$addAlert` 会跳过），于是榜上恰好两条、被抽到的那一行落在后一条上。
     */
    public function test_a_metric_near_its_limit_is_flagged_regardless_of_its_position(): void
    {
        $this->seedBudget(['daily_exec_limit' => 100, 'daily_api_limit' => 10]);
        $this->seedExecution(1, 9);

        $row = $this->budgetRow();

        $byMetric = [];
        foreach ($row['alerts'] as $a) {
            $byMetric[$a['metric']] = $a;
        }

        self::assertSame(['daily_exec', 'daily_api'], array_keys($byMetric), '告警榜的形状变了：下面的断言按这两条读');

        self::assertTrue(
            $byMetric['daily_api']['warning'] ?? null,
            'daily_api 已用到上限的 90% 却没标 warning：判据只认 alerts 里的第一条'
        );
        self::assertFalse($byMetric['daily_api']['exceeded'] ?? true, '90% 不是超限');

        self::assertFalse(
            $byMetric['daily_exec']['warning'] ?? true,
            'daily_exec 只用了 1% 却标了 warning：那一条恰好是榜上的第一条'
        );
    }

    /** 已经越界的指标算 exceeded，不许同时算「只是接近」。 */
    public function test_an_exceeded_metric_is_not_also_reported_as_approaching(): void
    {
        $this->seedBudget(['daily_exec_limit' => 1, 'daily_api_limit' => 0]);
        $this->seedExecution(2, 0);

        $row = $this->budgetRow();

        self::assertSame(1, $row['exceeded_count'] ?? null, '超限计数不对：一条越界指标应当恰好算一次');

        $alerts = $row['alerts'];
        self::assertTrue($alerts[0]['exceeded'] ?? null, 'daily_exec 用 2/1 必须算超限');
        self::assertFalse(
            $alerts[0]['warning'] ?? true,
            '越界的指标同时被标成 warning：面板上它读作「接近上限」而不是「已经超了」'
        );
    }

    /* ==================== 夹具 ==================== */

    /**
     * @param array<string, int> $limits
     */
    private function seedBudget(array $limits): void
    {
        $this->budgetIds[] = (int) Db::insert(
            'INSERT INTO kode_lowcode_workflow_budgets
                (workflow_name, daily_exec_limit, hourly_exec_limit, avg_duration_limit_ms, daily_api_limit, alert_enabled)
             VALUES (?, ?, ?, ?, ?, 1)',
            [
                $this->wf,
                $limits['daily_exec_limit'] ?? 0,
                $limits['hourly_exec_limit'] ?? 0,
                $limits['avg_duration_limit_ms'] ?? 0,
                $limits['daily_api_limit'] ?? 0,
            ]
        );
    }

    /** 一条执行流水：`$execs` 决定它自己算 1 次执行，`$apiCalls` 进 24h 的 API 调用量合计。 */
    private function seedExecution(int $execs, int $apiCalls): void
    {
        for ($i = 0; $i < $execs; $i++) {
            $this->execIds[] = (int) Db::insert(
                'INSERT INTO kode_lowcode_workflow_executions
                    (workflow_name, workflow_id, status, step_count, duration_ms, api_calls, created_at)
                 VALUES (?, 0, ?, 1, ?, ?, NOW())',
                [$this->wf, 'succeeded', 100, $apiCalls]
            );
        }
    }

    /**
     * 取本轮那一条预算行（按工作流名过滤，绝不读到别人的行）。
     *
     * @return array<string, mixed>
     */
    private function budgetRow(): array
    {
        $res = $this->call('budgetList', ['workflow' => $this->wf]);

        self::assertSame(200, $res['status'], '预算列表没回 200：下面的断言是按载荷里的 alerts 读的');
        // 这个 handler 走 `ok(['data' => [...]])`，信封的 data 里还有一层 data（前端同名口径见
        // views/kode/workflow-bridge/index.vue 的 `res.data?.data ?? res.data`）。
        $payload = $res['body']['data']['data'] ?? [];
        self::assertArrayHasKey('budgets', $payload, '预算列表出参形状变了：没有 budgets 键');
        $budgets = $payload['budgets'];
        self::assertCount(1, $budgets, sprintf(
            '按工作流名过滤后应当只有本轮这一条预算行（filters.workflow=%s，信封=%s）',
            $this->wf,
            json_encode($payload['total'] ?? null)
        ));

        return $budgets[0];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function call(string $handler, array $query = []): array
    {
        $request = (new ServerRequest('GET', '/api/workflow/' . $handler . '?' . http_build_query($query)))
            ->withQueryParams($query)
            ->withAttribute('_route_params', [])
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => self::TAG, 'roles' => ['admin']]);
        HttpRequest::setRequest($request);

        $response = (new WorkflowBridgeController())->{$handler}();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }
}
