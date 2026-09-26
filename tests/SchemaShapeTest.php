<?php

declare(strict_types=1);

namespace app\tests;

use app\support\SchemaShape;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;

/**
 * 列形状对账：`database/sql/schema.sql` 是全量安装的权威形状，存量库必须能被收敛到它。
 *
 * 为什么单独立一道闸（AGENTS §3「DB 表结构变更两处同步」的另一半）：
 * `tests/SchemaCoverageTest.php` 只比**列在不在**，比不出「同一列在两条建表路上形状不同」。
 * 而 `kode/database` 的建表器直到 v1.25.0 都**无法表达 NOT NULL**（`nullable` 选项被
 * `toSql()` 忽略、`setNullable(false)` 是空操作），于是凡走 `Schema::create()` 建出的列
 * 在存量库里一律「可空 + 无默认」，与 schema.sql / 插件 install() 写的手写 DDL 分叉。
 * 本机实测 44 列。最坏的一条不是「少个约束」这么抽象：`kode_menus.plugin` 可空且无默认，
 * 所以任何一条不写这列的 INSERT 都静默落 NULL（库里就真有一条 NULL 行），
 * 而 `kode_notices` 的分片表（走 install() 的原生 DDL）反倒是严格的那一份 ——
 * 同一张表在同一个库里躺着两种形状。
 *
 * 用例分三类，各自挡不同的坏法：
 *  1. 解析器自己要对（少解析出一张表 = 后面全部空转）；
 *  2. 收敛判据要在**自建临时表**上真的发出 DDL 并改变形状，且对「有 NULL 数据」的列
 *     必须**拒绝动手**只报告（回填成什么是业务决定，见 AGENTS §4「禁止静默夹取」）；
 *  3. 存量库的漂移必须全部落在已记账的欠账清单里 —— 新写的迁移漏了 `->notNull()`
 *     会让一个不在清单上的列出现在这里，门禁立刻变红。
 */
final class SchemaShapeTest extends TestCase
{
    protected bool $independentApp = true;

    private const TEMP = 'zz_shape_probe';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        $this->dropTemp();
    }

    protected function tearDown(): void
    {
        $this->dropTemp();

        parent::tearDown();
    }

    /* ==================== 1. 解析器 ==================== */

    public function test_schema_sql_shape_is_parsed_wide_enough_to_be_a_real_baseline(): void
    {
        $declared = SchemaShape::declared();

        // 反空转：解析失效时下游三条用例会在「零列可比」上全绿。
        $this->assertGreaterThanOrEqual(23, count($declared), 'schema.sql 只解析出 ' . count($declared) . ' 张表，多半是解析失效');
        $columns = 0;
        foreach ($declared as $cols) {
            $columns += count($cols);
        }
        $this->assertGreaterThanOrEqual(150, $columns, 'schema.sql 只解析出 ' . $columns . ' 列，多半是解析失效');
    }

    public function test_parsed_shape_matches_the_handwritten_declarations(): void
    {
        $declared = SchemaShape::declared();

        // 助手方法建的列：NOT NULL + 字符串默认值
        $this->assertSame(true, $declared['kode_menus']['plugin']['not_null'] ?? null, 'kode_menus.plugin 应声明为 NOT NULL');
        $this->assertSame("'system'", $declared['kode_menus']['plugin']['default'] ?? null);
        // 尾随注释不得被吃进默认值
        $this->assertSame("''", $declared['kode_menus']['icon']['default'] ?? null);
        // 函数默认值：这是建表器至今表达不了、只能走原生 DDL 的一种
        $this->assertSame('NOW()', $declared['kode_users']['created_at']['default'] ?? null);
        $this->assertSame(true, $declared['kode_users']['created_at']['not_null'] ?? null);
        // 内联 PRIMARY KEY（非 SERIAL）也蕴含非空，漏判会把它报成一条假漂移
        $this->assertSame(true, $declared['kode_tenants']['id']['not_null'] ?? null);
        // JSON 字面量默认值
        $this->assertSame("'{}'", $declared['kode_menus']['meta']['default'] ?? null);
    }

    public function test_derived_tables_inherit_the_base_shape(): void
    {
        $declared = SchemaShape::declared();
        $this->assertArrayHasKey('kode_audit_logs_00', $declared, '`(LIKE … INCLUDING ALL)` 的派生表没解析出来');
        // PG 的 LIKE 就是照抄基表形状，所以这里必须一字不差地相等（否则分片表落在对账射程外）
        $this->assertSame($declared['kode_audit_logs'], $declared['kode_audit_logs_00']);
        $this->assertSame(true, $declared['kode_audit_logs_00']['duration_ms']['not_null']);
    }

    /* ==================== 2. 收敛判据 ==================== */

    public function test_reconcile_only_reports_when_apply_is_false(): void
    {
        $this->createTempTable();
        Db::statement('INSERT INTO ' . self::TEMP . ' (name, note, when_done) VALUES (?, ?, NOW())', ['a', 'b']);

        $report = SchemaShape::reconcileShape(self::TEMP, $this->wantShape(), false);
        sort($report['applied']);

        // name 只缺非空；note / when_done 两项都缺，一条差异列一行（不是拆成两行重复计数）；
        // id 是 BIGSERIAL PRIMARY KEY，本来就齐 —— 收敛不许对它动手。
        $this->assertSame(
            [
                self::TEMP . '.name SET NOT NULL',
                self::TEMP . '.note SET DEFAULT \'\' + SET NOT NULL',
                self::TEMP . '.when_done SET DEFAULT NOW() + SET NOT NULL',
            ],
            array_values($report['applied'])
        );
        $this->assertSame([], $report['blocked']);
        // 只读模式一个字节都不许动
        $this->assertSame(true, $this->columnNullable('name'));
        $this->assertSame(true, $this->columnNullable('when_done'));
        $this->assertSame(null, $this->columnDefault('note'));
    }

    public function test_reconcile_tightens_and_the_tightened_column_rejects_an_omitted_insert(): void
    {
        $this->createTempTable();
        // 先塞一行合法值：`SET NOT NULL` 要求「一行的 NULL 都没有」
        Db::statement('INSERT INTO ' . self::TEMP . ' (name, note, when_done) VALUES (?, ?, NOW())', ['a', 'b']);

        $report = SchemaShape::reconcileShape(self::TEMP, $this->wantShape(), true);
        $this->assertSame([], $report['blocked']);
        $this->assertCount(3, $report['applied']);

        $this->assertSame(false, $this->columnNullable('name'));
        $this->assertSame(false, $this->columnNullable('note'));
        $this->assertSame(false, $this->columnNullable('when_done'));
        $this->assertSame("''::character varying", $this->columnDefault('note'));
        $this->assertStringContainsString('now(', (string) $this->columnDefault('when_done'));

        // 收敛的真正收益：少写一列不再是「静默落 NULL」，而是数据库直接拒
        $this->expectException(\Throwable::class);
        Db::statement('INSERT INTO ' . self::TEMP . ' (note) VALUES (?)', ['c']);
    }

    public function test_a_column_with_null_rows_is_reported_and_never_touched(): void
    {
        $this->createTempTable();
        // name 留 NULL，note/when_done 给值 —— 一列挡路不该让整张表停在原地
        Db::statement('INSERT INTO ' . self::TEMP . ' (name, note, when_done) VALUES (?, ?, NOW())', [null, 'x']);

        $report = SchemaShape::reconcileShape(self::TEMP, $this->wantShape(), true);

        $this->assertCount(1, $report['blocked'], '只应有 name 一条：' . implode(' | ', $report['blocked']));
        $this->assertStringContainsString(self::TEMP . '.name', $report['blocked'][0]);
        $this->assertStringContainsString('先定回填值', $report['blocked'][0]);
        $this->assertSame(true, $this->columnNullable('name'), 'blocked 的列必须保持原状');
        $this->assertCount(2, $report['applied']);
        $this->assertSame(false, $this->columnNullable('note'));
        $this->assertSame(false, $this->columnNullable('when_done'));

        // 数据清掉之后同一条判据就能收敛它（幂等 + 可重入）
        Db::statement('DELETE FROM ' . self::TEMP);
        $again = SchemaShape::reconcileShape(self::TEMP, $this->wantShape(), true);
        $this->assertSame([], $again['blocked']);
        $this->assertSame([self::TEMP . '.name SET NOT NULL'], $again['applied']);
        $this->assertSame(false, $this->columnNullable('name'));
        // 再跑一次：已经对齐的形状不该产生任何 DDL
        $third = SchemaShape::reconcileShape(self::TEMP, $this->wantShape(), true);
        $this->assertSame([], $third['applied']);
        $this->assertSame([], $third['blocked']);
    }

    public function test_a_table_that_schema_sql_does_not_declare_is_left_alone(): void
    {
        $this->createTempTable();

        $report = SchemaShape::reconcile('zz_shape_no_such_table', true);
        $this->assertSame(['table' => 'zz_shape_no_such_table', 'applied' => [], 'blocked' => [], 'absent' => []], $report);

        // 表存在但 schema.sql 没声明它 —— 同样一个字节都不动（判据缺失就不许动手）
        $untouched = SchemaShape::reconcile(self::TEMP, true);
        $this->assertSame([], $untouched['applied']);
        $this->assertSame(true, $this->columnNullable('name'));
    }

    /* ==================== 3. 存量库的欠账必须都在账上 ==================== */

    public function test_live_drift_is_entirely_inside_the_recorded_legacy_list(): void
    {
        $legacy = [];
        foreach (SchemaShape::LEGACY_NULLABLE as $table => $cols) {
            foreach ($cols as $c) {
                $legacy[$table . '.' . $c] = true;
            }
        }
        $this->assertNotEmpty($legacy, '欠账清单为空就无法验证这条门禁');
        // 清单里的每一项都必须是 schema.sql 真的声明为 NOT NULL 的列 ——
        // 否则「记进清单」就成了把本来可空的列偷偷收紧的通道。
        $declared = SchemaShape::declared();
        foreach ($legacy as $key => $_) {
            [$t, $c] = explode('.', $key, 2);
            $this->assertSame(true, $declared[$t][$c]['not_null'] ?? null, "欠账清单里的 {$key} 在 schema.sql 并不是 NOT NULL");
        }

        $stray = [];
        foreach (SchemaShape::driftedColumns() as $table => $cols) {
            $base = SchemaShape::baseTableOf($table);
            foreach ($cols as $c) {
                if (!isset($legacy[$base . '.' . $c])) {
                    $stray[] = "{$table}.{$c}";
                }
            }
        }
        sort($stray);

        $this->assertSame(
            [],
            $stray,
            '这些列的真实形状弱于 schema.sql，却不在欠账清单里：新写的迁移多半漏了 ->notNull()'
                . '（存量库跑 `php kode migrate` 收敛；确属欠账再登记进 SchemaShape::LEGACY_NULLABLE）：'
                . implode(', ', $stray)
        );
    }

    public function test_the_convergence_migration_exists_and_delegates_to_the_single_judgement_point(): void
    {
        $file = dirname(__DIR__) . '/database/migrations/2026_09_25_000042_converge_column_shapes.php';
        $this->assertFileExists($file, '欠账清单不会自己落地，收敛要由迁移执行');
        $code = self::stripComments((string) file_get_contents($file));
        // 判据只写一份：迁移里不许出现第二套「比形状 / 拼 ALTER」的实现。
        // 扫的是**去掉注释后的代码** —— 类注释里本来就要解释 `SET NOT NULL` 是什么。
        $this->assertStringContainsString('SchemaShape::reconcile(', $code);
        $this->assertDoesNotMatchRegularExpression('/ALTER\s+TABLE|SET\s+NOT\s+NULL|DROP\s+DEFAULT/i', $code, '迁移自己拼 DDL 就是第二份判据');
    }

    /** 注释从判据射程里剔掉（用 `token_get_all` 而不是正则：正文里出现「星号加斜杠」的注释会骗过后者）。 */
    private static function stripComments(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($tok) ? (string) $tok[1] : $tok;
        }

        return $out;
    }

    /* ==================== 夹具 ==================== */

    /** @return array<string, array{not_null: bool, default: ?string}> */
    private function wantShape(): array
    {
        return [
            'id' => ['not_null' => true, 'default' => 'nextval'],
            'name' => ['not_null' => true, 'default' => null],
            'note' => ['not_null' => true, 'default' => "''"],
            'when_done' => ['not_null' => true, 'default' => 'NOW()'],
        ];
    }

    private function createTempTable(): void
    {
        // 故意全可空 + 无默认：复刻「建表器 v1.25.0 之前建出来的形状」
        Db::statement(
            'CREATE TABLE ' . self::TEMP . ' (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(64),
                note VARCHAR(32),
                when_done TIMESTAMPTZ
            )'
        );
    }

    private function columnNullable(string $col): bool
    {
        $rows = Db::select(
            'SELECT a.attnotnull FROM pg_catalog.pg_attribute a
              WHERE a.attrelid = to_regclass(?) AND a.attname = ? AND a.attnum > 0',
            ['public.' . self::TEMP, $col]
        );
        $this->assertNotSame([], $rows, "临时表没有列 {$col}");

        return !((bool) $rows[0]['attnotnull']);
    }

    private function columnDefault(string $col): ?string
    {
        $rows = Db::select(
            "SELECT COALESCE((SELECT pg_catalog.pg_get_expr(d.adbin, d.adrelid)
                                FROM pg_catalog.pg_attrdef d
                               WHERE d.adrelid = a.attrelid AND d.adnum = a.attnum), '') AS dflt
               FROM pg_catalog.pg_attribute a
              WHERE a.attrelid = to_regclass(?) AND a.attname = ? AND a.attnum > 0",
            ['public.' . self::TEMP, $col]
        );

        return ($rows[0]['dflt'] ?? '') === '' ? null : (string) $rows[0]['dflt'];
    }

    private function dropTemp(): void
    {
        try {
            Db::statement('DROP TABLE IF EXISTS ' . self::TEMP);
        } catch (\Throwable) {
            // 库不可达时留给下一次 setUp 处理
        }
    }
}
