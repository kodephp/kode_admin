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
 * `GET /api/workflow/definitions` 的窗口与总数口径。
 *
 * 修之前这一条是「写死 `ORDER BY id DESC LIMIT 200` + `total = count($items)`」：
 * 定义多于 200 条时，列表静默只剩最近 200 条，而 `total` 数的是**这一批**，
 * 于是页面上的「共 N 条」在 201 条之后仍是 200 —— 与 #127 修掉的 `licensesIndex`
 * 一字不差同形（口径见 AGENTS.md §4「列表入口 = pageWindow() + 一条 COUNT(*) + $this->page()，
 * 没有第二种形状」）。前端那一侧的 `total.value` 此前写了却从不渲染（表是
 * `:pagination="false"`），所以第 201 条定义连「看不见」的迹象都没有。
 *
 * 夹具走 `tests/LowcodeListWindowTest.php` 的 dispatch 形状：真请求 + 真库 + 真 handler，
 * 探针行按 id 逐条删除，不碰别人的数据。
 */
final class WorkflowDefinitionListWindowTest extends TestCase
{
    protected bool $independentApp = true;

    private const PROBE = 'zz-deflist';

    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->ids as $id) {
            Db::delete('DELETE FROM kode_lowcode_workflows WHERE id = ?', [$id]);
        }

        HttpRequest::clear();

        parent::tearDown();
    }

    public function test_the_definition_list_honours_the_page_window(): void
    {
        $this->seed(3);

        $first = $this->call(['page' => 1, 'size' => 2, 'keyword' => self::PROBE]);
        $second = $this->call(['page' => 2, 'size' => 2, 'keyword' => self::PROBE]);

        self::assertSame(200, $first['status']);
        self::assertSame(3, $first['body']['total'] ?? null, 'total 没回或数的是这一批：分页条算不出页数');
        self::assertSame(2, count($first['body']['data'] ?? []));
        self::assertSame(2, $first['body']['size'] ?? null);
        self::assertSame(2, $second['body']['page'] ?? null, 'page 回显不对：分页条会停在第 1 页');
        self::assertSame(1, count($second['body']['data'] ?? []), '第 2 页的行数与窗口不符');

        $ids1 = array_column($first['body']['data'], 'id');
        $ids2 = array_column($second['body']['data'], 'id');
        self::assertSame(
            [],
            array_intersect($ids1, $ids2),
            '两页返回了同一批行：page/size 仍然没进 OFFSET'
        );
    }

    /** total 是「筛选后的全集」，不是当页行数 —— 这一条直接钉住 `count($items)` 那个假数。 */
    public function test_total_describes_the_filtered_set_not_the_page(): void
    {
        $this->seed(2);
        $this->seed(1, 0);

        $oneRow = $this->call(['page' => 1, 'size' => 1, 'keyword' => self::PROBE]);
        self::assertSame(3, $oneRow['body']['total'] ?? null, 'total 数的是这一页而不是筛选后的全集');
        self::assertSame(1, count($oneRow['body']['data'] ?? []));

        $disabled = $this->call(['keyword' => self::PROBE, 'status' => '0']);
        self::assertSame(
            1,
            $disabled['body']['total'] ?? null,
            'total 没跟 status 走：列表是过滤后的，计数还写着全量'
        );
    }

    /* ==================== 工具 ==================== */

    private function seed(int $n, int $status = 1): void
    {
        $base = count($this->ids);
        for ($i = 1; $i <= $n; $i++) {
            $this->ids[] = (int) Db::insert(
                'INSERT INTO kode_lowcode_workflows (name, description, status, created_by) VALUES (?, ?, ?, ?)',
                [
                    self::PROBE . '-wf' . ($base + $i),
                    self::PROBE . ' 描述',
                    $status,
                    self::PROBE,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function call(array $query): array
    {
        $request = (new ServerRequest('GET', '/api/workflow/definitions?' . http_build_query($query)))
            ->withQueryParams($query)
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => self::PROBE, 'roles' => ['admin']]);
        HttpRequest::setRequest($request);

        $response = (new WorkflowBridgeController())->definitionsIndex();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }
}
