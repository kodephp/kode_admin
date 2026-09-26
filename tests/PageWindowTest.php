<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\AuditController;
use app\admin\controllers\SyslogController;
use app\admin\controllers\UserController;
use app\concerns\PageWindow;
use Kode\Framework\Http\Controller;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Plugins\frontend\BuilderController;
use Kode\Plugins\notices\NoticeController;
use Kode\Plugins\wallet\WalletController;

/**
 * 分页窗口入参闸门（AGENTS.md §5「一次请求的开销必须有界」+ §4「越界一律拒掉」）。
 *
 * 修的是实测出来的 500：`?page=9223372036854775807` 经 `max(1, (int) $page)` 只会饱和成
 * `PHP_INT_MAX`，紧接着 `($page - 1) * $size` 溢出成 float `9.223372036854776E+20`，
 * 这个值再绑进 `LIMIT ? OFFSET ?`，pgsql 报
 * `SQLSTATE[22P02] 无效的类型 bigint 输入语法: "9.2233720368548E+20"` ——
 * 任何一枚有效 token 用一个查询参数就能把 16 个列表入口（app 12 + 插件 4）打成 5xx。
 * 另一半是成本：`OFFSET` 在 pgsql 里要真的扫弃那么多多余行，
 * 深度无界等于「一个数就让 DB 白干一次全表级扫描」（11 worker 的后台，点几下即锁死）。
 *
 * 所以判定收进 `PageWindow` 一份：`size` 的上限照旧是保护性夹取（它限的是本次返回行数），
 * 但**翻页深度**越界一律 400，并且 `offset` 只由这份判定算出来。
 *
 * 归并型入口（跨分片各取 `page*size` 行进 PHP 再排序切片）另有一份预算：它的代价不是
 * DB 侧扫弃的行数而是**进内存的行数 × 分片数**，沿用那个十万行深度等于放行
 * `100000 × 4 片 ≈ 354 MB`（真实审计表实测每行净占 ~885 B，worker `memory_limit` 128M）——
 * 又是一枚查询参数打崩 worker，只是这次崩在 PHP 而不是 pgsql。见 `mergedWindow()`。
 */
final class PageWindowTest extends TestCase
{
    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    /**
     * 溢出与无界两类样本：前者把 offset 乘成 float（直接 5xx），
     * 后者仍是合法 int，但 pgsql 要为它逐行数过去（一次请求的开销没有顶）。
     *
     * @return array<string, array{0: string}>
     */
    public static function overflowingPages(): array
    {
        return [
            'int max' => ['9223372036854775807'],
            'beyond int max' => ['99999999999999999999'],
            'int but unbounded depth' => ['1000000000'],
            'largest page still multiplied into an int' => ['9007199254740993'],
        ];
    }

    /** 原生 SQL 分支：`LIMIT ? OFFSET ?` 直接绑到 pgsql。 */
    #[\PHPUnit\Framework\Attributes\DataProvider('overflowingPages')]
    public function test_syslog_index_refuses_an_absurd_page_instead_of_erroring(string $page): void
    {
        $body = $this->call(new SyslogController(), 'index', ['page' => $page]);

        self::assertSame(400, (int) $body['code'], '溢出成 float 的 OFFSET 会被 pgsql 拒掉，绝不能走到那一步');
        self::assertStringContainsString('翻页', (string) $body['msg']);
    }

    /** Doctrine 分支：分页参数是交给 `setFirstResult()`，溢出同样致命。 */
    #[\PHPUnit\Framework\Attributes\DataProvider('overflowingPages')]
    public function test_user_index_refuses_an_absurd_page_instead_of_erroring(string $page): void
    {
        $body = $this->call(new UserController(), 'index', ['page' => $page]);

        self::assertSame(400, (int) $body['code']);
        self::assertStringContainsString('翻页', (string) $body['msg']);
    }

    /** 正常翻页不受影响：判定只管越界，不改可用区间。 */
    public function test_a_reasonable_page_still_returns_rows(): void
    {
        $body = $this->call(new SyslogController(), 'index', ['page' => '1', 'size' => '5']);

        self::assertSame(200, (int) $body['code']);
        self::assertSame(1, (int) $body['page']);
        self::assertSame(5, (int) $body['size']);
        self::assertLessThanOrEqual(5, count((array) $body['data']));
    }

    /**
     * 插件侧同样收口：钱包流水、公告（分片合并分支）、低代码项目列表。
     *
     * 三条各跑一种不同的翻页实现 —— 绑定 offset 的原生 SQL、跨片取回后 `array_slice`、
     * 服务层收 offset 的转发 —— 越界都必须在碰到 DB 之前挡住。
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('overflowingPages')]
    public function test_plugin_lists_refuse_an_absurd_page_too(string $page): void
    {
        $sites = [
            'wallet' => [new WalletController(), 'list'],
            'notices' => [new NoticeController(), 'index'],
            'builder' => [new BuilderController(), 'projectsIndex'],
        ];
        foreach ($sites as $name => [$controller, $action]) {
            $body = $this->call($controller, $action, ['page' => $page]);

            self::assertSame(400, (int) $body['code'], $name . ' 列表没挡住越界的 page');
            self::assertStringContainsString('翻页', (string) $body['msg'], $name);
        }
    }

    /** 判定本身：边界值、类型、以及「offset 恒为 int」这条硬口径。 */
    public function test_the_judgement_keeps_offset_an_integer_within_the_depth_ceiling(): void
    {
        $window = $this->window();

        $cases = [
            // [rawPage, rawSize, maxSize, 期望 page, 期望 size, 期望 offset, 是否应越界]
            [null, null, 100, 1, 20, 0, false],
            ['0', '-7', 100, 1, 1, 0, false],
            ['abc', 'xyz', 100, 1, 1, 0, false],
            ['3', '50', 100, 3, 50, 100, false],
            ['2', '99999', 100, 2, 100, 100, false],
            ['1', '99999', 200, 1, 200, 0, false],
            ['100001', '100', 100, 100001, 100, 0, true],
        ];
        foreach ($cases as [$rawPage, $rawSize, $maxSize, $page, $size, $offset, $overflows]) {
            $out = $window->check($rawPage, $rawSize, $maxSize);
            if ($overflows) {
                self::assertNotNull($out['error'], "page={$rawPage} 深度越界却没报错");
                continue;
            }
            self::assertNull($out['error'], '入参 ' . var_export($rawPage, true) . ' 本应放行');
            self::assertSame($page, $out['page']);
            self::assertSame($size, $out['size']);
            self::assertSame($offset, $out['offset']);
            self::assertIsInt($out['offset']);
        }
    }

    /** 允许的最深一页必须放行、再深一页必须出 error：上限是个真实存在的数，不是约等于。 */
    public function test_the_depth_ceiling_is_exact(): void
    {
        $window = $this->window();
        $depth = $window->depth();
        $size = 20;
        $lastAllowed = intdiv($depth, $size) + 1;

        $ok = $window->check((string) $lastAllowed, (string) $size, 100);
        self::assertNull($ok['error'], '刚好卡在深度上限上的那一页不该被拒');
        self::assertLessThanOrEqual($depth, $ok['offset'], 'offset 越过了行深上限：一次请求的扫描量没封顶');
        self::assertSame(intdiv($depth, $size) * $size, $ok['offset']);

        $over = $window->check((string) ($lastAllowed + 1), (string) $size, 100);
        self::assertNotNull($over['error'], '比上限深一页却没被拒：判定没吃到上限');
    }

    /** 归并型预算：摊薄到分片数上，且 `shards <= 1` 时退回 SQL 侧那个深度。 */
    public function test_the_merged_budget_divides_by_shard_count_and_no_further(): void
    {
        $window = $this->window();
        $budget = $window->mergedBudget();
        $size = 20;

        foreach ([2, 4, 16] as $shards) {
            $perShard = intdiv($budget, $shards);
            $deepest = intdiv($perShard, $size) + 1;

            $ok = $window->merged((string) $deepest, (string) $size, $shards);
            self::assertNull($ok['error'], "shards={$shards} 卡在预算上的那一页不该被拒");
            // 进 PHP 的行数 = page*size × 分片数，这才是内存账本上的那个数（含末页多取的那几条）。
            self::assertLessThanOrEqual(
                $budget + $shards * $size,
                ($ok['offset'] + $size) * $shards,
                "shards={$shards}：归并后的行数越过了内存预算"
            );
            // 判据真的按片数摊薄了（不是拿一个常数糊过去）：每页取行数贴着 预算/片数。
            self::assertLessThanOrEqual(
                $size,
                abs($ok['offset'] + $size - $perShard),
                "shards={$shards}：每片取行数与摊薄后的预算不符"
            );

            $over = $window->merged((string) ($deepest + 1), (string) $size, $shards);
            self::assertNotNull($over['error'], "shards={$shards} 比预算深一页却没被拒");
            self::assertStringContainsString('行预算', (string) $over['error']);
        }

        // 单片不是归并：代价在 DB 侧的 OFFSET 扫描，不该被内存预算误伤。
        $sqlDepth = intdiv($window->depth(), $size) + 1;
        self::assertNull(
            $window->merged((string) $sqlDepth, (string) $size, 1)['error'],
            'shards=1 被摊薄预算误伤：单表分页的天花板应是 SQL 侧那个深度'
        );
        self::assertNotSame(
            $window->check((string) $sqlDepth, (string) $size, 100)['error'],
            $window->merged((string) $sqlDepth, (string) $size, 4)['error'],
            'shards=4 没吃到摊薄后的预算：与 pageWindow 判据没分岔'
        );
    }

    /** 归并型入口真的吃到这份预算：越界先 400，且发生在任何查询之前。 */
    public function test_a_merge_paginated_endpoint_refuses_past_the_merged_ceiling(): void
    {
        $window = $this->window();
        $shards = max(1, \app\support\Shard::tableShards());
        $deepest = intdiv(intdiv($window->mergedBudget(), $shards), 20) + 1;

        $ok = $this->call(new AuditController(), 'index', ['page' => '1', 'size' => '20']);
        self::assertSame(200, (int) $ok['code'], '默认页连预算都过不了，断言就没意义了');

        $body = $this->call(new AuditController(), 'index', ['page' => (string) ($deepest + 1)]);
        self::assertSame(400, (int) $body['code'], '归并入口没吃到摊薄后的预算');
        self::assertStringContainsString('翻页', (string) $body['msg']);
    }

    /** 暴露 protected 判定，用于纯逻辑断言。 */
    private function window(): object
    {
        return new class extends Controller {
            use PageWindow;

            /** @return array{page: int, size: int, offset: int, error: string|null} */
            public function check(mixed $rawPage, mixed $rawSize, int $maxSize = 100): array
            {
                return $this->pageWindow($rawPage, $rawSize, $maxSize);
            }

            /** @return array{page: int, size: int, offset: int, error: string|null} */
            public function merged(mixed $rawPage, mixed $rawSize, int $shards): array
            {
                return $this->mergedWindow($rawPage, $rawSize, $shards);
            }

            /** trait 的常量不能从外部直接取，用它把真实上限交给断言（写死数字的测试会假绿）。 */
            public function depth(): int
            {
                return self::MAX_ROW_DEPTH;
            }

            public function mergedBudget(): int
            {
                return self::MAX_MERGED_ROWS;
            }
        };
    }

    /**
     * 直接调控制器方法（绕开中间件），拿归一化后的信封。
     *
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function call(object $controller, string $action, array $query): array
    {
        HttpRequest::setRequest(
            (new ServerRequest('GET', '/api/any?' . http_build_query($query)))->withQueryParams($query)
        );

        $response = $controller->{$action}();

        return (array) json_decode((string) $response->getBody(), true);
    }
}
