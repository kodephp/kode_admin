<?php

declare(strict_types=1);

namespace app\tests;

use app\support\Shard;
use app\tests\Support\ShardSandbox;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Request as HttpRequest;

/**
 * 分片表的列缺口必须有人回填（`Shard::ensureTables()` 顺带补齐 + `Shard::drift()` 如实汇报）。
 *
 * 为什么单独立一道闸：基表加列的三条路**都不碰已经存在的片表**——
 *  1. 迁移只跑主库（迁移内切连接会打断迁移事务，见 `2026_09_23_000041_notice_type` 的注释）；
 *  2. 插件 install() 只在「装插件」那一刻跑；
 *  3. 插件 upgrade() 只在「点升级」那一刻跑。
 * 于是「基表有 type、片表没有」是长期无人负责的空档。本机实测kode_app_00/01 的
 * 四张 kode_notices_* 至今少 `type`，而读侧聚合是 `catch (\Throwable) continue`：
 * 42703 被吞掉之后，整片数据从公告列表里静默消失、total 跟着偏小，页面上和「真的一条没有」
 * 长得一模一样（写入侧 store() 更直接，500）。
 *
 * 用例走自建的一次性基表（`zz_align_base`），因为「漂移」这个前提不能在开发库上现编：
 * 拿真实片表做断言的话，补齐过一次之后就永远测不到了。
 * 收尾把本文件留下的**每一张**临时表删掉（`ensureTables` 是库×表双维，写死三个名字会漏），
 * 并把分片热配置恢复原状（本机原本没有 `shard` 行 → 删掉）。
 */
final class ShardColumnAlignTest extends TestCase
{
    use ShardSandbox;

    protected bool $independentApp = true;

    private const BASE = 'zz_align_base';

    private const SHARD_TABLE = 'zz_align_base_00';

    /** 本机是否真的具备跑条件（分片开着 + shard_00 连接注册了） */
    private bool $runnable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));

        $this->captureShardBaseline();
        $this->enableSharding();
        $this->runnable = Shard::enabled() && Db::hasConnection('shard_00');
    }

    protected function tearDown(): void
    {
        $this->dropTempTables();
        $this->restoreShardConfig();

        HttpRequest::clear();

        parent::tearDown();
    }

    public function test_missing_columns_are_backfilled_from_the_base_table(): void
    {
        if (!$this->runnable) {
            $this->markTestSkipped(
                '本机分片未开启或 shard_00 连接未注册（' . (Shard::enabled() ? '连接缺失' : '开关为关')
                . '）：补齐要在真分片库里 ALTER，跑不出来'
            );
        }

        $this->createTempTables();

        // 前提：片表确实少列，而且缺口是**读得出来**的
        $gap = $this->driftForBase();
        self::assertNotSame([], $gap, '临时片表没测出列缺口：本用例的前提没了，判据要一起改');
        self::assertSame(
            ['type'],
            $gap[0]['missing'],
            '缺口内容与声明的不一致（多了/少了列）：说明判据读的基表或片表变了'
        );

        Shard::resetEnsured();
        Shard::ensureTables(self::BASE);

        $report = Shard::alignReport();
        self::assertSame(
            [['db' => 'shard_00', 'table' => self::SHARD_TABLE, 'added' => ['type']]],
            $report,
            'ensureTables 没汇报它补了什么列：诊断面就永远看不见这次修复'
        );
        self::assertSame([], $this->driftForBase(), '补过之后仍有缺口：补齐没落地');

        // 原有行必须还在，并且按基表默认值拿到新列的值（不是 NULL、不是报错）
        $kept = Db::connection('shard_00')->select(
            'SELECT id, name, type FROM ' . self::SHARD_TABLE . ' WHERE name = ?',
            ['zz-keep-me']
        );
        self::assertCount(1, $kept, '补齐把已有行弄丢了：ADD COLUMN 不该动数据');
        self::assertSame('announcement', (string) ($kept[0]['type'] ?? ''), '补出来的列没带基表的默认值');

        // 幂等：再来一次不该重复补、也不该报错
        Shard::resetEnsured();
        Shard::ensureTables(self::BASE);
        self::assertSame([], Shard::alignReport(), '第二次 ensureTables 又补了一遍：不幂等');
        self::assertSame([], $this->driftForBase(), '幂等复测后仍有缺口');
    }

    /**
     * 对照：读不到基表时一个字节都不许动。
     *
     * 补齐的判据来自**主库**的基表目录。主库没有这张表（拼错名、迁移没跑）时，
     * 判据是空集合，而「空」既可能是「基表零列」也可能是「读不到」——
     * 这时候去 ALTER 就是拿一份不存在的规范改真表。
     */
    public function test_an_unreadable_base_table_never_touches_shards(): void
    {
        if (!$this->runnable) {
            $this->markTestSkipped('本机分片未开启或 shard_00 连接未注册：无片表可验');
        }

        self::assertSame([], Shard::drift('zz_no_such_base_yet'), '主库没这张基表却报出了缺口：判据读错了地方');

        Shard::resetEnsured();
        Shard::ensureTables('zz_no_such_base_yet');

        $made = Db::connection('shard_00')->select("SELECT to_regclass('public.zz_no_such_base_yet_00') AS r");
        self::assertNull(
            $made[0]['r'] ?? null,
            '基表都不存在就在分片库里建/改同名片表：那是凭空造出一张没人认识的表'
        );
    }

    /**
     * 本机真实漂移的收口：kode_notices 的片表补过之后不该再有缺口。
     *
     * 这一条跑的是生产入口（同一个 ensureTables），拿的是**真实产物**：
     * kode_app_00/01 的四张 kode_notices_* 在本机确实少 `type`，跑完就该齐。
     * 换一台本来就没漂移的机器，它会因为「片表还不存在/没有缺口」而恒绿 ——
     * 机制本身由上面那条用例负责红绿，这里只负责「历史债被清掉」。
     */
    public function test_the_real_notices_shards_have_no_column_gap_left(): void
    {
        if (!$this->runnable) {
            $this->markTestSkipped('本机分片未开启或 shard_00 连接未注册：真实片表读不到');
        }

        Shard::resetEnsured();
        Shard::ensureTables('kode_notices');

        self::assertSame(
            [],
            Shard::drift('kode_notices'),
            'kode_notices 的片表仍缺列：公告列表会静默少一整片数据（读侧 42703 被 catch 吞掉）'
        );
    }

    // ─── 私有 ───────────────────────────────────────────────

    /** @return list<array{db: string, table: string, missing: list<string>}> 只看临时基表的缺口 */
    private function driftForBase(): array
    {
        return array_values(array_filter(
            Shard::drift(self::BASE),
            static fn(array $row): bool => $row['table'] === self::SHARD_TABLE
        ));
    }

    /** 开分片（走生产那条写入路径），供 ensureTables 的门禁放行；模数保持静态配置口径。 */
    private function enableSharding(): void
    {
        $cfg = Shard::config();
        $this->forceTopology(true, (int) $cfg['db_count'], (int) $cfg['table_shards']);
    }

    /**
     * 一次性基表 + 故意少一列的片表。
     *
     * 表名必须正好是 `<base>_%02d`（ensureTables 的命名口径），所以不能带随机后缀；
     * 三个名字都以 zz_ 打头，收尾无条件删。
     */
    private function createTempTables(): void
    {
        $ddl = 'CREATE TABLE IF NOT EXISTS %s (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(64) NOT NULL DEFAULT \'\',
            note TEXT,
            %s
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )';
        Db::statement(sprintf($ddl, self::BASE, "type VARCHAR(24) NOT NULL DEFAULT 'announcement',"));
        Db::connection('shard_00')->statement(sprintf($ddl, self::SHARD_TABLE, ''));
        Db::connection('shard_00')->update(
            'INSERT INTO ' . self::SHARD_TABLE . " (name, note) VALUES (?, ?)",
            ['zz-keep-me', 'probe']
        );
    }

    /**
     * 删掉本文件可能留下的**全部**临时表。
     *
     * 不能只删写死的那三个名字：`ensureTables()` 是按 `库 × 表` 双维建的（本机 2×2 = 四张），
     * 于是 `_01` 那两张从来没人删 —— 开发库里就一直躺着 `zz_align_base_01`。
     * 残留不只是脏：下一轮 `drift()` 会读到「本用例没创建、却带着上一轮形状」的片表，
     * 「漂移」这个前提就被自己的残留污染了。所以名字从 `pg_tables` 现查，模式写死在本文件的前缀上。
     */
    private function dropTempTables(): void
    {
        $targets = [[null, self::BASE]];
        for ($d = 0; $d < Shard::dbCount(); $d++) {
            $conn = sprintf('shard_%02d', $d);
            if (!Db::hasConnection($conn)) {
                continue;
            }
            $rows = Db::connection($conn)->select(
                "SELECT tablename FROM pg_catalog.pg_tables
                  WHERE schemaname = 'public' AND tablename ~ ?",
                ['^' . preg_quote(self::BASE, '/') . '_[0-9]{2}$']
            );
            foreach ($rows as $row) {
                $name = (string) ($row['tablename'] ?? '');
                // 名字来自目录，删之前再判一次形状：只删本文件前缀 + 两位后缀
                if (preg_match('/^zz_align_base_[0-9]{2}$/', $name) === 1) {
                    $targets[] = [$conn, $name];
                }
            }
        }

        foreach ($targets as [$conn, $table]) {
            try {
                $conn === null
                    ? Db::statement('DROP TABLE IF EXISTS ' . $table)
                    : Db::connection($conn)->statement('DROP TABLE IF EXISTS ' . $table);
            } catch (\Throwable) {
                // 删不掉由下面的恢复对照与手工清理负责，这里不盖住用例真正的失败原因
            }
        }
        Shard::resetEnsured();
    }

    /**
     * 收尾对照：本文件不该把开发库的热配置留在自己写的值上。
     *
     * 断言前**先跑一次恢复**：每个用例的 setUp 都会把 `shard` 写成「开分片」，
     * 而 PHPUnit 是先跑方法再跑 tearDown —— 不在这里手动恢复，这条对照测的就是
     * 「本用例自己刚写的值」，永远不等于原状（第一版就是这么红的）。
     */
    public function test_the_shard_switch_is_left_as_it_was(): void
    {
        $this->restoreShardConfig();

        $this->assertSandboxLeftClean();
    }
}
