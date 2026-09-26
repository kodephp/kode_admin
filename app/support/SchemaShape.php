<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;

/**
 * 列形状对账：把 `database/sql/schema.sql` 声明的 NOT NULL / DEFAULT 落到已存在的库上。
 *
 * 一张表有三条建表路，形状互不相同：
 *  1. `schema.sql`（新环境全量安装）—— 手写，列普遍 `NOT NULL DEFAULT ...`；
 *  2. `database/migrations/`（存量环境增量）—— 走 `Kode\Framework\Database\Schema` 建表器，
 *     而建表器在 `kode/database` v1.25.0 之前**没有任何途径表达 NOT NULL**
 *     （`nullable` 选项被 `toSql()` 忽略、`setNullable(false)` 是空操作），
 *     于是这条路上建出的列在库里一律可空、且没有默认值；
 *  3. 插件 `install()` 的原生 DDL —— 手写，和第 1 条同形。
 *
 * 实测后果（本机 `kode_app`，44 列）：`kode_notices` 基表由第 2 条路建出（title/body 可空、
 * created_at 无默认），而它的分片表由第 3 条路建出（全部 NOT NULL DEFAULT NOW()）——
 * 同一张表在同一个库里有两种形状。更麻烦的是「可空且无默认」的列对
 * `INSERT` 少写一列是**静默容忍**的：`kode_menus.plugin` 就因此躺着一条 NULL
 * （列默认值缺失 + 有人绕过控制器插过行），而读侧一律按字符串用这一列。
 *
 * 本类只做一件事：以 `schema.sql` 为准绳，**只收紧、不放宽**
 * （补 `SET DEFAULT` / `SET NOT NULL`；绝不 `DROP DEFAULT`、绝不放宽非空）。
 * 有 NULL 数据的列不动、只报告 —— 回填是真数据写操作，得由人决定回成什么。
 */
final class SchemaShape
{
    /** @var array<string, array<string, array<string, array{not_null: bool, default: ?string}>>> 按文件路径缓存的解析结果 */
    private static array $declared = [];

    /**
     * v1.25.0 之前由建表器建出、形状弱于 schema.sql 的列（表 => 列）。
     *
     * 这份清单是「待收敛」的记账，不是永久豁免：`php kode migrate` 跑完
     * `ConvergeColumnShapes` 后它们就和 schema.sql 对齐了。它存在的意义是让
     * `tests/SchemaShapeTest.php` 能区分「历史欠账」与「新写的迁移又漏了 notNull()」——
     * 新列不在清单里，门禁立刻变红；往清单里加条目是一次显式的「我承认这是欠账」。
     *
     * @var array<string, list<string>>
     */
    public const LEGACY_NULLABLE = [
        'kode_users' => ['name', 'email', 'password', 'created_at', 'updated_at'],
        'kode_roles' => ['name', 'slug', 'created_at', 'updated_at'],
        'kode_role_user' => ['user_id', 'role_slug'],
        'kode_menus' => ['title', 'path', 'icon', 'plugin', 'sort'],
        'kode_notices' => ['title', 'body', 'created_at', 'updated_at'],
        'kode_audit_logs' => [
            'user_id', 'username', 'method', 'path', 'status', 'duration_ms', 'ip', 'created_at', 'updated_at',
        ],
        'kode_sys_configs' => ['ckey', 'cvalue', 'remark', 'created_at', 'updated_at'],
        'kode_depts' => ['name', 'parent_id', 'sort', 'created_at', 'updated_at'],
        'kode_plugins' => ['name', 'version', 'status', 'created_at', 'updated_at'],
    ];

    /**
     * 解析 schema.sql 的列形状。
     *
     * `(LIKE other INCLUDING ALL)` 的派生表（审计分片表）继承基表形状 —— PG 自己就是这么建的，
     * 所以这里把基表的列搬过来，否则分片表就落在对账射程外。
     *
     * @return array<string, array<string, array{not_null: bool, default: ?string}>>
     */
    public static function declared(?string $file = null): array
    {
        $path = $file ?? self::schemaSqlPath();
        if (isset(self::$declared[$path])) {
            return self::$declared[$path];
        }

        $sql = (string) file_get_contents($path);
        $out = [];
        $like = [];

        $cols = [];
        $table = null;
        $depth = 0;
        foreach (explode("\n", $sql) as $line) {
            $body = trim((string) preg_replace('/--.*$/u', '', $line));
            if ($body === '') {
                continue;
            }
            if ($table === null) {
                if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-z0-9_]+)\s*\(\s*(.*)$/i', $body, $m) === 1) {
                    $inner = rtrim(trim($m[2]), ';');
                    if ($inner === '') {
                        $table = $m[1];
                        $cols = [];
                        $depth = 1;
                        continue;
                    }
                    // 单行形式只有 `(LIKE 基表 INCLUDING ALL)` 这一种（审计分片表）；
                    // 外层正则已经吃掉了左括号，这里只认剩下的部分。
                    if (preg_match('/^\(?LIKE\s+([a-z0-9_]+)/i', $inner, $lk) === 1) {
                        $like[$m[1]] = strtolower($lk[1]);
                    }
                }
                continue;
            }

            $depth += substr_count($body, '(') - substr_count($body, ')');
            if (preg_match('/^\)/', $body) === 1 || $depth <= 0) {
                $out[$table] = $cols;
                $table = null;
                continue;
            }
            $stmt = rtrim($body, ',');
            // 表级约束：`PRIMARY KEY (a, b)` 让列非空；其余约束行不是列定义
            if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]*)\)/i', $stmt, $pk) === 1) {
                foreach (explode(',', $pk[1]) as $c) {
                    $c = trim($c);
                    if (isset($cols[$c])) {
                        $cols[$c]['not_null'] = true;
                    }
                }
                continue;
            }
            if (preg_match('/^(UNIQUE|FOREIGN\s+KEY|CHECK|CONSTRAINT|LIKE)\b/i', $stmt) === 1) {
                continue;
            }
            if (preg_match('/^([a-z0-9_]+)\s+(.+)$/i', $stmt, $m) !== 1) {
                continue;
            }
            $cols[$m[1]] = self::columnShape($m[2]);
        }

        foreach ($like as $derived => $base) {
            if (isset($out[$base])) {
                $out[$derived] = $out[$base];
            }
        }

        return self::$declared[$path] = $out;
    }

    /** schema.sql 里声明的表名（含派生表）。@return list<string> */
    public static function declaredTables(?string $file = null): array
    {
        return array_keys(self::declared($file));
    }

    /**
     * 库里一张表的真实列形状；表不存在返回 null（那是建表的活，不算形状漂移）。
     *
     * @return array<string, array{not_null: bool, default: ?string}>|null
     */
    public static function actual(string $table): ?array
    {
        $reg = Db::select('SELECT to_regclass(?) AS r', ['public.' . $table]);
        if (($reg[0]['r'] ?? null) === null) {
            return null;
        }

        $rows = Db::select(
            "SELECT a.attname AS name,
                    CASE WHEN a.attnotnull THEN 1 ELSE 0 END AS notnull,
                    COALESCE((SELECT pg_catalog.pg_get_expr(d.adbin, d.adrelid)
                                FROM pg_catalog.pg_attrdef d
                               WHERE d.adrelid = a.attrelid AND d.adnum = a.attnum), '') AS dflt
               FROM pg_catalog.pg_attribute a
              WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped
              ORDER BY a.attnum",
            ['public.' . $table]
        );

        $out = [];
        foreach ($rows as $row) {
            $def = trim((string) ($row['dflt'] ?? ''));
            $out[(string) $row['name']] = [
                'not_null' => ((int) ($row['notnull'] ?? 0)) === 1,
                'default' => $def === '' ? null : $def,
            ];
        }

        return $out;
    }

    /**
     * 按 schema.sql 收敛一张表（只收紧）。`$apply = false` 时只报告不动库。
     *
     * @return array{table: string, applied: list<string>, blocked: list<string>, absent: list<string>}
     */
    public static function reconcile(string $table, bool $apply = true): array
    {
        $declared = self::declared();

        return self::reconcileShape($table, $declared[$table] ?? [], $apply);
    }

    /**
     * 收敛判据的唯一落点：给一张表一份目标形状，算出并（可选）执行差异。
     *
     * 单独拆出来是为了让测试能拿一份伪造的目标形状驱动同一条判据 ——
     * 若测试自己再实现一遍「比形状 + 发 DDL」，它就测不到生产代码了。
     *
     * @param array<string, array{not_null: bool, default: ?string}> $want
     * @return array{table: string, applied: list<string>, blocked: list<string>, absent: list<string>}
     */
    public static function reconcileShape(string $table, array $want, bool $apply = true): array
    {
        $report = ['table' => $table, 'applied' => [], 'blocked' => [], 'absent' => []];
        if (!self::isIdentifier($table) || $want === []) {
            return $report;
        }
        $have = self::actual($table);
        if ($have === null) {
            return $report;
        }

        foreach ($want as $col => $target) {
            if (!self::isIdentifier($col)) {
                $report['blocked'][] = "{$table}.{$col}：列名不是合法标识符，不动";
                continue;
            }
            if (!isset($have[$col])) {
                $report['absent'][] = $col;
                continue;
            }
            $now = $have[$col];
            $needsDefault = $target['default'] !== null && !self::defaultMatches($target['default'], $now['default']);
            $needsNotNull = $target['not_null'] && !$now['not_null'];

            if (!$needsDefault && !$needsNotNull) {
                continue;
            }
            if ($needsNotNull && !self::columnIsEmptyOfNulls($table, $col)) {
                $report['blocked'][] = "{$table}.{$col}：库内存在 NULL 行，收紧会失败，先定回填值";
                continue;
            }
            if (!$apply) {
                $report['applied'][] = "{$table}.{$col}"
                    . ($needsDefault ? ' SET DEFAULT ' . $target['default'] : '')
                    . ($needsNotNull ? ($needsDefault ? ' + ' : ' ') . 'SET NOT NULL' : '');
                continue;
            }

            // 先补默认再收紧：`SET NOT NULL` 只对未来的 INSERT 生效，
            // 而未来的 INSERT 若没带这一列，仍要靠默认值才不撞 23502。
            if ($needsDefault) {
                $expr = self::defaultLiteral($target['default']);
                if ($expr === null) {
                    $report['blocked'][] = "{$table}.{$col}：schema.sql 的默认值不是可安全拼进 DDL 的字面量，跳过";
                    continue;
                }
                Db::statement('ALTER TABLE ' . self::quote($table) . ' ALTER COLUMN ' . self::quote($col) . ' SET DEFAULT ' . $expr);
            }
            if ($needsNotNull) {
                Db::statement('ALTER TABLE ' . self::quote($table) . ' ALTER COLUMN ' . self::quote($col) . ' SET NOT NULL');
            }
            $report['applied'][] = "{$table}.{$col}"
                . ($needsDefault ? ' SET DEFAULT ' . $target['default'] : '')
                . ($needsNotNull ? ($needsDefault ? ' + ' : ' ') . 'SET NOT NULL' : '');
        }

        return $report;
    }

    /**
     * 全站只读报告（不动库）。
     *
     * @return array{tables: int, drifted: int, blocked: list<string>, absent: list<string>, details: list<array>}
     */
    public static function report(): array
    {
        $declared = self::declared();
        $out = ['tables' => 0, 'drifted' => 0, 'blocked' => [], 'absent' => [], 'details' => []];
        foreach (array_keys($declared) as $table) {
            $r = self::reconcile($table, false);
            if ($r['applied'] === [] && $r['blocked'] === [] && $r['absent'] === []) {
                continue;
            }
            $out['tables']++;
            $out['drifted'] += count($r['applied']);
            $out['blocked'] = array_merge($out['blocked'], $r['blocked']);
            $out['absent'] = array_merge($out['absent'], array_map(
                static fn(string $c): string => "{$table}.{$c}",
                $r['absent']
            ));
            $out['details'][] = $r;
        }

        return $out;
    }

    /**
     * 当前库里仍然漂移的列（表 => 列），只读。
     *
     * @return array<string, list<string>>
     */
    public static function driftedColumns(): array
    {
        $declared = self::declared();
        $out = [];
        foreach (array_keys($declared) as $table) {
            $r = self::reconcile($table, false);
            if ($r['applied'] !== []) {
                $out[$table] = array_map(
                    static fn(string $line): string => explode('.', explode(' ', $line)[0])[1],
                    $r['applied']
                );
            }
            foreach ($r['blocked'] as $line) {
                $pair = explode('：', $line)[0];
                [$t, $c] = explode('.', $pair, 2);
                $out[$t][] = $c;
            }
        }
        foreach ($out as $t => $cols) {
            $out[$t] = array_values(array_unique($cols));
        }

        return $out;
    }

    /**
     * 分片表回到基表：`kode_audit_logs_00` 的形状由 `(LIKE kode_audit_logs INCLUDING ALL)`
     * 派生，所以它的欠账记在基表名下（否则每扩一片就多一份要维护的清单）。
     *
     * 只在「去掉尾号后的名字自己也是 schema.sql 声明的表」时才折叠 ——
     * 真实表名恰好长成 `_00` 而并非派生表时保持原样，不吞掉它的漂移。
     */
    public static function baseTableOf(string $table, ?string $file = null): string
    {
        if (preg_match('/^(.+)_[0-9]{2}$/', $table, $m) !== 1) {
            return $table;
        }
        $declared = self::declared($file);

        return isset($declared[$m[1]]) ? $m[1] : $table;
    }

    /** 从一列定义尾部解出 not_null / default。@return array{not_null: bool, default: ?string} */
    private static function columnShape(string $rest): array
    {
        $notNull = preg_match('/\bNOT\s+NULL\b/i', $rest) === 1;
        $default = null;
        if (preg_match('/\bDEFAULT\s+(.+)$/i', $rest, $m) === 1) {
            // 尾部的 `NOT NULL` / `NULL` 属于约束而不是默认值的一部分
            $default = trim((string) preg_replace('/\s+(NOT\s+NULL|NULL)$/i', '', $m[1]));
        }
        // SERIAL/BIGSERIAL 本身就是 nextval 默认 + 非空；内联 PRIMARY KEY 也蕴含非空
        if (preg_match('/\b(BIG)?SERIAL\b/i', $rest) === 1) {
            $notNull = true;
            $default = 'nextval';
        }
        if (preg_match('/\bPRIMARY\s+KEY\b/i', $rest) === 1) {
            $notNull = true;
        }

        return ['not_null' => $notNull, 'default' => $default === '' ? null : $default];
    }

    /** 声明的默认值与库里的默认值是否等价（忽略大小写、空白与类型转换噪声）。 */
    private static function defaultMatches(string $want, ?string $have): bool
    {
        if ($have === null) {
            return false;
        }
        $norm = static function (string $s): string {
            $s = strtolower(trim($s));
            $s = (string) preg_replace('/::\s*[a-z_ ]+(\(\d+(?:,\s*\d+)?\))?/', '', $s);
            return (string) preg_replace('/\s+/', '', $s);
        };
        $w = $norm($want);
        $h = $norm($have);
        if ($w === 'nextval') {
            return str_starts_with($h, 'nextval(');
        }
        if ($w === 'now()' || $w === 'current_timestamp') {
            return str_starts_with($h, 'now(') || str_starts_with($h, 'current_timestamp');
        }

        return $w === $h;
    }

    /**
     * 把 schema.sql 里的默认值文本变成可拼进 DDL 的字面量。
     *
     * 来源是仓库内的固定文件，但仍是「文本进 DDL」，故按字符集白名单拒掉一切带
     * 分号 / 注释 / 反斜杠的形状，而不是信任它。函数默认（`NOW()`）在这里是合法的，
     * 而 `kode/database` 的 `Column::setDefault()` 会给字符串加引号（把 `now()` 变成
     * `'now()'`），所以这一类只能走原生 DDL。
     */
    private static function defaultLiteral(string $expr): ?string
    {
        $trim = trim($expr);
        if ($trim === '' || preg_match('/^[A-Za-z0-9_.\'"()\\-+,: ]+$/', $trim) !== 1) {
            return null;
        }
        if (str_contains($trim, '--') || str_contains($trim, '/*') || str_contains($trim, ';')) {
            return null;
        }

        return $trim === 'nextval' ? null : $trim;
    }

    /**
     * 这一列是否已经「一行 NULL 都没有」。表是空表也算没有。
     *
     * 探测走读连接（`DB_READ_WRITE_SPLIT` 下可能是从库），所以理论上会被复制延迟骗过 ——
     * 那不要紧：`SET NOT NULL` 由主库自己校验，延迟只会让报错出现得更早，不会静默放行。
     */
    private static function columnIsEmptyOfNulls(string $table, string $col): bool
    {
        try {
            $rows = Db::select(
                'SELECT 1 AS hit FROM ' . self::quote($table) . ' WHERE ' . self::quote($col) . ' IS NULL LIMIT 1'
            );
        } catch (\Throwable) {
            // 读不动就当有 NULL：宁可只报告不动库，也不要在收紧时撞 23502 之外再撞一次锁
            return false;
        }

        return $rows === [];
    }

    private static function isIdentifier(string $name): bool
    {
        return preg_match('/^[a-z_][a-z0-9_]*$/', $name) === 1;
    }

    private static function quote(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    public static function schemaSqlPath(): string
    {
        return dirname(__DIR__, 2) . '/database/sql/schema.sql';
    }
}
