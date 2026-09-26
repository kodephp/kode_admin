<?php

declare(strict_types=1);

namespace app\tests;

use app\support\AccountScope;
use app\support\AuditTrail;
use app\support\Shard;
use app\tests\Support\ShardSandbox;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 审计读侧的缺片口径（AGENTS.md §3「跨片聚合的缺片必须点名」）。
 *
 * `kode_audit_logs` 与 notices 不同：它**只在默认连接上按表分片**（写侧 `AuditTrail::record()`
 * 用 `Db::`，读侧枚举的是主库里的 `_00.._NN`），所以「配置里的模数比库里的片多」是这条路
 * 独有的坏法 —— 常见于改过 `table_shards` 却没补迁移，或开发库被别的分支建过更大模数。
 *
 * 修之前的形状是每片一个 `catch (\Throwable) { continue; }`：缺的那一片只让列表少一截、
 * `total` 偏小，页面与「这台系统没人操作」长得一模一样；`clean()` 更糟 —— 一张表没删动
 * 也回 `deleted: <成功那几张的行数>` 加 200。现在五个读入口（index/mine/stats/export/overview）
 * 与清理口共用一个派发口：读不出来的片必须带原因回来。
 *
 * 探针行一律走生产写入口（`AuditTrail::record()`）落进**真实存在**的那一片，
 * 收尾按 id + username 删自己那一行；全程不建表、不碰分片库（审计的片表本来就不在分片库里）。
 */
final class AuditShardReadTest extends TestCase
{
    use ShardSandbox;

    protected bool $independentApp = true;

    private const BASE = AuditTrail::BASE;

    /** @var array{0: string, 1: int, 2: string}|null 探针行 [落点表名, id, username] */
    private ?array $probe = null;

    /** 探针行的 path：列表/导出断言的对照值，由 seedProbeOnALiveLeg() 写好 */
    private string $probePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));

        $this->captureShardBaseline();
    }

    protected function tearDown(): void
    {
        $this->dropProbe();
        $this->restoreShardConfig();

        HttpRequest::clear();

        parent::tearDown();
    }

    /**
     * 列表：配置里多出一片、库里没有时，缺的那一片必须被点名，
     * 同时**照常带回读得到的那些片**（半套不是全废）。
     */
    public function test_index_names_the_missing_leg_and_keeps_the_readable_rows(): void
    {
        $missing = $this->forceOneMissingLeg();
        $owner = $this->seedProbeOnALiveLeg($missing);

        // 按 username 过滤后应当只剩探针那一行
        $list = $this->call('index', ['username' => $owner]);
        self::assertSame(200, $list['status'], '列表请求就失败了：' . $list['msg']);
        self::assertSame(1, (int) $list['body']['total'], '读得到的那些片没把行带回来：' . json_encode($list['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(
            $this->probePath,
            (string) ($list['body']['data'][0]['path'] ?? ''),
            'total 对得上、回的不是那一行：归并/排序口径变了'
        );
        $this->assertNamesTheMissingLeg((array) ($list['body']['unreadable_shards'] ?? []), $missing, 'index');
    }

    /** 我的操作记录：同一个 username 的载荷，走的是另一条 SQL，缺口口径不许跟着分叉。 */
    public function test_mine_names_the_missing_leg(): void
    {
        $missing = $this->forceOneMissingLeg();
        $owner = $this->seedProbeOnALiveLeg($missing);

        $mine = $this->call('mine', ['username' => $owner], $owner);
        self::assertSame(200, $mine['status'], 'mine 请求失败：' . $mine['msg']);
        self::assertSame(1, (int) $mine['body']['total'], 'mine 没出自己的那行：' . json_encode($mine['body'], JSON_UNESCAPED_UNICODE));
        $this->assertNamesTheMissingLeg((array) ($mine['body']['unreadable_shards'] ?? []), $missing, 'mine');
    }

    /** 趋势图：data 形状（7 天列表）不许因缺片而变，缺口走信封同级的键。 */
    public function test_stats_names_the_missing_leg_without_bending_the_data_shape(): void
    {
        $missing = $this->forceOneMissingLeg();
        $owner = $this->seedProbeOnALiveLeg($missing);

        $stats = $this->call('stats', [], $owner);
        self::assertSame(200, $stats['status'], 'stats 请求失败：' . $stats['msg']);
        self::assertCount(7, (array) $stats['body']['data'], 'stats 的 data 形状变了：前端两张图都按 7 天画');
        $this->assertNamesTheMissingLeg((array) ($stats['body']['unreadable_shards'] ?? []), $missing, 'stats');
    }

    /** CSV 导出：状态码改不了（头已发），半套产物必须在正文里自己说明。 */
    public function test_export_writes_the_missing_leg_into_the_csv(): void
    {
        $missing = $this->forceOneMissingLeg();
        $owner = $this->seedProbeOnALiveLeg($missing);

        $csv = $this->exportCsv(['username' => $owner], $owner);
        self::assertStringContainsString(
            $missing['table'],
            $csv,
            '导出的 CSV 里没有缺片说明：这份少了行的产物和一份完整的长得一样'
        );
        self::assertStringContainsString('片表不存在', $csv, 'CSV 的尾巴没写失败原因：' . substr($csv, -220));
        self::assertStringContainsString($owner, $csv, '读得到的那些片没进 CSV');
    }

    /**
     * 总览走的是另一条腿：聚合是 `UNION ALL`，一条腿指向不存在的表会让整条 SQL 报错，
     * 所以它先按目录核对，缺片进 `missing_tables`（不是 unreadable_shards）。
     */
    public function test_overview_reports_the_missing_leg_as_a_table_and_keeps_aggregating(): void
    {
        $missing = $this->forceOneMissingLeg();
        $this->seedProbeOnALiveLeg($missing);

        $res = $this->call('overview', [], null);
        self::assertSame(200, $res['status'], '总览在缺片时整个失败：' . $res['msg']);
        // 总览的缺片口径在 data 里（`missing_tables`：它是**建表口径**的问题，走 pg_class 预检，
        // 不进 unreadable_shards 那条**派发口径**）；两个键各自的落点在此都锁住形状。
        self::assertContains(
            $missing['table'],
            (array) ($res['body']['data']['missing_tables'] ?? []),
            '缺的那一片没进 missing_tables：' . json_encode($res['body']['data']['missing_tables'] ?? null, JSON_UNESCAPED_UNICODE)
        );
        self::assertGreaterThan(
            0,
            (int) ($res['body']['data']['total_7d'] ?? 0),
            '总览把缺片渲染成了「这台系统七天没人操作」：present 的那些片明明有行'
        );
    }

    /**
     * 清理口：一张表没删动就不能回「成功 + deleted」。
     *
     * days 取上限 3650（库里最老一行是 2026-09，删不到任何历史数据），
     * 这条用例要测的是**失败口径**，不是删除本身。
     */
    public function test_clean_refuses_to_report_a_partial_delete_as_success(): void
    {
        $missing = $this->forceOneMissingLeg();
        $owner = $this->seedProbeOnALiveLeg($missing);

        $res = $this->call('clean', ['days' => '3650'], $owner);
        self::assertSame(500, $res['status'], '缺一张表还回 200：' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString($missing['table'], (string) $res['msg'], '没点名是哪张表：' . $res['msg']);
        self::assertStringNotContainsString('"deleted"', (string) json_encode($res['body']), '失败响应里还带 deleted：读的人会以为那是全站口径');
        self::assertNotEmpty($this->probeRow(), '清理把没到保留期的行也删了：days 的口径变了');
    }

    /**
     * 对照：模数与库一致时 unreadable_shards 必须是**空数组**而不是缺键，
     * 否则前端分不出「老后端没这个字段」与「一切正常」。
     */
    public function test_a_matching_modulus_reports_no_missing_leg(): void
    {
        $live = $this->presentShardIndexes(self::BASE);
        self::assertNotEmpty($live, '主库里一张 ' . self::BASE . '_NN 都没有：先跑迁移再跑本文件');
        $cfg = Shard::config();
        $this->forceTopology(true, (int) $cfg['db_count'], count($live));

        $res = $this->call('index');
        self::assertSame(200, $res['status'], '正常拓扑下列表读不出：' . $res['msg']);
        self::assertArrayHasKey('unreadable_shards', $res['body'], '正常拓扑下这个键消失了：形状随拓扑变了');
        self::assertSame([], (array) $res['body']['unreadable_shards'], '全片可读却报了缺口：' . json_encode($res['body']['unreadable_shards']));

        $over = $this->call('overview');
        self::assertSame(
            [],
            (array) ($over['body']['data']['missing_tables'] ?? ['__missing__' => 1]),
            '总览在正常情况下报了缺片：' . json_encode($over['body']['data']['missing_tables'] ?? null)
        );
    }

    /** 收尾对照：热配置与连接数都得回到本文件开始前。 */
    public function test_the_topology_is_left_as_it_was(): void
    {
        $this->assertSandboxLeftClean();
    }

    // ─── 私有 ───────────────────────────────────────────────

    /**
     * 把 table_shards 拨到「比库里真实片数多一片」，返回那一片的口径。
     *
     * @return array{table: string, index: int, live: int}
     */
    private function forceOneMissingLeg(): array
    {
        $live = $this->presentShardIndexes(self::BASE);
        self::assertNotEmpty($live, '主库里一张 ' . self::BASE . '_NN 都没有：本文件的缺片现场建不出来');
        self::assertSame(
            range(0, max($live)),
            $live,
            '片表序号不连续（历史模数留下的洞）：本用例的「恰好多一片」判据不成立，请先补齐片表'
        );

        $cfg = Shard::config();
        $shards = count($live) + 1;
        $this->forceTopology(true, (int) $cfg['db_count'], $shards);

        $index = $shards - 1;

        return ['table' => sprintf('%s_%02d', self::BASE, $index), 'index' => $index, 'live' => count($live)];
    }

    /**
     * 用生产写入口落一行探针，且**避开缺的那一片**（否则 INSERT 自己就先 42P01）。
     *
     * @param array{table: string, index: int, live: int} $missing
     */
    private function seedProbeOnALiveLeg(array $missing): string
    {
        $owner = 'zz_audit_leg_' . bin2hex(random_bytes(5));
        foreach (range(1, 24) as $k) {
            if (Shard::index((string) $k) === $missing['index']) {
                continue;
            }
            $table = AuditTrail::tableFor((string) $k, '127.0.0.1');
            self::assertStringNotContainsString(
                $missing['table'],
                $table,
                '写侧把探针落在了缺的那一片上：本用例的前提没了'
            );
            $id = AuditTrail::record((string) $k, $owner, 'GET', $this->freshProbePath(), 200, 3, '127.0.0.1');
            self::assertGreaterThan(0, $id, '探针行没写进去：' . $table);
            $this->probe = [$table, $id, $owner];

            return $owner;
        }

        self::fail('24 个候选归属键全被模数挡在缺片上：模数口径变了，请改本用例');
    }

    /** 探针行的 path：唯一、可被 `path LIKE` 命中，也是列表断言的对照值。 */
    private function freshProbePath(): string
    {
        $this->probePath = '/zz_audit_probe_' . bin2hex(random_bytes(4));

        return $this->probePath;
    }

    private function probeRow(): array
    {
        if ($this->probe === null) {
            return [];
        }
        [$table, $id, $owner] = $this->probe;

        return Db::select('SELECT id FROM ' . $table . ' WHERE id = ? AND username = ?', [$id, $owner]);
    }

    private function dropProbe(): void
    {
        if ($this->probe === null) {
            return;
        }
        [$table, $id, $owner] = $this->probe;
        $this->probe = null;
        try {
            // 只删自己那一行（id + username 双条件，命中不了别人的数据）
            Db::delete('DELETE FROM ' . $table . ' WHERE id = ? AND username = ?', [$id, $owner]);
        } catch (\Throwable) {
            // 收尾删除失败不该盖住用例真正的失败原因
        }
    }

    /**
     * 缺片口径的公共判据：那一片被点名、原因说得出、且只多这一片。
     *
     * @param array{table: string, index: int, live: int} $missing
     */
    private function assertNamesTheMissingLeg(array $unreadable, array $missing, string $entry): void
    {
        self::assertSame(
            1,
            count($unreadable),
            $entry . ' 的 unreadable_shards 不是「恰好缺那一片」：' . json_encode($unreadable, JSON_UNESCAPED_UNICODE)
        );
        $row = (array) $unreadable[0];
        self::assertSame(
            'default.' . $missing['table'],
            (string) ($row['shard'] ?? ''),
            $entry . ' 点名的片不对（审计只在默认连接上分片，标签前缀必须是 default.）'
        );
        self::assertStringContainsString(
            '片表不存在',
            (string) ($row['reason'] ?? ''),
            $entry . ' 没给出可执行的排障方向：' . json_encode($row, JSON_UNESCAPED_UNICODE)
        );
        self::assertSame('42P01', (string) ($row['code'] ?? ''), $entry . ' 没带 SQLSTATE');
    }

    /**
     * 直接驱动控制器（本机 admin 密码已改，拿不到真令牌；鉴权由路由侧 RequirePerm 负责）。
     *
     * @param array<string, string> $query
     * @return array{status: int, msg: string, body: array<string, mixed>}
     */
    private function call(string $handler, array $query = [], ?string $username = null): array
    {
        $request = (new ServerRequest('GET', '/api/audits/' . $handler))
            ->withQueryParams($query)
            ->withAttribute('auth', (object) [
                'uid'      => 1,
                'username' => $username ?? AccountScope::ADMIN_ROLE,
                'roles'    => [AccountScope::ADMIN_ROLE],
            ]);
        HttpRequest::setRequest($request);

        $response = (new \app\admin\controllers\AuditController())->{$handler}();
        $decoded = (array) json_decode((string) $response->getBody(), true);

        return [
            'status' => (int) ($decoded['code'] ?? $response->getStatusCode()),
            'msg'    => (string) ($decoded['msg'] ?? ''),
            'body'   => $decoded,
        ];
    }

    /** @param array<string, string> $query */
    private function exportCsv(array $query, ?string $username = null): string
    {
        $request = (new ServerRequest('GET', '/api/audits/export'))
            ->withQueryParams($query)
            ->withAttribute('auth', (object) [
                'uid'      => 1,
                'username' => $username ?? AccountScope::ADMIN_ROLE,
                'roles'    => [AccountScope::ADMIN_ROLE],
            ]);
        HttpRequest::setRequest($request);

        return (string) (new \app\admin\controllers\AuditController())->export()->getBody();
    }
}
