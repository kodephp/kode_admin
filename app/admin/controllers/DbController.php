<?php

declare(strict_types=1);

namespace app\admin\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;
use Psr\Http\Message\ResponseInterface;
use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use app\support\Clip;

/**
 * 数据库管理（只读浏览 + 受限 SQL 查询，仅超管）：
 * 查表/列/分页行 + 单条 SELECT/WITH 查询（禁分号多语句，自动 LIMIT 200）。
 *
 * 支持多数据库：通过 ?connection= 参数指定目标连接，默认用主库。
 */
final class DbController extends Controller
{
    use ApiResponse;
    use PageWindow;

    /**
     * GET /api/databases — 列出所有已注册的数据库连接。
     */
    public function connections()
    {
        $conns = [];
        $cfg = (array) config('database.connections', []);
        $default = (string) config('database.default', 'pgsql');

        foreach (array_keys($cfg) as $name) {
            $status = 'unknown';
            try {
                Db::connection((string) $name)->select('SELECT 1');
                $status = 'ok';
            } catch (\Throwable) {
                $status = 'error';
            }
            $conns[] = [
                'name' => (string) $name,
                'is_default' => $name === $default,
                'status' => $status,
            ];
        }

        // 也列出动态注册的分片连接
        $shardCfg = (array) config('sharding', []);
        if (!empty($shardCfg['enabled'])) {
            $dbCount = max(1, (int) ($shardCfg['db_count'] ?? 2));
            for ($i = 0; $i < $dbCount; $i++) {
                $name = sprintf('shard_%02d', $i);
                $status = 'unknown';
                try {
                    Db::connection($name)->select('SELECT 1');
                    $status = 'ok';
                } catch (\Throwable) {
                    $status = 'error';
                }
                $conns[] = [
                    'name' => $name,
                    'is_default' => false,
                    'status' => $status,
                    'is_shard' => true,
                ];
            }
        }

        return $this->ok($conns);
    }

    /**
     * GET /api/databases/{connection}/tables — 列出指定连接的所有表。
     *
     * 增强：附行数（估算）、总大小、索引数、最后分析时间。
     * 用 pg_stat_user_tables 关联 pg_relation_size / pg_indexes。
     * 说明：n_live_tup / n_dead_tup 是 ANALYZE 后的估算值（非精确 count）。
     */
    public function tables()
    {
        $connName = $this->connectionName();
        if ($connName === null) {
            return $this->badConnection();
        }
        $db = Db::connection($connName);
        $rows = $db->select(
            "SELECT
                c.relname AS name,
                COALESCE(s.n_live_tup, 0) AS live_rows,
                COALESCE(s.n_dead_tup, 0) AS dead_rows,
                (
                    COALESCE(pg_total_relation_size(c.oid), 0)
                    + COALESCE(pg_indexes_size(c.oid), 0)
                ) AS total_bytes,
                COALESCE(ix.idx_count, 0) AS index_count,
                s.last_analyze,
                s.last_autoanalyze,
                s.last_vacuum,
                c.relispartition AS is_partition
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid
            LEFT JOIN (
                SELECT tablename, COUNT(*) AS idx_count
                FROM pg_indexes
                WHERE schemaname = 'public'
                GROUP BY tablename
            ) ix ON ix.tablename = c.relname
            WHERE n.nspname = 'public'
              AND c.relkind = 'r'
            ORDER BY c.relname"
        );

        // 人类可读大小
        foreach ($rows as &$row) {
            $row['total_bytes'] = (int) $row['total_bytes'];
            $row['total_human'] = self::bytesHuman((int) $row['total_bytes']);
        }
        unset($row);

        return $this->ok($rows);
    }

    /**
     * GET /api/db/tables/{table}/indexes — 指定表的索引详情 + 使用统计。
     *
     * 用途：识别未使用索引（idx_scan=0 且非唯一约束）、超大索引（占用存储）。
     */
    public function indexes()
    {
        $connName = $this->connectionName();
        if ($connName === null) {
            return $this->badConnection();
        }
        $table = $this->table();
        if ($table === null) {
            return $this->fail('表不存在', 404);
        }
        $db = Db::connection($connName);
        $rows = $db->select(
            "SELECT
                i.relname AS index_name,
                ix.indisunique AS is_unique,
                ix.indisprimary AS is_primary,
                ix.indisvalid AS is_valid,
                ix.indkey::text AS indkey,
                pg_relation_size(i.oid) AS size_bytes,
                COALESCE(s.idx_scan, 0) AS scan_count,
                COALESCE(s.idx_tup_read, 0) AS tup_read,
                COALESCE(s.idx_tup_fetch, 0) AS tup_fetch
            FROM pg_index ix
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN pg_class t ON t.oid = ix.indrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            LEFT JOIN pg_stat_user_indexes s ON s.indexrelid = i.oid
            WHERE t.relname = ? AND n.nspname = 'public'
            ORDER BY i.relname",
            [$table]
        );
        foreach ($rows as &$row) {
            $row['size_bytes'] = (int) $row['size_bytes'];
            $row['size_human'] = self::bytesHuman((int) $row['size_bytes']);
            $row['is_unique'] = (bool) $row['is_unique'];
            $row['is_primary'] = (bool) $row['is_primary'];
            $row['is_valid'] = (bool) $row['is_valid'];
        }
        unset($row);

        return $this->ok($rows);
    }

    /**
     * GET /api/db/tables/{table}/constraints — 约束详情（PK/FK/UQ/CHECK）。
     */
    public function constraints()
    {
        $connName = $this->connectionName();
        if ($connName === null) {
            return $this->badConnection();
        }
        $table = $this->table();
        if ($table === null) {
            return $this->fail('表不存在', 404);
        }
        $db = Db::connection($connName);
        $rows = $db->select(
            "SELECT
                con.conname AS name,
                con.contype AS type,
                CASE con.contype
                    WHEN 'p' THEN 'PRIMARY KEY'
                    WHEN 'f' THEN 'FOREIGN KEY'
                    WHEN 'u' THEN 'UNIQUE'
                    WHEN 'c' THEN 'CHECK'
                    WHEN 'x' THEN 'EXCLUSION'
                    ELSE con.contype
                END AS type_label,
                pg_get_constraintdef(con.oid) AS definition,
                con.convalidated AS is_validated
            FROM pg_constraint con
            JOIN pg_class t ON t.oid = con.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE t.relname = ? AND n.nspname = 'public'
            ORDER BY con.contype, con.conname",
            [$table]
        );
        foreach ($rows as &$row) {
            $row['is_validated'] = (bool) $row['is_validated'];
        }
        unset($row);

        return $this->ok($rows);
    }

    /**
     * GET /api/db/top-tables — 按总大小降序的 Top N 表。
     *
     * 用途：识别大表（占用存储）、dead-tuple 占比异常（VACUUM 需求）。
     */
    public function topTables()
    {
        $connName = $this->connectionName();
        if ($connName === null) {
            return $this->badConnection();
        }
        $limit = min(50, max(1, (int) $this->query('limit', 20)));
        $db = Db::connection($connName);
        $rows = $db->select(
            "SELECT
                c.relname AS name,
                (
                    COALESCE(pg_total_relation_size(c.oid), 0)
                    + COALESCE(pg_indexes_size(c.oid), 0)
                ) AS total_bytes,
                COALESCE(s.n_live_tup, 0) AS live_rows,
                COALESCE(s.n_dead_tup, 0) AS dead_rows,
                s.last_vacuum,
                s.last_autovacuum,
                s.last_analyze
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid
            WHERE n.nspname = 'public' AND c.relkind = 'r'
            ORDER BY total_bytes DESC
            LIMIT ?",
            [$limit]
        );
        foreach ($rows as &$row) {
            $row['total_bytes'] = (int) $row['total_bytes'];
            $row['total_human'] = self::bytesHuman((int) $row['total_bytes']);
            $live = (int) $row['live_rows'];
            $dead = (int) $row['dead_rows'];
            $row['dead_ratio'] = $live + $dead > 0 ? round($dead / ($live + $dead) * 100, 2) : 0.0;
        }
        unset($row);

        return $this->ok($rows);
    }

    /**
     * GET /api/db/activity — 当前活跃会话（pg_stat_activity 精简版）。
     *
     * 用途：定位长事务、等待锁的会话、闲置连接。
     * 安全：不返回 SQL 全文（截断 200 字符），避免敏感查询语句外泄。
     */
    public function activity()
    {
        $connName = $this->connectionName();
        if ($connName === null) {
            return $this->badConnection();
        }
        $db = Db::connection($connName);
        $rows = $db->select(
            "SELECT
                pid,
                usename,
                application_name,
                state,
                wait_event_type,
                wait_event,
                now() - xact_start AS xact_duration,
                now() - query_start AS query_duration,
                now() - backend_start AS backend_duration,
                LEFT(query, 200) AS query,
                client_addr::text AS client_addr
            FROM pg_stat_activity
            WHERE state IS NOT NULL
              AND pid <> pg_backend_pid()
            ORDER BY query_start DESC NULLS LAST
            LIMIT 100"
        );
        foreach ($rows as &$row) {
            $row['query'] = (string) ($row['query'] ?? '');
            $row['query_duration_ms'] = self::intervalMs($row['query_duration'] ?? null);
            $row['xact_duration_ms'] = self::intervalMs($row['xact_duration'] ?? null);
            $row['backend_duration_ms'] = self::intervalMs($row['backend_duration'] ?? null);
            unset($row['query_duration'], $row['xact_duration'], $row['backend_duration']);
        }
        unset($row);

        return $this->ok($rows);
    }

    /** DateInterval → 总毫秒（format('%e') 是天数不是秒，勿再用于时长换算）。 */
    private static function intervalMs(mixed $interval): int
    {
        if (!$interval instanceof \DateInterval) {
            return 0;
        }

        return ((int) $interval->days * 86400 + (int) $interval->h * 3600
            + (int) $interval->i * 60 + (int) $interval->s) * 1000
            + (int) round($interval->f * 1000);
    }

    /**
     * GET /api/db/locks — 当前锁状态（pg_locks 精简版，仅显示被阻塞或有等待的锁）。
     *
     * 用途：定位锁等待链，识别长事务阻塞问题。
     */
    public function locks()
    {
        $connName = $this->connectionName();
        if ($connName === null) {
            return $this->badConnection();
        }
        $db = Db::connection($connName);
        $rows = $db->select(
            "SELECT
                l.pid,
                a.usename,
                l.locktype,
                l.mode,
                l.granted,
                l.wait_event IS NOT NULL AS is_waiting,
                a.state,
                l.relation::regclass::text AS relation
            FROM pg_locks l
            JOIN pg_stat_activity a ON a.pid = l.pid
            WHERE l.pid <> pg_backend_pid()
              AND (NOT l.granted OR l.wait_event IS NOT NULL OR l.mode LIKE '%Exclusive')
            ORDER BY l.granted DESC, l.pid
            LIMIT 100"
        );
        foreach ($rows as &$row) {
            $row['is_waiting'] = (bool) $row['is_waiting'];
            $row['granted'] = (bool) $row['granted'];
        }
        unset($row);

        return $this->ok($rows);
    }

    /**
     * GET /api/db/stats — 当前连接所在数据库的总大小 + 各连接规模。
     *
     * 用途：容量规划、异常增长告警前的基线数据。
     */
    public function stats()
    {
        $conns = [];
        $cfg = (array) config('database.connections', []);
        foreach (array_keys($cfg) as $name) {
            $name = (string) $name;
            $status = 'error';
            $size = 0;
            $dbCount = 0;
            try {
                $db = Db::connection($name);
                $dbName = (string) ($db->select('SELECT CURRENT_DATABASE()')[0]['current_database'] ?? '');
                $sizeRow = $db->select('SELECT pg_database_size(current_database()) AS size');
                $size = (int) ($sizeRow[0]['size'] ?? 0);
                $tablesRow = $db->select(
                    "SELECT COUNT(*) AS c FROM pg_tables WHERE schemaname = 'public'"
                );
                $dbCount = (int) ($tablesRow[0]['c'] ?? 0);
                $status = 'ok';
            } catch (\Throwable) {
                $dbName = '';
            }
            $conns[] = [
                'name' => $name,
                'database' => $dbName,
                'status' => $status,
                'total_bytes' => $size,
                'total_human' => self::bytesHuman($size),
                'table_count' => $dbCount,
            ];
        }
        // 加默认连接（避免配置遗漏）
        $default = (string) config('database.default', 'pgsql');
        $names = array_column($conns, 'name');
        if (!in_array($default, $names, true)) {
            $conns[] = [
                'name' => $default,
                'database' => '',
                'status' => 'unknown',
                'total_bytes' => 0,
                'total_human' => '-',
                'table_count' => 0,
            ];
        }

        return $this->ok($conns);
    }

    /** 字节转人类可读（B/KB/MB/GB/TB）。 */
    private static function bytesHuman(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $i = -1;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return round($v, 2) . ' ' . $units[$i];
    }

    /**
     * GET /api/db/tables/{table} — 列出指定表的列信息。
     */
    public function columns()
    {
        $connName = $this->connectionName();
        if ($connName === null) {
            return $this->badConnection();
        }
        $table = $this->table();
        if ($table === null) {
            return $this->fail('表不存在', 404);
        }
        $db = Db::connection($connName);
        $rows = $db->select(
            'SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position',
            [$table]
        );

        return $this->ok($rows);
    }

    /**
     * GET /api/db/tables/{table}/rows — 分页查询表数据。
     */
    public function rows()
    {
        $connName = $this->connectionName();
        if ($connName === null) {
            return $this->badConnection();
        }
        $table = $this->table();
        if ($table === null) {
            return $this->fail('表不存在', 404);
        }
        // 表格浏览按屏显示，单页上限放宽到 200（口径同 PageWindow，只是显式传更大的 cap）。
        $win = $this->pageWindow($this->query('page'), $this->query('size'), 200);
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];
        $db = Db::connection($connName);

        $total = (int) ($db->select("SELECT COUNT(*) AS c FROM {$table}")[0]['c'] ?? 0);
        $rows = $db->select("SELECT * FROM {$table} ORDER BY 1 DESC LIMIT ? OFFSET ?", [$size, $offset]);
        $rows = $this->clipCells($rows);

        return $this->page($rows, $total, $page, $size);
    }

    /**
     * POST /api/db/query — 执行受限 SELECT 查询。
     *
     * 三层收敛，缺一不可（每层都实测过）：
     *  1. 语句屏幕：单条 SELECT/WITH + 服务端能力函数拦截。只读事务挡不住
     *     `lo_import`：实测本机 `SELECT lo_import('/etc/hosts')` + `lo_get()`
     *     真读回 1510 字节，`lo_export` 还能往磁盘写；`pg_read_file()` 直接读到
     *     /etc/passwd（9344 字节）。这些函数名在 PG 里不能被拼接出来（函数名不是
     *     表达式），所以摘掉字面量/注释后按词匹配即可封死 —— 但引用标识符必须保留
     *     内容，`"pg_read_file"()` 同样是真的函数调用（实测同 /etc/hosts 1510 字节）。
     *  2. READ ONLY 事务：写/DDL 由服务端拒绝（实测 CREATE TABLE 报 25006），
     *     且控制台永远 rollback，不提交。
     *  3. statement_timeout + 外层 LIMIT 200：`SELECT pg_sleep(60)` 与
     *     `LIMIT 999999` 的笛卡尔积各只能占住 worker 5 秒 / 200 行。
     */
    public function sql()
    {
        $connName = $this->connectionName();
        if ($connName === null) {
            return $this->badConnection();
        }
        $data = $this->validate(Request::all(), ['sql' => 'required|min:8|max:2000']);
        $sql = trim((string) $data['sql']);

        // 引号内不算关键字：旧实现拿原文匹配，`WHERE method = 'DELETE'` 会被误判成写语句
        $screen = self::stripSqlLiterals($sql);
        if ($screen === null) {
            return $this->fail('引号或注释不成对，无法安全判定语句类型', 400);
        }
        if (!preg_match('/^(SELECT|WITH)\b/i', $screen)) {
            return $this->fail('仅允许 SELECT/WITH 查询', 400);
        }
        if (str_contains($screen, ';')) {
            return $this->fail('禁止多语句', 400);
        }
        foreach (self::SQL_FORBIDDEN_WORDS as $kw) {
            if (preg_match('/\b' . $kw . '\b/i', $screen)) {
                return $this->fail("禁止 {$kw} 关键字", 400);
            }
        }
        foreach (self::SQL_FORBIDDEN_FUNCTIONS as $fn) {
            if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $screen)) {
                return $this->fail("禁止调用函数 {$fn}()", 400);
            }
        }

        $db = Db::connection($connName);
        $wrapped = 'SELECT * FROM (' . $sql . ') AS kode_sql_console LIMIT ' . self::SQL_MAX_ROWS;
        try {
            $db->beginTransaction();
            $db->raw('SET TRANSACTION READ ONLY');
            $db->raw('SET LOCAL statement_timeout = ' . self::SQL_TIMEOUT_MS);
            $rows = $db->select($wrapped);
        } catch (\Throwable $e) {
            return $this->fail('查询失败：' . Clip::chars($e->getMessage(), 200), 400);
        } finally {
            // 控制台只读：无论成败都回滚（SET LOCAL 随事务消失，不污染池里的连接）
            try {
                $db->rollBack();
            } catch (\Throwable) {
            }
        }
        $rows = $this->clipCells($rows);

        return $this->ok($rows);
    }

    /**
     * 出参格子的展示宽度：表格浏览与 SQL 控制台两条腿共用（口径只能有一份）。
     *
     * 旧写法 `substr($v, 0, 2000)` 按**字节**切，中文单元格被切成非法 UTF-8，而
     * `kode/http` 的 json_encode 带 `JSON_THROW_ON_ERROR` —— 于是一个格子让**整份响应**
     * 编码失败（`JsonException: Malformed UTF-8 characters`），不是「这一列显示不全」。
     * 判据按字符，且只在真的截短时补省略号：`strlen > 2000` 是「字符数 > 2000」的必要
     * 条件（每字符至少一字节），所以短格永不进这条分支，长格里 ≤2000 字符的多字节值
     * 也不会被标上没发生过的截断。
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function clipCells(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach ($row as $k => $v) {
                if ($v instanceof \DateTimeInterface) {
                    $row[$k] = $v->format('Y-m-d H:i:s');
                } elseif (is_resource($v)) {
                    $row[$k] = '[binary]';
                } elseif (is_string($v) && strlen($v) > self::CELL_MAX_CHARS) {
                    $cut = Clip::chars($v, self::CELL_MAX_CHARS);
                    if ($cut !== $v) {
                        $row[$k] = $cut . '…';
                    }
                }
            }
        }
        unset($row);

        return $rows;
    }

    /** 单个格子的展示宽度（字符，不是字节）。 */
    private const CELL_MAX_CHARS = 2000;

    /** 返回给控制台的行数上限（也是内层 LIMIT 再大时的实际封顶）。 */
    private const SQL_MAX_ROWS = 200;

    /** 单条查询最长执行时间（毫秒），到点由服务端取消。 */
    private const SQL_TIMEOUT_MS = 5000;

    /** 写类关键字（沿用原口径，作用在摘掉字面量之后的文本上）。 */
    private const SQL_FORBIDDEN_WORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE',
        'TRUNCATE', 'GRANT', 'COPY', 'VACUUM',
    ];

    /**
     * 服务端能力函数：文件读写、进程/会话操控、跨库出网、会话参数改写。
     * READ ONLY 事务挡不住其中任何一条，必须在执行前按名字拦。
     */
    private const SQL_FORBIDDEN_FUNCTIONS = [
        // 文件系统
        'pg_read_file', 'pg_read_binary_file', 'pg_stat_file', 'pg_ls_dir',
        'pg_ls_logdir', 'pg_ls_waldir', 'pg_ls_tmpdir',
        'lo_import', 'lo_export', 'lo_get', 'lo_put', 'lo_open', 'loread',
        'lowrite', 'lo_lseek', 'lo_tell', 'lo_creat', 'lo_create', 'lo_unlink',
        // 进程 / 会话 / 复制控制
        'pg_sleep', 'pg_sleep_for', 'pg_sleep_until',
        'pg_cancel_backend', 'pg_terminate_backend', 'pg_reload_conf',
        'pg_switch_wal', 'pg_wal_replay_pause', 'pg_wal_replay_resume',
        'pg_create_restore_point', 'pg_drop_replication_slot', 'pg_sync_replication_slots',
        // 出网（dblink / fdw 取数）
        'dblink', 'dblink_exec', 'dblink_open', 'dblink_fetch', 'dblink_connect',
        // 会话参数：set_config(x, false) 能绕过本控制器的 statement_timeout
        'set_config', 'set_setting',
    ];

    /**
     * 摘掉 SQL 里的字符串字面量与注释，用于「语句类型」判定。
     *
     * 返回 null 表示引号/注释不成对：这种输入无法可靠判定，调用方必须直接拒绝
     * （不成对若按「后面都在字面量里」处理，等于把真正的代码放过屏幕）。
     * E'...' 反斜杠转义与 `$$` 美元引用一并处理：前者会错位配对，后者能整段吞掉检查。
     *
     * 单引号（字面量）整段摘掉，双引号（引用标识符）只脱引号保留内容：
     * 字面量当不了函数名，标识符却可以（见下）。代价是极少数怪名字会被误判，
     * 比如列 `"delete"`、`"a;b"` —— 方向是「拒绝」而不是「放行」，可接受。
     */
    private static function stripSqlLiterals(string $sql): ?string
    {
        $out = '';
        $len = strlen($sql);
        $i = 0;
        $commentDepth = 0;

        while ($i < $len) {
            $ch = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($commentDepth > 0) {
                // PostgreSQL 块注释可嵌套
                if ($ch === '/' && $next === '*') {
                    $commentDepth++;
                    $i += 2;
                    continue;
                }
                if ($ch === '*' && $next === '/') {
                    $commentDepth--;
                    $i += 2;
                    if ($commentDepth === 0) {
                        $out .= ' ';
                    }
                    continue;
                }
                $i++;
                continue;
            }

            if ($ch === '-' && $next === '-') {
                $eol = strpos($sql, "\n", $i);
                $i = $eol === false ? $len : $eol;
                $out .= ' ';
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $commentDepth = 1;
                $i += 2;
                continue;
            }

            // 美元引用字符串：$$ ... $$ 或 $tag$ ... $tag$（定界符 = 首尾各一个 `$`，
            // 尾部多写一个 `\$\$` 会让本分支永远匹配不上，`$$ DROP TABLE x $$` 会整段漏进屏幕）
            if ($ch === '$' && preg_match('/\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/A', substr($sql, $i), $m)) {
                $tag = $m[0];
                $end = strpos($sql, $tag, $i + strlen($tag));
                if ($end === false) {
                    return null;
                }
                $out .= "''";
                $i = $end + strlen($tag);
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                // E'...' 里反斜杠转义生效（普通串在 standard_conforming_strings=on 下不生效）
                $isEscapeString = $ch === "'"
                    && $i > 0
                    && ($sql[$i - 1] === 'e' || $sql[$i - 1] === 'E')
                    && ($i < 2 || !ctype_alnum($sql[$i - 2]) && $sql[$i - 2] !== '_');
                $quote = $ch;
                $j = $i + 1;
                $closed = false;
                while ($j < $len) {
                    if ($isEscapeString && $sql[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($sql[$j] === $quote) {
                        if (($sql[$j + 1] ?? '') === $quote) {
                            $j += 2;
                            continue;
                        }
                        $closed = true;
                        $j++;
                        break;
                    }
                    $j++;
                }
                if (!$closed) {
                    return null;
                }
                if ($ch === "'") {
                    $out .= "''";
                } else {
                    // 引用标识符要还原内容、只摘引号。整段擦成 `""` 会被绕过：
                    // `SELECT length("pg_read_file"('/etc/hosts'))` 在 PG 里是**真调用**
                    // （实测 1510 字节），函数名字面上带引号也照样解析。
                    $out .= str_replace('""', '"', substr($sql, $i + 1, $j - $i - 2));
                }
                $i = $j;
                continue;
            }

            $out .= $ch;
            $i++;
        }

        return $commentDepth > 0 ? null : $out;
    }

    /**
     * 当前请求的目标数据库连接名；未注册的连接返回 null（调用方须拒绝）。
     *
     * 绝不能凭名字直连：Db::getConnection() 对未知名字会静默回落默认配置
     * （vendor/kode/database/src/Db/Db.php:528 `$connections[$name] ?? $config`），
     * 于是 `?connection=anything` 拿着主库凭证建了一条真连接并永久塞进
     * Db::$connectionCache[$name]（worker 存活期内不回收）。实测 12 个随意名字
     * 让 kode_app 的后端数从 2 涨到 14 且永不下降 —— 每个 key 由请求方指定，
     * 11 个 worker 各被这样打满几十次就能耗尽 max_connections（本机 100），
     * 拖死整个后台；同时面板显示的「连接」是假的，读的实际全是主库。
     */
    private function connectionName(): ?string
    {
        $conn = (string) $this->query('connection', '');
        if ($conn === '') {
            return (string) config('database.default', 'pgsql');
        }
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $conn) || !Db::hasConnection($conn)) {
            return null;
        }
        return $conn;
    }

    /** 未注册连接的拒绝响应（消息里给出可选值，便于运维自查）。 */
    private function badConnection(): ResponseInterface
    {
        $allowed = implode(', ', array_keys(Db::getConnections()));
        return $this->fail('未知数据库连接，可选：' . $allowed, 400);
    }

    /**
     * 表名验证：白名单格式（允许非 kode_ 前缀）。
     */
    private function table(): ?string
    {
        $table = (string) $this->param('table', '');
        if (preg_match('/^[a-z][a-z0-9_]{0,64}$/', $table) !== 1) {
            return null;
        }
        $connName = $this->connectionName();
        if ($connName === null) {
            return null;
        }
        $db = Db::connection($connName);
        $exists = $db->select(
            "SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND tablename = ? LIMIT 1",
            [$table]
        );

        return $exists === [] ? null : $table;
    }
}
