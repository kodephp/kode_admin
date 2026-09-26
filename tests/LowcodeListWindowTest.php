<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Plugins\frontend\BuilderController;
use Psr\Http\Message\ResponseInterface;

/**
 * 低代码三个列表入口的分页与聚合口径（AGENTS.md §3「分页统一 {code,msg,dur,data,total,page,size}」）。
 *
 * 同一份 `pageWindow()` 判据在这里有三个缺口，缺口方向各不相同：
 *  - `licensesIndex` 写死 `LIMIT 200` 且**完全不看** `page`/`size` —— 而管理端 license 页
 *    一直在发这两个参数（`views/kode/lowcode-licenses/index.vue`）。于是第 2 页返回的是
 *    第 1 页的内容、`total` 缺键被前端回落成「本页条数」，翻到第 201 条之后的授权永久看不见；
 *  - `pagesIndex` 一个 `LIMIT` 都没有：一个项目的页面数没有产品上限，一次请求出多少行全看库里有多少；
 *  - `buildsIndex` 写死 `LIMIT 100`，同样不回 `total`（构建历史过 100 次就静默截断）。
 *
 * 另有一条是聚合口径：license 页顶部那三张分布卡（生效/过期/吊销）此前由前端在**当页**行里数，
 * 一旦列表真分页，卡片就从「全站分布」静默变成「本页分布」—— 与 §4「跨分片聚合不许按行抽样」
 * 同一类错（数字看着正常，含义已经换了）。现在分布由 SQL 侧的 `FILTER` 一次算出，随分页响应
 * 一起出（`stats`），并且**跟着筛选条件走**，所以搜一次客户名时卡片不会还写着全站数。
 */
final class LowcodeListWindowTest extends TestCase
{
    protected bool $independentApp = true;

    private const PROBE = 'zz-listwin';

    /** @var list<int> */
    private array $projectIds = [];

    /** @var list<int> */
    private array $licenseIds = [];

    /** @var list<int> */
    private array $pageIds = [];

    /** @var list<int> */
    private array $jobIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->jobIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_build_jobs WHERE id = ?', [$id]);
        }
        foreach ($this->pageIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_pages WHERE id = ?', [$id]);
        }
        foreach ($this->licenseIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_licenses WHERE id = ?', [$id]);
        }
        foreach ($this->projectIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_projects WHERE id = ?', [$id]);
        }

        HttpRequest::clear();

        parent::tearDown();
    }

    /* ==================== licenses ==================== */

    /** 翻页必须真的换一批：此前 `page`/`size` 被完全忽略，第 2 页 == 第 1 页。 */
    public function test_the_license_list_honours_the_page_window(): void
    {
        $this->seedLicenses(3);

        $first = $this->call('/api/lowcode/licenses', 'licensesIndex', ['page' => 1, 'size' => 2, 'keyword' => self::PROBE]);
        $second = $this->call('/api/lowcode/licenses', 'licensesIndex', ['page' => 2, 'size' => 2, 'keyword' => self::PROBE]);

        self::assertSame(200, $first['status']);
        self::assertSame(3, $first['body']['total'] ?? null, 'total 没回或回错：分页条会算不出页数');
        self::assertSame(2, count($first['body']['data'] ?? []));
        self::assertSame(2, $first['body']['size'] ?? null);
        self::assertSame(2, $second['body']['page'] ?? null, 'page 回显不对：分页条会停在第 1 页');
        self::assertSame(1, count($second['body']['data'] ?? []), '第 2 页的行数和窗口不符');

        $ids1 = array_column($first['body']['data'], 'license_id');
        $ids2 = array_column($second['body']['data'], 'license_id');
        self::assertSame(
            [],
            array_intersect($ids1, $ids2),
            '两页返回了同一批行：page/size 仍然没进 OFFSET'
        );
    }

    /** 分布卡是「筛选后的全集」而不是「这一页」，且随 keyword 收窄。 */
    public function test_the_license_stats_describe_the_filtered_set_not_the_page(): void
    {
        // 3 条：1 生效（未到期）、1 已过期（显式 expired）、1 active 但 end_at 已过
        $this->seedLicenses(2);
        Db::insert(
            "INSERT INTO kode_lowcode_licenses (license_id, customer_name, status, end_at)
             VALUES (?, ?, 'active', (CURRENT_DATE - INTERVAL '5 day')::date)",
            [self::PROBE . '-past', self::PROBE . ' 过期']
        );
        $this->trackLastLicense();

        $all = $this->call('/api/lowcode/licenses', 'licensesIndex', ['page' => 1, 'size' => 1, 'keyword' => self::PROBE]);
        $stats = $all['body']['stats'] ?? null;
        self::assertIsArray($stats, '分页响应没带 stats：前端卡片会退回「数当页行」');
        self::assertSame(3, $stats['total'] ?? null);
        self::assertSame(1, $stats['active'] ?? null, '到期日已过的 active 仍算生效');
        self::assertSame(2, $stats['expired'] ?? null, 'expired 的两种来源没有合并（显式状态 / 到期日）');
        self::assertSame(
            1,
            count($all['body']['data'] ?? []),
            '窗口本身不对，stats 与 data 的关系就无从谈起'
        );

        $onlyActive = $this->call('/api/lowcode/licenses', 'licensesIndex', ['keyword' => self::PROBE, 'status' => 'active']);
        self::assertSame(
            2,
            $onlyActive['body']['stats']['total'] ?? null,
            'stats 没跟筛选条件走：卡片写着全站数，列表却是过滤后的'
        );
    }

    /* ==================== pages / builds ==================== */

    public function test_the_project_page_list_is_paged_and_reports_its_total(): void
    {
        $projectId = $this->seedProject();
        for ($i = 1; $i <= 3; $i++) {
            $this->pageIds[] = (int) Db::insert(
                "INSERT INTO kode_lowcode_pages (project_id, page_id, title, status, sort, created_by)
                 VALUES (?, ?, ?, 1, ?, ?)",
                [$projectId, self::PROBE . "-p{$i}", self::PROBE . " 页面{$i}", $i, self::PROBE]
            );
        }

        $first = $this->call('/api/builder/projects/' . \app\support\HashId::enc($projectId) . '/pages', 'pagesIndex', ['page' => 1, 'size' => 2], $projectId);
        $second = $this->call('/api/builder/projects/' . \app\support\HashId::enc($projectId) . '/pages', 'pagesIndex', ['page' => 2, 'size' => 2], $projectId);

        self::assertSame(3, $first['body']['total'] ?? null);
        self::assertSame(2, count($first['body']['data'] ?? []));
        self::assertSame(1, count($second['body']['data'] ?? []));
        self::assertSame(
            [],
            array_intersect(array_column($first['body']['data'], 'page_id'), array_column($second['body']['data'], 'page_id')),
            '页面列表两页同批：无界查询收成了无界分页'
        );
    }

    public function test_the_build_history_is_paged_and_reports_its_total(): void
    {
        $projectId = $this->seedProject();
        for ($i = 1; $i <= 3; $i++) {
            $this->jobIds[] = (int) Db::insert(
                "INSERT INTO kode_lowcode_build_jobs (project_id, version, status, created_by)
                 VALUES (?, ?, 'succeeded', ?)",
                [$projectId, '1.0.' . $i, self::PROBE]
            );
        }

        $res = $this->call('/api/builder/projects/' . \app\support\HashId::enc($projectId) . '/builds', 'buildsIndex', ['page' => 1, 'size' => 2], $projectId);

        self::assertSame(3, $res['body']['total'] ?? null, '构建历史只给了截断的 100 条而没有 total');
        self::assertSame(2, count($res['body']['data'] ?? []));
    }

    /* ==================== 工具 ==================== */

    private function seedProject(): int
    {
        $id = (int) Db::insert(
            "INSERT INTO kode_lowcode_projects (name, appid, edition, theme_key, status, created_by)
             VALUES (?, ?, 'standard', 'light-clean', 1, ?)",
            [self::PROBE . '-proj', self::PROBE . '-appid', self::PROBE]
        );
        $this->projectIds[] = $id;

        return $id;
    }

    private function seedLicenses(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->licenseIds[] = (int) Db::insert(
                "INSERT INTO kode_lowcode_licenses (license_id, customer_name, status, end_at)
                 VALUES (?, ?, ?, (CURRENT_DATE + INTERVAL '30 day')::date)",
                [
                    self::PROBE . '-' . $i,
                    self::PROBE . ' 客户' . $i,
                    $i === 2 ? 'expired' : 'active',
                ]
            );
        }
    }

    /** 上一条 INSERT 不在 seedLicenses 里，取回它自己的 id（按 license_id 精确认，不猜 MAX）。 */
    private function trackLastLicense(): void
    {
        $row = Db::select(
            'SELECT id FROM kode_lowcode_licenses WHERE license_id = ?',
            [self::PROBE . '-past']
        );
        self::assertNotSame([], $row, '刚插入的探针 license 查不到');
        $this->licenseIds[] = (int) $row[0]['id'];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function call(string $path, string $handler, array $query, ?int $routeId = null): array
    {
        $request = (new ServerRequest('GET', $path . '?' . http_build_query($query)))
            ->withQueryParams($query)
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => self::PROBE, 'roles' => ['admin']]);
        if ($routeId !== null) {
            // {id} 恒为对外 HashId（控制器走 eid() 解码），传裸自增 id 会被判成非法而 404。
            $request = $request->withAttribute('_route_params', ['id' => \app\support\HashId::enc($routeId)]);
        }
        HttpRequest::setRequest($request);

        $response = (new BuilderController())->{$handler}();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }
}
