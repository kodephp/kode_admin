<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Plugins\frontend\GiteeReleaseController;
use Psr\Http\Message\ResponseInterface;

/**
 * gitee 仓库插件剩下的两个列表入口（AGENTS.md §4「列表入口 = pageWindow() + 一条 COUNT(*) +
 * `$this->page()`，没有第二种形状」）。
 *
 * `definitionsIndex` 那类缺陷在这里是更直接的一支：两条 SQL 一个 `LIMIT` 都没有 ——
 *  - `reposIndex` 把 `kode_gitee_releases` 整张表一次读进内存，并且**逐行**再发一枚
 *   `SELECT token FROM kode_gitee_releases WHERE id = ?` 只为判「有没有配令牌」：
 *   N 行 = 1 + N 条查询，而这一列本可以跟着主查询一起回来（列表页每 5 秒不动一次也不会自己变多，
 *   但仓库数从来不是恒定的）；
 *  - `downloadsList` 读的是 `kode_gitee_downloads` —— 每次安装落一行，只增不减的历史表，
 *   没有窗口就意味着响应体随安装次数线性长大，而 `total` 又回的是「这一批有几条」，
 *   与 §4 里 `pagesIndex`「一次请求出多少行全看库里有多少」是同一件事。
 *
 * 这里钉三件事：窗口真的进 OFFSET、`total` 由同一份条件的 `COUNT(*)` 算、
 * 以及凭证那一列**永不出现在响应里**（掩码口径的同一条线）且列表不再逐行查它。
 */
final class GiteeListWindowTest extends TestCase
{
    protected bool $independentApp = true;

    private const PROBE = 'zz-gitelist';

    /** @var list<int> */
    private array $repoIds = [];

    /** @var list<int> */
    private array $downloadIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->downloadIds as $id) {
            Db::delete('DELETE FROM kode_gitee_downloads WHERE id = ?', [$id]);
        }
        foreach ($this->repoIds as $id) {
            Db::delete('DELETE FROM kode_gitee_releases WHERE id = ?', [$id]);
        }

        HttpRequest::clear();

        parent::tearDown();
    }

    /* ==================== repos ==================== */

    public function test_the_repo_list_honours_the_page_window(): void
    {
        $this->seedRepos(3);
        // 期望值现查，不写死 3：库里本来就可能有别的账号配过仓库，
        // 写死会让这条用例在别人的库上假红（而「total 数的是全集还是这一页」才是它要钉的）。
        $all = $this->repoCount();

        $first = $this->call('/api/gitee-release/repos', 'reposIndex', ['page' => 1, 'size' => 2]);
        $second = $this->call('/api/gitee-release/repos', 'reposIndex', ['page' => 2, 'size' => 2]);

        self::assertSame(200, $first['status']);
        self::assertSame($all, $first['body']['total'] ?? null, 'total 没回或不是全集：分页条算不出页数');
        self::assertGreaterThanOrEqual(3, $all, '种子行没落库，这条用例的前提没建立');
        self::assertSame(2, count($first['body']['data'] ?? []));
        self::assertSame(2, $first['body']['size'] ?? null);
        self::assertSame(2, $second['body']['page'] ?? null, 'page 回显不对：分页条会停在第 1 页');
        self::assertSame(
            min(2, $all - 2),
            count($second['body']['data'] ?? []),
            '第 2 页的行数与窗口不符'
        );

        $ids1 = $this->plainIds($first['body']['data'] ?? []);
        $ids2 = $this->plainIds($second['body']['data'] ?? []);
        self::assertSame(
            [],
            array_intersect($ids1, $ids2),
            '两页返回了同一批行：page/size 仍然没进 OFFSET'
        );
        // 翻页方向也要说得上话：`ORDER BY id DESC` 时第 1 页的每一行都必须比第 2 页的新。
        self::assertLessThan(
            min($ids1),
            max($ids2),
            '第 2 页混进了比第 1 页更新的行：OFFSET 与排序没对上'
        );
    }

    /** 令牌那一列既不逐行查、也永不外发；`has_token` 仍然分得清有没有配。 */
    public function test_the_repo_list_reports_has_token_without_leaking_it(): void
    {
        $this->seedRepo('with-token', 'zz-secret-value');
        $this->seedRepo('no-token', '');
        // 字面量 "0" 也是配了凭证：`(bool) '0'` 为 false，旧写法会把这个仓库报成没配。
        $this->seedRepo('zero-token', '0');

        $res = $this->call('/api/gitee-release/repos', 'reposIndex', ['page' => 1, 'size' => 100]);

        $rows = [];
        foreach ($res['body']['data'] ?? [] as $row) {
            $rows[$row['repo']] = $row;
        }

        self::assertTrue($rows[self::PROBE . '-with-token']['has_token'] ?? null, '配了令牌却被报成没配');
        self::assertTrue($rows[self::PROBE . '-zero-token']['has_token'] ?? null, '令牌是 "0" 时被报成没配');
        self::assertFalse($rows[self::PROBE . '-no-token']['has_token'] ?? true, '没配令牌却被报成配了');
        self::assertArrayNotHasKey(
            'token',
            $rows[self::PROBE . '-with-token'],
            '明文凭证进了响应体（掩码口径的另一半）'
        );
        self::assertStringNotContainsString(
            'zz-secret-value',
            (string) json_encode($res['body']),
            '整份响应里回显出了令牌原文'
        );

        // 列表不许再「每行查一次凭证」：N 行 = 1 + N 条 SQL，而主查询把这一列一起带上就行。
        $body = $this->handlerBody('reposIndex');
        self::assertStringNotContainsString(
            'repoToken(',
            $body,
            'reposIndex 仍在逐行调用 repoToken()：列表每多一行就多一条查询'
        );
    }

    /* ==================== downloads ==================== */

    public function test_the_download_history_is_paged_and_reports_its_total(): void
    {
        $repoId = $this->seedRepo('dl', '');
        $this->seedDownloads($repoId, 3);
        // 另一个仓库也要有流水：否则「COUNT 带不带 repo_id 条件」这两种写法回同一个数，
        // 下面那条 total 断言就成了自证（只钉住了「不是本页行数」这一半）。
        $otherId = $this->seedRepo('dl-other', '');
        $this->seedDownloads($otherId, 2);

        $first = $this->call(
            '/api/gitee-release/repos/' . \app\support\HashId::enc($repoId) . '/downloads',
            'downloadsList',
            ['page' => 1, 'size' => 2],
            $repoId
        );
        $second = $this->call(
            '/api/gitee-release/repos/' . \app\support\HashId::enc($repoId) . '/downloads',
            'downloadsList',
            ['page' => 2, 'size' => 2],
            $repoId
        );

        self::assertSame(200, $first['status']);
        self::assertSame(3, $first['body']['total'] ?? null, '历史只回截断的一批而没有全量 total');
        self::assertSame(2, count($first['body']['data'] ?? []));
        self::assertSame(1, count($second['body']['data'] ?? []));
        foreach ($first['body']['data'] as $row) {
            self::assertSame(
                \app\support\HashId::enc($repoId),
                $row['repo_id'] ?? null,
                '别人的下载流水混进了本仓库的历史：COUNT 与行查询不再同一份条件'
            );
        }
        self::assertSame(
            [],
            array_intersect(
                array_column($first['body']['data'], 'id'),
                array_column($second['body']['data'], 'id')
            ),
            '下载历史两页同批：无界查询收成了无界分页'
        );
    }

    /**
     * 越界的 page 必须由 pageWindow() 的 error 转成 400。
     *
     * 摘掉那道 `if ($win['error'] !== null) return fail` 的变异体实测回的是 **200 + 空列表**
     * （pageWindow() 出错时 offset 给 0，SQL 照常跑）—— 也就是说少这道闸门不是「打成 500」，
     * 而是把「翻页翻过头了」渲染成「这台系统再没有别的仓库了」。
     */
    public function test_an_absurd_page_is_refused_instead_of_executed(): void
    {
        $this->seedRepos(1);

        $res = $this->call('/api/gitee-release/repos', 'reposIndex', ['page' => 9223372036854775807, 'size' => 20]);

        self::assertSame(400, $res['status'], '深翻页没有过 PageWindow 的闸门');
    }

    /* ==================== 工具 ==================== */

    /**
     * 出参的 id 是对外 HashId（§4），比较翻页方向要先解回整数。
     * 解不回整数就原样落 0 —— 那会让下面的大小断言变红，而不是悄悄放行。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function plainIds(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $raw = (string) ($row['id'] ?? '');
            $out[] = ctype_digit($raw) ? (int) $raw : (\app\support\HashId::dec($raw) ?? 0);
        }

        return $out;
    }

    private function repoCount(): int
    {
        $rows = Db::select('SELECT COUNT(*) AS c FROM kode_gitee_releases');

        return (int) ($rows[0]['c'] ?? 0);
    }

    private function seedRepo(string $suffix, string $token): int
    {
        $id = (int) Db::insert(
            "INSERT INTO kode_gitee_releases (repo, token, name, created_by)
             VALUES (?, ?, ?, ?)",
            [self::PROBE . '-' . $suffix, $token, self::PROBE . ' 仓库', self::PROBE]
        );
        $this->repoIds[] = $id;

        return $id;
    }

    private function seedRepos(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->seedRepo('r' . $i, '');
        }
    }

    private function seedDownloads(int $repoId, int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->downloadIds[] = (int) Db::insert(
                "INSERT INTO kode_gitee_downloads (repo_id, version, asset_name, status)
                 VALUES (?, ?, ?, 'succeeded')",
                [$repoId, '1.0.' . $i, self::PROBE . "-asset-{$i}.tar.gz"]
            );
        }
    }

    private function handlerBody(string $method): string
    {
        $file = (new \ReflectionClass(GiteeReleaseController::class))->getFileName();
        self::assertIsString($file, '定位不到 GiteeReleaseController 的源文件');
        $src = (string) file_get_contents($file);

        // 先剥注释：文档里那句「此前是逐行 repoToken()」是说明文字，不是实现命中，
        // 否则门禁只会逼人删掉解释。
        $clean = '';
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $clean .= "\n";
                continue;
            }
            $clean .= is_array($tok) ? $tok[1] : $tok;
        }

        self::assertSame(
            1,
            preg_match('/function\s+' . $method . '\s*\(/', $clean, $m, PREG_OFFSET_CAPTURE),
            "源文件里找不到 {$method}()，这条静态断言就没有射程"
        );

        $open = strpos($clean, '{', $m[0][1]);
        self::assertIsInt($open, "{$method}() 的方法体开头没找到");

        $depth = 0;
        for ($i = $open, $len = strlen($clean); $i < $len; $i++) {
            if ($clean[$i] === '{') {
                $depth++;
            } elseif ($clean[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($clean, $open, $i - $open + 1);
                }
            }
        }

        self::fail("{$method}() 的方法体没有闭合，括号配对读不出边界");
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

        $response = (new GiteeReleaseController())->{$handler}();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }
}
