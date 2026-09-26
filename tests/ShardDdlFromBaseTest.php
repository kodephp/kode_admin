<?php

declare(strict_types=1);

namespace app\tests;

use app\support\Shard;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Request as HttpRequest;

/**
 * 片表的形状必须**从主库基表的目录派生**（`Shard::ddlFor()` 读 `pg_attribute` + `pg_index`）。
 *
 * 为什么单独立一道闸：`ddlFor()` 里原先有两支各自会坏一条路的写法——
 *  1. `kode_notices` 走一份**手写的 schema 副本**。基表两年里改过三处（body→TEXT、加 status、
 *     加 type），副本一次没跟着改；而且它与基表的偏离是**双向**的：基表
 *     `title`/`body`/`created_at`/`updated_at` 实测可空、两个时间列还没有默认值，副本写的却是
 *     `NOT NULL DEFAULT NOW()` —— 于是「基表合法的写入」到片上会被拒，同一条不带时间的 INSERT
 *     还会因落点不同得出不同结果（跨片读取按 created_at 排序时，NULL 与 NOW() 是两个世界）。
 *  2. 其余基表走 `CREATE TABLE … (LIKE {$base} INCLUDING ALL)`，而**分片库里没有基表**：
 *     实测在 kode_app_00 里执行 `LIKE zz_like_probe INCLUDING ALL` 直接
 *     「关系 "zz_like_probe" 不存在」（42P01），再被 `ensureTables()` 的 catch 吞掉。
 *     结果就是「除了 notices，谁都分不了片」，而且没有任何一处报出来。
 *
 * 用例都在**分片库里**执行生成的 DDL（那里恰恰没有基表），所以第 2 条一旦回退就是红。
 * 临时对象一律 `zz_` 打头、收尾无条件删：主库一张、shard_00 两张，删不掉由 `test_scratch_objects_are_gone` 报。
 */
final class ShardDdlFromBaseTest extends TestCase
{
    protected bool $independentApp = true;

    /** 一次性基表（建在**主库**，因为判据读的是主库目录） */
    private const BASE = 'zz_ddl_base';

    /** 由 ddlFor 建到分片库里的探针表 */
    private const PROBE = 'zz_ddl_probe';

    /** 用真实基表 kode_notices 生成的探针表 */
    private const NOTICES_PROBE = 'zz_ddl_notices_probe';

    private const SHARD = 'shard_00';

    /** 本机是否真的具备跑条件（shard_00 连接注册了；建表本身不看分片开关） */
    private bool $runnable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        $this->runnable = Db::hasConnection(self::SHARD);
        Shard::resetEnsured();
    }

    protected function tearDown(): void
    {
        $this->dropScratch();
        HttpRequest::clear();

        parent::tearDown();
    }

    /**
     * 核心：片表的列形状必须与基表**逐列相同**，包括「基表可空」这一半。
     *
     * 断言的是补过的两份反向证据：`stamp` 在基表既没有默认值也没有 NOT NULL，
     * 老写法会把它做成 `NOT NULL DEFAULT NOW()`；`nullable_title` 在基表可空，老写法是 NOT NULL。
     */
    public function test_the_shard_shape_is_derived_from_the_base_catalog(): void
    {
        $this->requireShard();
        $this->createScratchBase();

        $stmts = Shard::ddlFor(self::BASE, self::PROBE);
        self::assertNotEmpty($stmts, 'ddlFor 没出任何语句：建表入口当场空转');
        // 手写副本与那支必然失败的 LIKE 都不许复活
        foreach ($stmts as $ddl) {
            self::assertStringNotContainsStringIgnoringCase('(LIKE ', $ddl, '又回去用 LIKE 了：分片库里没有基表');
            self::assertStringNotContainsStringIgnoringCase('BIGSERIAL', $ddl, '又出现了第二份写死的表定义');
        }
        foreach ($stmts as $ddl) {
            $this->execOnShard($ddl);
        }

        self::assertSame(
            $this->shapeOf(null, self::BASE),
            $this->shapeOf(self::SHARD, self::PROBE),
            '片表形状与基表目录不一致：建出来的片会把基表合法的写入拒掉'
        );
        // 正对照：这几列的「松散」必须是真测到了，而不是 shapeOf 两边都读成空
        $shape = $this->shapeOf(self::SHARD, self::PROBE);
        self::assertContains('nullable_title|character varying(128)', $shape, '可空列没被如实建成可空');
        self::assertContains('stamp|timestamp with time zone', $shape, '无默认值的时间列被加了默认值');
        self::assertContains('amount|numeric(10,2)|NOT NULL|0', $shape, 'numeric 的精度/默认值没搬过来');
        self::assertContains('tags|text[]', $shape, '数组类型被认不出来（旧白名单会当场抛）');

        $defs = $this->indexesOf(self::SHARD, self::PROBE);
        $unique = array_values(array_filter($defs, static fn(string $d): bool => str_starts_with($d, 'CREATE UNIQUE')));
        self::assertCount(1, $unique, '片表上的唯一索引不止主键：唯一约束不该照抄（只管单片却会拒掉基表合法的写）');
        self::assertStringContainsString('(id)', $unique[0] ?? '', '主键没建成：' . implode(' / ', $unique));
        self::assertContains(
            'CREATE INDEX idx_zz_ddl_probe_flag_note ON public.zz_ddl_probe USING btree (flag, note)',
            $defs,
            '普通索引没照抄或名字没换成片表名：' . implode(' / ', $defs)
        );
        foreach ($defs as $def) {
            self::assertStringNotContainsStringIgnoringCase('upper(', $def, '表达式索引被猜了一份出来：跳过才对');
        }
    }

    /** 序列不跨库：基表的 BIGSERIAL 在片上必须变成**属于自己的** IDENTITY，且允许显式带 id 插入。 */
    public function test_a_serial_base_column_becomes_an_identity_the_shard_can_own(): void
    {
        $this->requireShard();
        $this->createScratchBase();
        foreach (Shard::ddlFor(self::BASE, self::PROBE) as $ddl) {
            $this->execOnShard($ddl);
        }

        $identity = $this->selectOnShard(
            'SELECT attidentity FROM pg_attribute WHERE attrelid = to_regclass(?) AND attname = ?',
            ['public.' . self::PROBE, 'id']
        );
        self::assertSame('d', (string) ($identity[0]['attidentity'] ?? ''), 'id 不是 BY DEFAULT 身份列：' . json_encode($identity));

        $db = Db::connection(self::SHARD);
        $db->update('INSERT INTO ' . self::PROBE . ' (nullable_title) VALUES (?)', ['auto']);
        $db->update('INSERT INTO ' . self::PROBE . ' (id, nullable_title) VALUES (?, ?)', [900001, 'explicit']);
        $ids = array_column(
            $db->select('SELECT id FROM ' . self::PROBE . ' ORDER BY id'), 'id'
        );
        self::assertSame(['1', '900001'], array_map('strval', $ids), '自动号与显式号有一条没落进去');
    }

    /**
     * 真实产物的收口：拿生产基表 `kode_notices` 的目录建一张探针片，形状必须与基表一致。
     *
     * 这一条测的是「本机那份手写副本到底漂了什么」：基表 title/body 可空、两个时间列没默认值，
     * 而 Kode 的既有片（kode_notices_00..）是照副本建的，两处都比基表严。
     * 换一台基表定义不同的机器，这条照样成立 —— 判据只有基表目录一份。
     */
    public function test_the_real_notices_base_produces_a_matching_shard_shape(): void
    {
        $this->requireShard();
        if ($this->shapeOf(null, 'kode_notices') === []) {
            $this->markTestSkipped('主库没有 kode_notices 基表：真实产物无从对照');
        }

        foreach (Shard::ddlFor('kode_notices', self::NOTICES_PROBE) as $ddl) {
            $this->execOnShard($ddl);
        }

        $base = $this->shapeOf(null, 'kode_notices');
        $probe = $this->shapeOf(self::SHARD, self::NOTICES_PROBE);
        self::assertSame($base, $probe, 'kode_notices 的片形状与基表不一致：见上面逐列对照');
        // 反向证据（`ConvergeColumnShapes` 落地后换了一批列）：基表两年改过的那些可空列已经收敛成
        // NOT NULL，`title`/`created_at` 现在两边一致，拿它们当「基表比副本松」的对照已经失效。
        // 仍然不一致的是 `type`：基表 NOT NULL，而照手写副本建起来的既有片 `kode_notices_00` 可空。
        // 于是上面那条 assertSame 有两个方向的.red：跟着基表就与既有片不同（正确），
        // 抄既有片/抄副本就与基表不同（红）。
        $baseType = $this->columnShape($base, 'type');
        $neighbourType = $this->columnShape($this->shapeOf(self::SHARD, 'kode_notices_00'), 'type');
        self::assertStringContainsString(
            'NOT NULL',
            $baseType,
            '基表的 type 已不再 NOT NULL：这条反向证据要换一个仍然不一致的列，否则上面的对账会空转'
        );
        self::assertStringNotContainsString(
            'NOT NULL',
            $neighbourType,
            '既有片表的 type 也已收敛：基表与副本两处形状重新重合，上面的 assertSame 就不再能分辨「跟谁」'
        );

        // 基表那条 (type, status) 必须照抄到片上：读侧每片都是这个条件，缺索引等于每片顺序扫
        self::assertNotEmpty(
            array_values(array_filter(
                $this->indexesOf(self::SHARD, self::NOTICES_PROBE),
                static fn(string $d): bool => str_contains($d, '(type, status)')
            )),
            'kode_notices 的 (type, status) 索引没进片表 DDL：新开的片一律顺序扫'
        );
    }

    /** 建过片的那条路也要把索引补齐（既有片表 CREATE TABLE 被 IF NOT EXISTS 跳过，索引不能跟着一起跳过）。 */
    public function test_ensure_tables_backfills_a_missing_index_on_an_existing_shard(): void
    {
        $this->requireShard();
        if (!Shard::enabled()) {
            $this->markTestSkipped('本机分片未开启：ensureTables 的门禁不放行，补索引这条路跑不出来');
        }

        // 现场：一张**列已经齐**、但索引被人为丢掉的 kode_notices 片表
        $table = 'kode_notices_00';
        $exists = Db::connection(self::SHARD)->select('SELECT to_regclass(?) AS r', ['public.' . $table]);
        if (($exists[0]['r'] ?? null) === null) {
            $this->markTestSkipped("分片库里没有 {$table}：这条要的是「已存在的片表补索引」");
        }
        Db::connection(self::SHARD)->statement('DROP INDEX IF EXISTS idx_' . $table . '_type_status');

        Shard::resetEnsured();
        Shard::ensureTables('kode_notices');

        $defs = $this->indexesOf(self::SHARD, $table);
        self::assertNotEmpty(
            array_values(array_filter($defs, static fn(string $d): bool => str_contains($d, '(type, status)'))),
            "跑过 ensureTables 之后 {$table} 仍无 (type, status)：读侧每片一次顺序扫"
        );
    }

    /** 基表读不到时必须**抛**，而不是回一支在分片库里必然失败的 LIKE。 */
    public function test_an_unreadable_base_fails_loud_instead_of_emitting_a_broken_like(): void
    {
        $this->requireShard();

        $caught = null;
        try {
            Shard::ddlFor('zz_no_such_base_yet', 'zz_no_such_base_yet_00');
        } catch (\Throwable $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, '基表不存在却没报错：那支必然失败的 LIKE 又回来了');
        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertStringContainsString('zz_no_such_base_yet', $caught?->getMessage(), '报错没点名是哪张基表');

        $made = Db::connection(self::SHARD)->select('SELECT to_regclass(?) AS r', ['public.zz_no_such_base_yet_00']);
        self::assertNull($made[0]['r'] ?? null, '基表读不到还是在分片库里建了同名片表');
    }

    /** 收尾对照：本文件不该在主库与分片库里留下临时表。 */
    public function test_scratch_objects_are_gone(): void
    {
        $this->dropScratch();

        $left = [];
        foreach ([[null, self::BASE], [null, self::PROBE], [self::SHARD, self::PROBE], [self::SHARD, self::NOTICES_PROBE]] as [$conn, $table]) {
            $row = $conn === null
                ? Db::select('SELECT to_regclass(?) AS r', ['public.' . $table])
                : Db::connection($conn)->select('SELECT to_regclass(?) AS r', ['public.' . $table]);
            if (($row[0]['r'] ?? null) !== null) {
                $left[] = ($conn ?? 'default') . '.' . $table;
            }
        }
        self::assertSame([], $left, '临时表没清干净：开发库里会多出没人认领的表');
    }

    // ─── 私有 ───────────────────────────────────────────────

    private function requireShard(): void
    {
        if (!$this->runnable) {
            $this->markTestSkipped('shard_00 连接未注册：片表 DDL 要在真分片库里执行才测得到');
        }
    }

    /**
     * 一次性基表：故意覆盖「副本当年写错的那几种形状」+ 索引的三种取舍。
     *
     * - `nullable_title`/`stamp` 可空、`stamp` 无默认值 → 老写法会给它们加 NOT NULL/DEFAULT
     * - `amount numeric(10,2)`、`tags text[]` → 旧的类型白名单认不出来（逗号、方括号）
     * - `UNIQUE (flag, nullable_title)` → 唯一索引不许照抄
     * - `(flag, note)` 普通索引 → 必须照抄并改名
     * - `(upper(nullable_title))` 表达式索引 → 跳过
     */
    private function createScratchBase(): void
    {
        Db::statement('DROP TABLE IF EXISTS ' . self::BASE);
        Db::statement('CREATE TABLE ' . self::BASE . " (
            id BIGSERIAL PRIMARY KEY,
            nullable_title VARCHAR(128),
            note TEXT,
            stamp TIMESTAMP WITH TIME ZONE,
            amount NUMERIC(10,2) NOT NULL DEFAULT 0,
            flag SMALLINT NOT NULL DEFAULT 1,
            tags TEXT[],
            UNIQUE (flag, nullable_title)
        )");
        Db::statement('CREATE INDEX idx_' . self::BASE . '_flag_note ON ' . self::BASE . ' (flag, note)');
        Db::statement('CREATE INDEX idx_' . self::BASE . '_upper ON ' . self::BASE . ' (upper(nullable_title))');
        Shard::resetEnsured();
    }

    /** 在分片库（shard_00）里执行一条 DDL。 */
    private function execOnShard(string $sql): void
    {
        Db::connection(self::SHARD)->statement($sql);
    }

    /** 在分片库里读（本文件的探针表全部落在 shard_00）。 */
    private function selectOnShard(string $sql, array $bindings = []): array
    {
        return $bindings === []
            ? Db::connection(self::SHARD)->select($sql)
            : Db::connection(self::SHARD)->select($sql, $bindings);
    }

    /** 从 `shapeOf()` 的形状清单里取某一列那一行（列不存在时返回空串，由调用方判空）。 */
    private function columnShape(array $shape, string $column): string
    {
        foreach ($shape as $line) {
            if (str_starts_with($line, $column . '|')) {
                return $line;
            }
        }

        return '';
    }

    /**
     * 一张表的形状：`列名|类型|NOT NULL|默认值`，自增列（序列或身份）统一记成 SERIAL。
     *
     * 序列不跨库，所以基表那条 `nextval('zz_ddl_base_id_seq'::regclass)` 与片表自己的
     * `nextval('zz_ddl_probe_id_seq'::regclass)` 天然不等 —— 两边都归一成 SERIAL 之后，
     * 这一列只剩「类型与非空相同」可比；「片上到底是不是自己拥有的那条序列」这件事
     * 由 `test_a_serial_base_column_becomes_an_identity_the_shard_can_own()` 负责（它读 attidentity）。
     *
     * @param string|null $conn null = 主库
     * @return list<string>
     */
    private function shapeOf(?string $conn, string $table): array
    {
        $sql = "SELECT a.attname AS name,
                       pg_catalog.format_type(a.atttypid, a.atttypmod) AS decl,
                       CASE WHEN a.attnotnull THEN 'NOT NULL' ELSE '' END AS nn,
                       CASE WHEN a.attidentity <> '' THEN 'SERIAL'
                            WHEN COALESCE(pg_catalog.pg_get_expr(d.adbin, d.adrelid), '') LIKE 'nextval(%' THEN 'SERIAL'
                            ELSE COALESCE(pg_catalog.pg_get_expr(d.adbin, d.adrelid), '') END AS dflt
                  FROM pg_catalog.pg_attribute a
                  LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
                 WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped
                 ORDER BY a.attnum";
        $rows = $conn === null ? Db::select($sql, ['public.' . $table]) : Db::connection($conn)->select($sql, ['public.' . $table]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = trim(
                ($r['name'] ?? '') . '|' . ($r['decl'] ?? '') . '|' . ($r['nn'] ?? '') . '|' . ($r['dflt'] ?? ''),
                '|'
            );
        }

        return $out;
    }

    /**
     * @param string|null $conn null = 主库
     * @return list<string> `pg_indexes.indexdef` 原文
     */
    private function indexesOf(?string $conn, string $table): array
    {
        $sql = 'SELECT indexdef FROM pg_catalog.pg_indexes WHERE schemaname = ? AND tablename = ? ORDER BY indexname';
        $rows = $conn === null ? Db::select($sql, ['public', $table]) : Db::connection($conn)->select($sql, ['public', $table]);

        return array_values(array_map(static fn(array $r): string => (string) ($r['indexdef'] ?? ''), $rows));
    }

    private function dropScratch(): void
    {
        foreach ([[null, self::PROBE], [self::SHARD, self::PROBE], [self::SHARD, self::NOTICES_PROBE], [null, self::BASE]] as [$conn, $table]) {
            try {
                $conn === null
                    ? Db::statement('DROP TABLE IF EXISTS ' . $table . ' CASCADE')
                    : Db::connection($conn)->statement('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
            } catch (\Throwable) {
                // 删不掉由 test_scratch_objects_are_gone 报，这里不盖住用例真正的失败原因
            }
        }
        Shard::resetEnsured();
    }
}
