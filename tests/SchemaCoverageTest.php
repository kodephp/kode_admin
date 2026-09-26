<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Testing\TestCase;

/**
 * schema.sql 覆盖守卫：database/sql/schema.sql 是全量建表脚本（新环境一次执行即得完整库），
 * 它的表集合必须始终包含 database/migrations/ 建出的全部核心表。
 *
 * 背景：核心表陆续由增量迁移补进来，schema.sql 却长期停在建库初期，缺 9 张表——
 * 新环境照 schema.sql 装完，系统日志/通知/媒体/租户等页面直接查不到表。
 * 此测试把「迁移建过的表必须在 schema.sql 里有全量定义」固化为断言，防止再次漂移。
 */
final class SchemaCoverageTest extends TestCase
{
    public function test_every_migration_table_is_declared_in_schema_sql(): void
    {
        $declared = $this->schemaSqlTables();
        $this->assertNotEmpty($declared, 'schema.sql 应至少声明一张表');

        $missing = array_values(array_diff($this->migrationTables(), $declared));

        $this->assertSame(
            [],
            $missing,
            "以下迁移建出的表缺少 schema.sql 全量定义，新环境安装后会查不到表：" . implode(', ', $missing)
        );
    }

    public function test_schema_sql_applies_as_valid_postgres_ddl(): void
    {
        $sql = (string) file_get_contents($this->schemaSqlPath());
        $stmts = array_filter(array_map('trim', explode(';', $sql)), static fn(string $s): bool => $s !== '' && !str_starts_with($s, '--'));

        foreach ($stmts as $stmt) {
            $body = preg_replace('/^\s*--.*$/m', '', $stmt) ?? $stmt;
            if (trim($body) === '') {
                continue;
            }
            $this->assertMatchesRegularExpression(
                '/^(CREATE|COMMENT|INSERT|SET|ALTER|BEGIN|COMMIT)/i',
                trim($body),
                'schema.sql 出现无法识别的语句（多半是缺少分号或括号不配对）：'
                    . substr(trim($body), 0, 80)
            );
        }
    }

    /**
     * 列级漂移：迁移里新增/创建的列，schema.sql 的全量定义必须跟上。
     *
     * 表级覆盖（上一个用例）拦不住这种情况：kode_roles 表在 schema.sql 里躺着，
     * 只是少了 status/remark 两列 —— 新环境照 schema.sql 建完库、迁移不再重放，
     * `SELECT ... status ... FROM kode_roles` 直接 42703 报错，角色页整页 500。
     */
    public function test_every_migration_column_is_declared_in_schema_sql(): void
    {
        $declared = $this->schemaSqlColumns();
        $this->assertNotEmpty($declared, 'schema.sql 应至少解析出一列');
        // 反空转：两侧解析都必须真吃到东西，否则本门禁会在代码照旧漂移时安静地全绿。
        $this->assertGreaterThanOrEqual(20, count($declared), 'schema.sql 只解析出 ' . count($declared) . ' 张表的列，多半是解析失效');

        $fromMigrations = $this->migrationColumns();
        $this->assertArrayHasKey('kode_roles', $fromMigrations);
        $this->assertArrayHasKey('slug', $fromMigrations['kode_roles'], 'Schema::create 块没解析出列，门禁是空的');
        $this->assertArrayHasKey('status', $fromMigrations['kode_roles'], 'ALTER ADD COLUMN 没解析出列，门禁是空的');

        $missing = [];
        foreach ($this->migrationColumns() as $table => $columns) {
            foreach ($columns as $column => $sources) {
                if (!isset($declared[$table][$column])) {
                    $missing[] = "{$table}.{$column}（" . implode(', ', $sources) . '）';
                }
            }
        }
        sort($missing);

        $this->assertSame(
            [],
            $missing,
            '以下列只存在于迁移、不在 schema.sql 的全量定义里，新环境装完会查不到列：'
                . implode('; ', $missing)
        );
    }

    /**
     * @return array<string, array<string, true>> 表名 => 列名集合
     */
    private function schemaSqlColumns(): array
    {
        $sql = (string) file_get_contents($this->schemaSqlPath());
        $columns = [];

        foreach (explode(';', $sql) as $stmt) {
            $body = trim((string) preg_replace('/^\s*--.*$/m', '', $stmt));
            if ($body === '') {
                continue;
            }
            if (preg_match('/^CREATE TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["\']?(\w+)["\']?\s*\((.*)\)$/is', $body, $m) === 1) {
                // (LIKE other INCLUDING ALL) 一类的派生表没有自己的列定义
                if (preg_match('/^\s*LIKE\b/i', $m[2]) === 1) {
                    continue;
                }
                foreach (preg_split('/\n/', $m[2]) ?: [] as $line) {
                    $line = trim((string) preg_replace('/--.*$/', '', $line));
                    if ($line === '' || preg_match('/^(PRIMARY|UNIQUE|CONSTRAINT|FOREIGN|CHECK|LIKE)\b/i', $line) === 1) {
                        continue;
                    }
                    if (preg_match('/^["\']?(\w+)["\']?\s+\S/', $line, $c) === 1) {
                        $columns[$this->finalName(strtolower($m[1]))][strtolower($c[1])] = true;
                    }
                }

                continue;
            }
            if (preg_match('/^ALTER TABLE\s+["\']?(\w+)["\']?\s+ADD COLUMN\s+(?:IF NOT EXISTS\s+)?["\']?(\w+)/i', $body, $m) === 1) {
                $columns[$this->finalName(strtolower($m[1]))][strtolower($m[2])] = true;
            }
        }

        return $columns;
    }

    /**
     * 迁移落到库里的列：`Schema::create` 块内的 `$t->type('col'…)` + 事后 `ALTER TABLE … ADD COLUMN`。
     *
     * @return array<string, array<string, list<string>>> 表名 => 列名 => 出处文件
     */
    private function migrationColumns(): array
    {
        $out = [];

        foreach (glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            $name = basename($file);

            if (preg_match_all('/Schema::create\(\'([a-z0-9_]+)\'.*?\n\s*\}/s', $src, $blocks, PREG_SET_ORDER)) {
                foreach ($blocks as $block) {
                    $table = $this->finalName($block[1]);
                    if (preg_match_all('/\$t->\w+\(\s*\'([a-z0-9_]+)\'/', $block[0], $cols, PREG_SET_ORDER)) {
                        foreach ($cols as $col) {
                            $out[$table][strtolower($col[1])][] = $name;
                        }
                    }
                }
            }

            if (preg_match_all('/ALTER TABLE\s+["\']?(\w+)["\']?\s+ADD COLUMN\s+(?:IF NOT EXISTS\s+)?["\']?(\w+)/i', $src, $adds, PREG_SET_ORDER)) {
                foreach ($adds as $add) {
                    $out[$this->finalName(strtolower($add[1]))][strtolower($add[2])][] = $name;
                }
            }
        }

        return $out;
    }

    /** @return list<string> migrations 建立的核心表名（已套用 kode_ 前缀） */
    private function migrationTables(): array
    {
        $tables = [];
        foreach (glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            preg_match_all("/Schema::create\('([a-z0-9_]+)'/", $src, $m);
            foreach (array_merge($m[1], $this->createTableNames($src)) as $name) {
                $tables[$this->finalName($name)] = true;
            }
        }

        return array_keys($tables);
    }

    /**
     * 从 SQL 文本里抽 CREATE TABLE 的表名。
     *
     * 表名可能是拼接/插值的（如分片表 `"kode_{$base}_{$i}"`），这类抽不到也不该抽——
     * 只认字面量。docblock 里的中文说明也可能出现 "CREATE TABLE IF NOT EXISTS" 字样，
     * 正则回溯时会误捕 SQL 关键字，故按关键字表过滤。
     *
     * @return list<string>
     */
    private function createTableNames(string $src): array
    {
        preg_match_all('/CREATE TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["\']?([a-z0-9_]+)/i', $src, $m);
        $keywords = ['if', 'not', 'exists', 'only', 'like', 'table', 'create'];

        return array_values(array_filter(
            $m[1],
            static fn(string $n): bool => !in_array(strtolower($n), $keywords, true)
        ));
    }

    /**
     * 迁移早期建的表不带前缀，2026_09_06_000011_kode_prefix 一次性把它们 RENAME 成 kode_*，
     * 所以最终表名 = kode_ + 原名（已经是 kode_ 开头的原样保留）。
     */
    private function finalName(string $name): string
    {
        if ($name === 'migrations' || preg_match('/_\d{2}$/', $name) === 1) {
            return $name;
        }

        return str_starts_with($name, 'kode_') ? $name : 'kode_' . $name;
    }

    /** @return list<string> schema.sql 里 CREATE TABLE 声明的表名 */
    private function schemaSqlTables(): array
    {
        return array_values(array_unique($this->createTableNames(
            (string) file_get_contents($this->schemaSqlPath())
        )));
    }

    private function schemaSqlPath(): string
    {
        return dirname(__DIR__) . '/database/sql/schema.sql';
    }
}
