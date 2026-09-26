<?php

declare(strict_types=1);

namespace app\tests;

use app\support\AuditTrail;
use app\support\Shard;
use app\tests\Support\ShardSandbox;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 分片总览面板（`GET /api/monitor/shards`）的口径。
 *
 * 这张面板是 AGENTS.md 里点名给运维排障用的（`Shard::unreadableKey()` 的尾巴就写着
 * 「逐片连通性见 GET /api/shard/config」这一类指引），所以它出错的时候恰恰是最要命的：
 * 修之前它同时带着三处漂移 ——
 * 1. 拓扑数字读 `config('sharding')`、片清单读 `Shard::tables()`（走 DB 热配置），
 *    热配置扩过片之后同一屏会写「表分片数 2」而列出 4 张表；
 * 2. 逐片 `COUNT(*)` 只打默认连接，跨库基表（notices）的 total 少几个库；
 * 3. 每张表一个空 `catch (\Throwable) {}`，缺片只让 `rows` 出 null，
 *    `total` 照旧回 200 —— 结构错误被渲染成「这一片没数据」。
 *
 * 本文件按这三条各锁一个不变式，并且一条都不靠读源码：全部对着响应里的数字算。
 * 全程不建表、不写业务数据（只读 COUNT），拓扑用 `ShardSandbox` 的口径拨并收尾还原。
 */
final class MonitorShardTotalsTest extends TestCase
{
    use ShardSandbox;

    protected bool $independentApp = true;

    private const AUDIT = AuditTrail::BASE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));

        $this->captureShardBaseline();
    }

    protected function tearDown(): void
    {
        $this->restoreShardConfig();

        HttpRequest::clear();

        parent::tearDown();
    }

    /**
     * 面板报的模数必须就是它自己列出来的片数。
     *
     * 这是第 1 条漂移的判据：旧写法读静态配置、循环读热配置，两者一旦分叉，
     * 「表分片数」和表格行数就会在同一屏里互相打脸。
     */
    public function test_the_reported_modulus_matches_the_tables_listed(): void
    {
        $live = $this->presentShardIndexes(self::AUDIT);
        self::assertNotEmpty($live, '主库里一张 ' . self::AUDIT . '_NN 都没有：先跑迁移再跑本文件');
        $cfg = Shard::config();
        $this->forceTopology(true, (int) $cfg['db_count'], count($live));

        $body = $this->callShards();
        $audit = $this->base($body, self::AUDIT);

        self::assertSame(
            Shard::tableShards(),
            (int) $body['data']['topology']['table_shards'],
            'topology 里的模数不是 Shard::config() 的口径：静态配置与 DB 热配置又会各说各话'
        );
        self::assertSame(
            Shard::tableShards(),
            count($audit['tables']),
            '报的模数与列出的片表数不一致：面板在同一屏里自相矛盾'
        );
    }

    /** total 必须等于逐片行数之和，也等于按同一批片表独立 COUNT 出来的数。 */
    public function test_the_total_is_the_sum_of_the_legs_it_listed(): void
    {
        $live = $this->presentShardIndexes(self::AUDIT);
        $cfg = Shard::config();
        $this->forceTopology(true, (int) $cfg['db_count'], count($live));

        $body = $this->callShards();
        $audit = $this->base($body, self::AUDIT);

        $listed = 0;
        foreach ((array) $audit['tables'] as $piece) {
            self::assertIsInt($piece['rows'], '片表行数出了 null：读不出来的片被当成「这一片没有数据」（' . json_encode($piece) . '）');
            // 标签与数字必须来自同一片：各片行数天然不等（本机 _00/_01/_02/_03 差异很大），
            // 所以「按名字独立数一遍再比」能钉住错位 —— 展示侧一旦用结果下标回查片清单，
            // 少一条腿就会让后面每条结果整体错一格，把 A 片的行数挂到 B 片名下。
            self::assertSame(
                (int) (Db::select('SELECT COUNT(*) AS c FROM ' . (string) $piece['table'])[0]['c'] ?? 0),
                (int) $piece['rows'],
                '审计各片的行数与它标的表名对不上：' . json_encode($piece, JSON_UNESCAPED_UNICODE)
            );
            $listed += (int) $piece['rows'];
        }
        self::assertSame($listed, (int) $audit['total'], 'total 不等于各片之和：聚合漏了腿');

        // 独立对照：按同一批表名自己数一遍，绕开面板的聚合代码
        $expected = 0;
        foreach ($live as $i) {
            $expected += (int) (Db::select('SELECT COUNT(*) AS c FROM ' . sprintf('%s_%02d', self::AUDIT, $i))[0]['c'] ?? 0);
        }
        self::assertSame($expected, (int) $audit['total'], '面板的 total 与库里真实行数不符');

        self::assertTrue((bool) ($audit['complete'] ?? false), '全片可读却标了不完整：' . json_encode($audit['unreadable_shards'] ?? null));
        self::assertSame([], (array) ($audit['unreadable_shards'] ?? ['__missing__' => 1]), '正常拓扑下这个键消失了：形状随拓扑变了');
    }

    /**
     * 缺一片时：那一片被点名、缺口被标明，而读到的那些片照常带回。
     *
     * 第 3 条漂移的正向对照 —— 半套数字必须是**自报半套**的半套。
     */
    public function test_a_leg_it_could_not_count_is_named_instead_of_shrinking_the_total(): void
    {
        $live = $this->presentShardIndexes(self::AUDIT);
        self::assertNotEmpty($live, '主库里一张 ' . self::AUDIT . '_NN 都没有：构造不出缺片现场');
        self::assertSame(range(0, max($live)), $live, '片表序号不连续：本用例的「恰好多一片」判据不成立');

        $cfg = Shard::config();
        $shards = count($live) + 1;
        $this->forceTopology(true, (int) $cfg['db_count'], $shards);
        $missing = sprintf('%s_%02d', self::AUDIT, $shards - 1);

        $audit = $this->base($this->callShards(), self::AUDIT);

        self::assertFalse((bool) ($audit['complete'] ?? true), '缺一片还回 complete:true：这份 total 会被读成全口径');
        $tags = array_map(static fn(array $s): string => (string) ($s['shard'] ?? ''), (array) $audit['unreadable_shards']);
        self::assertContains('default.' . $missing, $tags, '缺的那一片没被点名：' . json_encode($tags, JSON_UNESCAPED_UNICODE));

        $reasons = array_map(static fn(array $s): string => (string) ($s['reason'] ?? ''), (array) $audit['unreadable_shards']);
        self::assertStringContainsString(
            '片表不存在',
            (string) ($reasons[array_search('default.' . $missing, $tags, true) ?: 0] ?? ''),
            '点了名却没说原因：' . json_encode($audit['unreadable_shards'], JSON_UNESCAPED_UNICODE)
        );

        // 读到的那些片不许因为缺一片就整块消失
        self::assertNotEmpty($audit['tables'], '缺一片就把表格清空了：面板本该显示剩下的那些片');
        $listed = 0;
        foreach ((array) $audit['tables'] as $piece) {
            self::assertIsInt($piece['rows'], '表格里有 rows 为 null 的行：失败片混进了清单而不是 unreadable_shards');
            $listed += (int) $piece['rows'];
        }
        self::assertSame($listed, (int) $audit['total'], 'total 与各片之和不符（缺片场景）');
    }

    /**
     * 跨库基表必须**每个库**都被扫到。
     *
     * 第 2 条漂移：旧写法只对默认连接 COUNT，于是 notices 的 total 与片清单少几个库，
     * 而页面上一切正常。这里按 readUnits 的清单核对「每条腿要么出数、要么被点名」。
     */
    public function test_a_cross_db_base_covers_every_library(): void
    {
        $live = $this->presentShardIndexes(self::AUDIT);
        $cfg = Shard::config();
        $this->forceTopology(true, (int) $cfg['db_count'], count($live));

        foreach (Shard::SHARDED_BASES as $base) {
            $expected = count(Shard::readUnits($base));
            $entry = $this->base($this->callShards(), $base);

            self::assertSame(
                $expected,
                count($entry['tables']) + count($entry['unreadable_shards']),
                $base . ' 的腿数对不上（期望 ' . $expected . ' 条）：跨库清单被截断，'
                . '而截掉的部分既没出数也没被点名 → ' . json_encode([
                    'tables' => array_column((array) $entry['tables'], 'table'),
                    'unreadable' => array_column((array) $entry['unreadable_shards'], 'shard'),
                ], JSON_UNESCAPED_UNICODE)
            );

            if ((bool) $entry['complete']) {
                // 全片可读时，同名片表必须按库分别出现（不是只在默认连接上数一遍）
                $conns = array_unique(array_column((array) $entry['tables'], 'conn'));
                self::assertGreaterThan(
                    1,
                    count($conns),
                    $base . ' 是跨库基表，行数却只来自一个连接：' . json_encode($conns)
                );
            }
        }
    }

    /** 收尾对照：不能把开发库留在伪造的拓扑上。 */
    public function test_the_topology_is_left_as_it_was(): void
    {
        $this->assertSandboxLeftClean();
    }

    // ─── 私有 ───────────────────────────────────────────────

    /** @return array<string, mixed> 解码后的信封 */
    private function callShards(): array
    {
        $request = (new ServerRequest('GET', '/api/monitor/shards'))
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => 'admin', 'roles' => ['admin']]);
        HttpRequest::setRequest($request);

        $response = (new \app\admin\controllers\MonitorController())->shards();
        $decoded = (array) json_decode((string) $response->getBody(), true);

        self::assertSame(200, (int) ($decoded['code'] ?? 0), '分片总览请求失败：' . json_encode($decoded, JSON_UNESCAPED_UNICODE));

        return $decoded;
    }

    /**
     * 取某张基表那一节。
     *
     * 找不到就是响应没带这张表 —— 直接 fail，别让调用方去读 null 然后断言出一个假绿。
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function base(array $body, string $name): array
    {
        $bases = (array) ($body['data']['bases'] ?? []);
        foreach ($bases as $entry) {
            if ((string) ($entry['base'] ?? '') === $name) {
                self::assertIsArray($entry['tables'] ?? null, $name . ' 一节没有 tables 清单');
                self::assertIsArray($entry['unreadable_shards'] ?? null, $name . ' 一节没有 unreadable_shards：缺口在响应里不存在');

                return (array) $entry;
            }
        }

        self::fail('响应里没有 ' . $name . ' 这一节（基表清单与 Shard::SHARDED_BASES + 审计基表不符）：'
            . json_encode(array_column($bases, 'base')));
    }
}
