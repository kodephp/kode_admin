<?php

declare(strict_types=1);

namespace app\tests;

use app\support\HashId;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Plugins\frontend\WorkflowBridgeController;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * 工作流桥这四个列表入口的窗口口径（AGENTS.md §4「列表入口 = pageWindow() + 一条 COUNT(*) +
 * $this->page()，没有第二种形状」在插件侧剩下的四处）。
 *
 * 修之前的形状，四条各有各的缺法：
 *  - `executionsList` / `webhookLogs`：`ORDER BY ... LIMIT ? OFFSET ?` 里那个 OFFSET **写死 0** ——
 *    接口收了 `limit`（最多 200）却压根没有 `page`，所以「第 2 页」在这条 URL 上不存在：
 *    第 201 条之后的执行流水永久取不到，而同一份响应里的 `total` 明明白白写着「共 1234 条」，
 *    等于一边承认有、一边取不出（前端 `execTotal` 还赋值了却从不渲染，所以页面上连这个数都不显示）；
 *  - `versionList`：`LIMIT 50` 写死且**完全没有 total**，多于 50 个版本的定义连「被切过」这件事都没有痕迹；
 *  - `configHistory`：`LIMIT`（默认 20）之后把 `count($versionList)` 填进
 *    `diff_summary.total_versions`，而管理端那张卡的标题就叫**「版本总数」**
 *    （`views/kode/workflow-bridge/index.vue`：`:value="configHistoryData.versions?.length"`）——
 *    一个 37 个版本的定义，页面上印着「版本总数 20」。这与 #131 修掉的「total 数的是这一批」同形。
 *
 * 现在的口径：前三个走真分页（窗口三件套 + 同一条 WHERE 的 COUNT），第四个是**相邻版本对比**
 * 这类只能在 PHP 里算的聚合（判据只有一份实现：`compareWorkflowVersions`），允许抽样，
 * 但必须把抽样规模如实报出来 —— `total_versions` 改成 SQL 侧的全集数，另加 `scanned_versions` + `truncated`。
 *
 * 造行一律挂在自己的工作流名/定义 id 下，tearDown 按主键与归属名精删，不建库不建表。
 */
final class WorkflowListWindowTest extends TestCase
{
    protected bool $independentApp = true;

    private const TAG = 'zz-listwin';

    /** @var list<int> */
    private array $execIds = [];

    /** @var list<int> */
    private array $logIds = [];

    /** @var list<int> */
    private array $versionIds = [];

    private ?int $defId = null;

    private string $wf = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        // 每轮一个独占的工作流名：查询按名过滤，绝不碰库存量行
        $this->wf = self::TAG . '-' . substr(md5(uniqid('', true)), 0, 8);
    }

    protected function tearDown(): void
    {
        foreach ($this->execIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_workflow_executions WHERE id = ?', [$id]);
        }
        foreach ($this->logIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_workflow_webhook_logs WHERE id = ?', [$id]);
        }
        foreach ($this->versionIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_workflow_versions WHERE id = ?', [$id]);
        }
        if ($this->defId !== null) {
            Db::delete('DELETE FROM kode_lowcode_workflows WHERE id = ?', [$this->defId]);
        }
        $this->execIds = $this->logIds = $this->versionIds = [];
        $this->defId = null;

        HttpRequest::clear();

        parent::tearDown();
    }

    /* ==================== /api/workflow/executions ==================== */

    /** `page` 必须真的挪 OFFSET：写死 OFFSET 0 时两页返回同一批行，而响应看不出这件事。 */
    public function test_the_execution_list_pages_instead_of_repeating_the_first_page(): void
    {
        $this->seedExecutions(3);

        $first = $this->call('executionsList', ['workflow' => $this->wf, 'page' => 1, 'size' => 2]);
        $second = $this->call('executionsList', ['workflow' => $this->wf, 'page' => 2, 'size' => 2]);

        self::assertSame(200, $first['status'], '执行列表没回 200：先看状态码再看信封断言');
        self::assertSame(3, $first['body']['total'] ?? null, 'total 没与 data 同级：分页条算不出页数');
        self::assertSame(2, count($first['body']['data'] ?? []), '第 1 页行数与 size=2 不符');
        self::assertSame(2, $first['body']['size'] ?? null, 'size 回显不对：夹取后的窗口没告诉客户端真实窗口');
        self::assertSame(2, $second['body']['page'] ?? null, 'page 回显不对：翻页会停在第 1 页');
        self::assertSame(1, count($second['body']['data'] ?? []), '第 2 页的行数与窗口不符');

        self::assertSame(
            [],
            array_intersect(
                array_column($first['body']['data'], 'id'),
                array_column($second['body']['data'], 'id')
            ),
            '两页返回了同一批行：OFFSET 仍然写死 0'
        );
    }

    /** total 是「筛选后的全集」，不是当页行数，也不许把 status 条件丢掉。 */
    public function test_the_execution_total_follows_the_status_filter(): void
    {
        $this->seedExecutions(2, 'succeeded');
        $this->seedExecutions(1, 'failed');

        $one = $this->call('executionsList', ['workflow' => $this->wf, 'status' => 'failed', 'size' => 1]);

        self::assertSame(1, $one['body']['total'] ?? null, 'total 没跟 status 走：列表是过滤后的，计数还写着全量');
        self::assertSame(1, count($one['body']['data'] ?? []), '当页行数没跟 size=1 走：窗口没生效');
    }

    /* ==================== /api/workflow/webhook-logs ==================== */

    public function test_the_webhook_log_list_pages_instead_of_repeating_the_first_page(): void
    {
        $this->seedWebhookLogs(3);

        $first = $this->call('webhookLogs', ['workflow' => $this->wf, 'page' => 1, 'size' => 2]);
        $second = $this->call('webhookLogs', ['workflow' => $this->wf, 'page' => 2, 'size' => 2]);

        self::assertSame(3, $first['body']['total'] ?? null, '投递日志没有总数：列表分页器只能按本页行数编一个');
        self::assertSame(2, count($first['body']['data'] ?? []), '第 1 页行数与 size 不符');
        self::assertSame(1, count($second['body']['data'] ?? []), '末页行数应为剩余条数：OFFSET 仍然写死 0');
        self::assertSame(
            [],
            array_intersect(
                array_column($first['body']['data'], 'id'),
                array_column($second['body']['data'], 'id')
            ),
            '两页返回了同一批投递日志：OFFSET 仍然写死 0'
        );
    }

    /* ==================== /api/workflow/definitions/{id}/versions ==================== */

    public function test_the_version_list_pages_and_reports_its_total(): void
    {
        $this->seedDefinitionWithVersions(3);

        $first = $this->call('versionList', ['page' => 1, 'size' => 2], ['id' => HashId::enc($this->defId)]);
        $second = $this->call('versionList', ['page' => 2, 'size' => 2], ['id' => HashId::enc($this->defId)]);

        self::assertSame(200, $first['status'], '版本历史列表没回 200：先读后面几条断言之前的状态码');
        self::assertSame(3, $first['body']['total'] ?? null, '版本历史没有任何总数：多于 LIMIT 的版本静默失踪');
        self::assertSame(2, count($first['body']['data'] ?? []), '第 1 页行数与 size 不符');
        self::assertSame(1, count($second['body']['data'] ?? []), '末页只剩 1 个版本：OFFSET 仍然写死 0');
        self::assertSame(
            [],
            array_intersect(
                array_column($first['body']['data'], 'id'),
                array_column($second['body']['data'], 'id')
            ),
            '两页返回了同一批版本'
        );
    }

    /* ==================== /api/workflow/config-history ==================== */

    /**
     * 「版本总数」那张卡必须数的是全集，抽样规模另报一对键。
     *
     * @param array<string, mixed> $expect
     */
    #[DataProvider('configHistoryShapes')]
    public function test_config_history_separates_the_full_count_from_the_sample(
        int $versions,
        int $limit,
        array $expect
    ): void {
        $this->seedDefinitionWithVersions($versions);

        $res = $this->call('configHistory', ['workflow' => $this->wf, 'limit' => $limit]);

        self::assertSame(200, $res['status'], '配置历史没回 200：下面的键断言都是按信封形状读的');
        $data = $res['body']['data'] ?? [];
        self::assertSame(
            $expect['total_versions'],
            $data['diff_summary']['total_versions'] ?? null,
            'total_versions 数的是被 limit 切过的那一批，不是这个定义的全部版本'
        );
        self::assertSame(
            $expect['scanned_versions'],
            $data['scanned_versions'] ?? null,
            '没有 scanned_versions：客户端无从知道差异是按几条版本算的'
        );
        self::assertSame(
            $expect['truncated'],
            $data['truncated'] ?? null,
            '没有 truncated：「共 37 条只列 20 条」和「一共 20 条」在页面上长得一样'
        );
        self::assertSame($expect['listed'], count($data['versions'] ?? []));
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: array<string, mixed>}>
     */
    public static function configHistoryShapes(): array
    {
        return [
            // 5 个版本只取最近 2 个：差异只能按这 2 条算，但总数要说真话
            '抽样被截断' => [5, 2, [
                'total_versions' => 5,
                'scanned_versions' => 2,
                'truncated' => true,
                'listed' => 2,
            ]],
            // 全取：truncated 必须是 false 而不是缺键（缺键与「老后端」无法区分）
            '窗口装得下' => [3, 10, [
                'total_versions' => 3,
                'scanned_versions' => 3,
                'truncated' => false,
                'listed' => 3,
            ]],
        ];
    }

    /* ==================== 工具 ==================== */

    private function seedExecutions(int $n, string $status = 'succeeded'): void
    {
        $base = count($this->execIds);
        for ($i = 1; $i <= $n; $i++) {
            $this->execIds[] = (int) Db::insert(
                'INSERT INTO kode_lowcode_workflow_executions
                    (workflow_name, workflow_id, status, step_count, duration_ms, created_at)
                 VALUES (?, 0, ?, 1, ?, NOW() - make_interval(secs => ?))',
                [$this->wf, $status, 10 + $base + $i, $base + $i]
            );
        }
    }

    private function seedWebhookLogs(int $n): void
    {
        $base = count($this->logIds);
        for ($i = 1; $i <= $n; $i++) {
            $this->logIds[] = (int) Db::insert(
                "INSERT INTO kode_lowcode_workflow_webhook_logs
                    (workflow_id, workflow_name, execution_id, target_url, status, http_code, duration_ms, created_at)
                 VALUES (0, ?, 0, ?, 'delivered', 200, ?, NOW() - make_interval(secs => ?))",
                [$this->wf, 'http://127.0.0.1/zz-hook', 5 + $base + $i, $base + $i]
            );
        }
    }

    private function seedDefinitionWithVersions(int $n): void
    {
        $this->defId = (int) Db::insert(
            'INSERT INTO kode_lowcode_workflows (name, description, status, created_by) VALUES (?, ?, 1, ?)',
            [$this->wf, self::TAG . ' 描述', self::TAG]
        );

        $base = count($this->versionIds);
        for ($i = 1; $i <= $n; $i++) {
            $this->versionIds[] = (int) Db::insert(
                'INSERT INTO kode_lowcode_workflow_versions
                    (workflow_id, workflow_name, version, steps_json, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW() - make_interval(secs => ?))',
                [
                    $this->defId,
                    $this->wf,
                    '1.0.' . ($base + $i),
                    json_encode([['name' => 'step_' . ($base + $i), 'type' => 'log']]),
                    self::TAG,
                    $base + $i,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $routeParams
     * @return array{status: int, body: array<string, mixed>}
     */
    private function call(string $handler, array $query = [], array $routeParams = []): array
    {
        $request = (new ServerRequest('GET', '/api/workflow/' . $handler . '?' . http_build_query($query)))
            ->withQueryParams($query)
            ->withAttribute('_route_params', $routeParams)
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
