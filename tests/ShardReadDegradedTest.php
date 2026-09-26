<?php

declare(strict_types=1);

namespace app\tests;

use app\support\Shard;
use app\tests\Support\ShardSandbox;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Plugins\notices\NoticeController;

/**
 * 跨片读取的两个静默失败面（AGENTS.md §3「分片按需开通」+ §5 诊断面口径）。
 *
 * 1) **未注册的连接会被当成主库用**。`Db::connection('shard_02')` 对未知名字不抛错，
 *    而是回落全局配置（kode/database `Db::getConfig()`：`self::$connections[$name] ?? self::$config`），
 *    于是拿着分片表名去**主库**里查。主库里恰恰残留过同名表（`kode_app.kode_notices_00/_01`
 *    就是历史上「未注册分支在主库建片」留下的），所以这不是「读不到」而是「读到别处的数据」，
 *    还会把 total 乘重复。触发条件生产可达：热配置（kode_sys_configs 的 `shard` 行）说 db_count=4，
 *    而 `app/bootstrap.php` 按 `config/sharding.php`（= `SHARD_DB_COUNT` env，默认 2）注册连接
 *    —— 保存拓扑后**重启/回收**的 worker 只有前两条连接，且这个分岔不会自己收敛。
 *    写侧早就挡了（`Shard::route()` → `assertRegistered()`），读侧三处循环没挡。
 *
 * 2) **读失败被吃成「这一片没有数据」**（`catch (\Throwable) { continue; }`）。
 *    缺片、不可达、错列都只让列表少一截，页面毫无察觉 —— `ShardController::config()`
 *    的注释里就写着这件事（「整片数据会从列表里静默消失」），但只补了诊断口径，没补读侧。
 *
 * 判据落在**响应里恒有的 `unreadable_shards`**：空数组 = 全片读到了；非空 = 上面的数字只是部分口径。
 */
final class ShardReadDegradedTest extends TestCase
{
    use ShardSandbox;

    protected bool $independentApp = true;

    private const TABLE = 'kode_notices';

    /** 本次写进主库残留片表的探针行 [db 连接名, 表名, id, created_by] */
    private ?array $probe = null;

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
     * 主用例：热配置比已注册连接多出一片时，列表既不许读到主库的残留行，
     * 也不许把这片当成「没有数据」。
     */
    public function test_a_shard_without_a_registered_connection_is_reported_not_read_from_the_main_db(): void
    {
        $extra = $this->firstUnregisteredShardIndex();
        if ($this->mainDbHasShardTables() === false) {
            $this->markTestSkipped(
                '主库里没有 ' . self::TABLE . '_00 残留片表：「未注册连接读到主库」这条链在本机无法复现，'
                . '需要一台历史上建过残留片的开发库（本用例是那条链的唯一射程）'
            );
        }
        self::assertNotNull(
            $extra,
            '64 个分片连接位全部已注册：构造不出「热配置比已注册连接多出一片」的现场，本用例需要一台正常机器'
        );

        $cfg = Shard::config();
        $this->forceTopology(true, (int) $extra + 1, (int) $cfg['table_shards']);

        // 先取一次「干净基线」（探针还没写），后面的对照都以它为准
        $before = $this->callIndex();
        self::assertSame(200, $before['status'], '列表基线请求就失败了：' . $before['msg']);

        $title = $this->seedMainDbProbe((int) $extra);

        $after = $this->callIndex();
        $json = (string) json_encode($after['body'], JSON_UNESCAPED_UNICODE);

        // 判据 1：主库残留片表里的那一行不属于任何一片，一个字都不许出现在响应里
        self::assertStringNotContainsString(
            $title,
            $json,
            '未注册的分片连接被当成主库用了：' . $after['body']['total'] . ' 这个 total 里含主库残留表的行'
        );
        self::assertSame(
            (int) $before['body']['total'],
            (int) $after['body']['total'],
            '主库里多一行就让 total 变了：读侧的落点与写侧路由的不是同一批库'
        );

        // 判据 2：那一片必须被点名，而不是被 continue 吃成「这一片没有数据」
        self::assertArrayHasKey(
            'unreadable_shards',
            $after['body'],
            '分页信封没有 unreadable_shards：读不出来的片在响应上与「真的没有数据」长得一样'
        );
        $tag = sprintf('shard_%02d', $extra) . '.' . self::TABLE . '_00';
        $listed = array_values(array_filter(
            (array) $after['body']['unreadable_shards'],
            static fn(array $s): bool => (string) ($s['shard'] ?? '') === $tag
        ));
        self::assertNotEmpty(
            $listed,
            '缺的那一片没被点名（期望 ' . $tag . '）：' . json_encode($after['body']['unreadable_shards'], JSON_UNESCAPED_UNICODE)
        );
        self::assertStringContainsString(
            '未注册',
            (string) ($listed[0]['reason'] ?? ''),
            '点了名但没说原因：排障方向会被带向「分片库故障」而不是「worker 拓扑没收敛」'
        );
    }

    /**
     * 对照：全片可读时 unreadable_shards 必须是空数组而不是缺键 ——
     * 「缺键」会让前端无法区分「没这个字段的老后端」与「一切正常」。
     */
    public function test_the_marker_is_present_and_empty_when_every_shard_was_read(): void
    {
        $cfg = Shard::config();
        $this->forceTopology(true, $this->registeredShardCount(), (int) $cfg['table_shards']);

        $res = $this->callIndex();
        self::assertSame(200, $res['status'], '正常拓扑下列表读不出：' . $res['msg']);
        self::assertArrayHasKey('unreadable_shards', $res['body']);
        self::assertSame(
            [],
            (array) $res['body']['unreadable_shards'],
            '全片可读却报了什么：派发口会把正常片也算成失败'
        );
    }

    /** 派发口本身：真正读不动的一片（表不存在）要带原因回来，且不能盖住正常片的结果。 */
    public function test_scan_reports_the_failed_unit_and_keeps_the_ones_that_worked(): void
    {
        $cfg = Shard::config();
        $conn = sprintf('shard_%02d', 0);
        if (!$cfg['enabled'] || !Db::hasConnection($conn)) {
            $this->markTestSkipped('本机没有可用的分片连接（' . $conn . '）：无法构造「一片可读、一片缺表」的现场');
        }

        $missing = self::TABLE . '_99';
        $res = Shard::scan(
            [[$conn, self::TABLE . '_00'], [$conn, $missing]],
            static fn(?string $c, string $t): int => (int) (Db::connection((string) $c)
                ->select("SELECT COUNT(*) AS c FROM {$t}")[0]['c'] ?? 0)
        );

        self::assertCount(1, $res['results'], '可读的那一片的结果没带回来：' . json_encode($res['results']));
        self::assertIsInt($res['results'][0], '派发口必须原样带回各片的返回值');
        self::assertSame(
            1,
            count($res['unreadable']),
            '缺表的一片没有被记下来：' . json_encode($res['unreadable'], JSON_UNESCAPED_UNICODE)
        );
        self::assertSame($conn . '.' . $missing, (string) ($res['unreadable'][0]['shard'] ?? ''));
        self::assertStringContainsString(
            '片表不存在',
            (string) ($res['unreadable'][0]['reason'] ?? ''),
            '原因口径不对（42P01 应归成「片表不存在」）：' . json_encode($res['unreadable'][0], JSON_UNESCAPED_UNICODE)
        );
        self::assertSame('42P01', (string) ($res['unreadable'][0]['code'] ?? ''), 'SQLSTATE 没带上：排障时要它定位是哪一类失败');
        // 只回结论口径，不回原文：PDO 报错串里带着分片库主机名（内网拓扑）
        self::assertStringNotContainsString(
            '127.0.0.1',
            (string) json_encode($res['unreadable'], JSON_UNESCAPED_UNICODE),
            '失败原因里出现了连接串细节：诊断面不允许把内网拓扑发给客户端'
        );
    }

    /** 只有一片真的读不动时，其余片的数据照常出（半套不是全废）。 */
    public function test_readable_shards_still_come_back_when_one_is_broken(): void
    {
        $cfg = Shard::config();
        if (!$cfg['enabled']) {
            $this->markTestSkipped('分片未开启（config/sharding.php 或热配置）：跨片归并分支没有射程');
        }
        $units = Shard::readUnits(self::TABLE, true);
        self::assertGreaterThanOrEqual(2, count($units), '片数不足 2，无法构造「一片坏、一片好」');

        $bad = $units[0];
        $res = Shard::scan(
            [$bad, $units[1]],
            static function (?string $c, string $t) use ($bad): array {
                if ($t === $bad[1]) {
                    throw new \RuntimeException('模拟该片不可达');
                }

                return [$t];
            }
        );

        self::assertSame([[$units[1][1]]], array_values($res['results']));
        self::assertSame(1, count($res['unreadable']));
        self::assertSame(
            '该片操作失败',
            (string) ($res['unreadable'][0]['reason'] ?? ''),
            '非 SQLSTATE 的失败要有通用口径，且不回显原始消息'
        );
    }

    // ─── 私有 ───────────────────────────────────────────────

    /** 主库里是否残留过片表（历史事故现场，本用例的前提）。 */
    private function mainDbHasShardTables(): bool
    {
        $table = self::TABLE . '_00';
        $rows = Db::select("SELECT to_regclass('public.{$table}') AS r");

        return ($rows[0]['r'] ?? null) !== null;
    }

    /** 往主库的残留片表里写一行（只有未注册分支会读到它）。 */
    private function seedMainDbProbe(int $shardIndex): string
    {
        $owner = 'zz_shard_read_' . bin2hex(random_bytes(6));
        $title = '主库残留片表探针 ' . $owner;
        $table = self::TABLE . '_00';
        $id = (int) Db::connection(Db::getDefaultConnection())->insert(
            'INSERT INTO ' . $table
            . " (title, body, type, status, created_by, created_at, updated_at) VALUES (?, ?, 'announcement', 1, ?, NOW(), NOW())",
            [$title, 'probe', $owner]
        );
        $this->probe = [Db::getDefaultConnection(), $table, $id, $owner];
        self::assertGreaterThan(0, $id, '探针行没插进主库的 ' . $table . '：判据 1 是空转');

        return $title;
    }

    /** @return array{status: int, msg: string, body: array<string, mixed>} */
    private function callIndex(): array
    {
        $request = new ServerRequest('GET', '/api/notices', [], ['content-type' => 'application/json'], '');
        HttpRequest::setRequest(
            $request->withAttribute('auth', (object) ['uid' => 1, 'roles' => ['admin']])
        );

        $response = (new NoticeController())->index();
        $decoded = (array) json_decode((string) $response->getBody(), true);

        return [
            'status' => (int) ($decoded['code'] ?? $response->getStatusCode()),
            'msg'    => (string) ($decoded['msg'] ?? ''),
            'body'   => $decoded,
        ];
    }

    private function dropProbe(): void
    {
        if ($this->probe === null) {
            return;
        }
        [$conn, $table, $id, $owner] = $this->probe;
        $this->probe = null;
        try {
            // 只删自己那一行（归属键是随机串，命中不了别人的数据）
            Db::connection($conn)->delete(
                'DELETE FROM ' . $table . ' WHERE id = ? AND created_by = ?',
                [$id, $owner]
            );
        } catch (\Throwable) {
            // 收尾删除失败不该盖住用例真正的失败原因
        }
    }

    /**
     * 未分片模式带同一个键（空数组）：出参形状不能随开关变，
     * 否则客户端要先判断拓扑再决定读不读这个字段。
     */
    public function test_single_table_mode_carries_the_same_marker(): void
    {
        $this->forceTopology(false, 1, 1);

        $res = $this->callIndex();
        self::assertSame(200, $res['status'], '单表模式列表读不出：' . $res['msg']);
        self::assertSame(
            [],
            (array) ($res['body']['unreadable_shards'] ?? null),
            '单表分支缺 unreadable_shards：形状随开关变了'
        );
    }

    /**
     * 收尾对照：不能把开发库留在伪造的拓扑上（下一批用例就会按错模数路由）。
     *
     * 判据在 ShardSandbox 里共用一份（基准 = 本文件第一次 captureShardBaseline() 读到的原文）。
     */
    public function test_the_topology_is_left_as_it_was(): void
    {
        $this->assertSandboxLeftClean();
    }
}
