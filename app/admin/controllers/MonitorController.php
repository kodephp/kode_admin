<?php

declare(strict_types=1);

namespace app\admin\controllers;

use Kode\Framework\Http\Controller;
use Kode\Database\Db\Db;
use app\concerns\ApiResponse;
use app\support\AuditTrail;
use app\support\Clip;
use app\support\DbReadFace;
use app\support\Shard;

/**
 * 服务器监控：负载/CPU/内存/磁盘/PHP/应用信息（只读快照）。
 */
final class MonitorController extends Controller
{
    use ApiResponse;

    /**
     * 分片总览：各基表分片分布 + 行数（拓扑变更需改环境并重启，此处只读）。
     *
     * 三处口径此前各自为政，面板因此在它唯一被用来排障的时刻给出错答案：
     * - 拓扑读 `config('sharding')` 而片清单读 `Shard::tables()`，热配置扩过片之后
     *   同一屏里会写「表分片数 2」并列 4 张表（片数只从 `Shard::config()` 读，AGENTS §4）；
     * - 只扫默认连接，跨库基表的行数是「少几个库」的和，不是各库合计；
     * - 每张表一个空 `catch (\Throwable) {}`，缺片表现为 `rows: null` 而 `total` 照常回 200。
     *
     * 现在片清单来自 `Shard::readUnits()`、派发来自 `Shard::scan()`：读不出来的片带原因回来，
     * `complete` 明说这份总数是全口径还是部分口径。
     */
    public function shards()
    {
        $cfg = Shard::config();
        $out = [
            'topology' => [
                'enabled' => (bool) $cfg['enabled'],
                'db_count' => (int) $cfg['db_count'],
                'table_shards' => (int) $cfg['table_shards'],
                'db_name' => (string) $cfg['db_name'],
                'read_write_split' => (bool) config('database.read_write_split', false),
            ],
            'bases' => [],
        ];

        // 基表清单与「哪一维分片」都问既有口径：审计只在默认连接上分表且关掉开关仍落片表，
        // SHARDED_BASES 里的基表是库×表双维、未分片时落基表。
        $units = Shard::readUnits(AuditTrail::BASE, false, false);
        $out['bases'][] = $this->shardTotals(AuditTrail::BASE, $units);
        foreach (Shard::SHARDED_BASES as $base) {
            $out['bases'][] = $this->shardTotals($base, Shard::readUnits($base));
        }

        return $this->ok($out);
    }

    /**
     * 一片一张表的行数：逐片 COUNT 走唯一派发口，缺片如实带回。
     *
     * 标签（连接/库序号/表名）由**回调自己带回**，不按结果下标回查 `$units` ——
     * `Shard::scan()` 只在读成功时追加一条结果，中间少一条腿就会让后面的结果整体错位一格，
     * 于是「shard_00.notices_00 有 3 行」其实是 shard_01 的数字。
     *
     * @param list<array{0: string|null, 1: string, 2: int, 3: int}> $units
     * @return array{base: string, total: int, complete: bool, tables: list<array<string, mixed>>, unreadable_shards: list<array<string, string>>}
     */
    private function shardTotals(string $base, array $units): array
    {
        $scan = Shard::scan($units, static function (?string $conn, string $table, int $db) use ($base): array {
            return [
                // 坐标由 readUnits 原样带回，不要在展示侧反解 `shard_02` / `_01` 这种名字。
                'base'  => $base,
                'conn'  => $conn ?? 'default',
                'db'    => $db,
                'table' => $table,
                'rows'  => (int) (Shard::db($conn)->select("SELECT COUNT(*) AS c FROM {$table}")[0]['c'] ?? 0),
            ];
        });

        $total = 0;
        $tables = [];
        foreach ($scan['results'] as $piece) {
            $total += (int) $piece['rows'];
            $tables[] = $piece;
        }
        $unreadable = Shard::unreadableKey($scan['unreadable']);

        return [
            'base' => $base,
            'total' => $total,
            'complete' => $unreadable === [],
            'tables' => $tables,
            'unreadable_shards' => $unreadable,
        ];
    }

    public function overview()
    {
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0];
        $cpu = $this->cpuCount();
        $memTotal = $this->memTotal();
        $memAvail = $this->memAvailable();
        $diskTotal = @disk_total_space(base_path()) ?: 0;
        $diskFree = @disk_free_space(base_path()) ?: 0;

        return $this->ok([
            'load' => ['1m' => round((float) $load[0], 2), '5m' => round((float) $load[1], 2), '15m' => round((float) $load[2], 2)],
            'cpu' => ['cores' => $cpu],
            'memory' => [
                'total' => $memTotal,
                'available' => $memAvail,
                'used' => max(0, $memTotal - $memAvail),
                'usage' => $memTotal > 0 ? round((1 - $memAvail / $memTotal) * 100, 1) : 0,
            ],
            'disk' => [
                'total' => (int) $diskTotal,
                'free' => (int) $diskFree,
                'used' => (int) ($diskTotal - $diskFree),
                'usage' => $diskTotal > 0 ? round((1 - $diskFree / $diskTotal) * 100, 1) : 0,
            ],
            'app' => [
                'php' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'framework' => \Kode\Framework\Application::VERSION,
                'time' => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * GET /api/monitor/db-activity — pg_stat_activity 活跃查询。
     *
     * 出参恒为 `{rows, available, reason}`：这张表空着的两种原因是「真没有活跃查询」和
     * 「这座库没读到」，前者不用排障、后者必须去排障。
     */
    public function dbActivity()
    {
        try {
            $rows = Db::select(
                "SELECT pid, datname, usename, application_name, client_addr,
                        state, now() - query_start AS duration,
                        LEFT(query, 200) AS query
                 FROM pg_stat_activity
                 WHERE datname IS NOT NULL AND state != 'idle'
                 ORDER BY duration DESC
                 LIMIT 50"
            );

            $list = [];
            foreach ($rows as $row) {
                $list[] = [
                    'pid' => (int) $row['pid'],
                    'database' => (string) $row['datname'],
                    'user' => (string) $row['usename'],
                    'app' => (string) ($row['application_name'] ?? ''),
                    'client' => (string) ($row['client_addr'] ?? ''),
                    'state' => (string) $row['state'],
                    'duration' => (string) ($row['duration'] ?? ''),
                    'query' => (string) $row['query'],
                ];
            }

            return $this->ok(DbReadFace::readable(['rows' => $list]));
        } catch (\Throwable $e) {
            return $this->ok(['rows' => []] + $this->dbDegrade($e, 'db-activity'));
        }
    }

    /**
     * GET /api/monitor/db-statements — pg_stat_statements 慢查询 Top-N。
     *
     * 这里曾经是整屏唯一「把读不到藏起来」的一条：`catch (\Throwable) { return ok([]); }`。
     * 本机 `kode_app` 就没装 `pg_stat_statements`（实测 `pg_extension` 里 0 行、跑那条 SQL 得 42P01），
     * 于是这一页永远空白，而它唯一被点开的时刻恰恰是有人来查慢查询的时候 ——
     * 「这台系统没有慢查询」和「这台系统没开慢查询统计」是两个完全不同的结论。
     */
    public function dbStatements()
    {
        $limit = min(50, max(1, (int) $this->query('limit', 20)));

        try {
            $rows = Db::select(
                "SELECT LEFT(query, 200) AS query,
                        calls,
                        total_exec_time,
                        mean_exec_time,
                        rows,
                        shared_blks_hit,
                        shared_blks_read
                 FROM pg_stat_statements
                 ORDER BY total_exec_time DESC
                 LIMIT ?",
                [$limit]
            );

            $list = [];
            foreach ($rows as $row) {
                $list[] = [
                    'query' => (string) $row['query'],
                    'calls' => (int) $row['calls'],
                    'total_ms' => round((float) $row['total_exec_time'], 2),
                    'mean_ms' => round((float) $row['mean_exec_time'], 2),
                    'rows' => (int) $row['rows'],
                    'hits' => (int) $row['shared_blks_hit'],
                    'reads' => (int) $row['shared_blks_read'],
                ];
            }

            return $this->ok(DbReadFace::readable(['rows' => $list]));
        } catch (\Throwable $e) {
            return $this->ok(['rows' => []] + $this->dbDegrade($e, 'db-statements', 'pg_stat_statements'));
        }
    }

    /**
     * GET /api/monitor/connections — 连接池状态。
     *
     * 失败时三个数字回 null 而不是 0：`活跃连接 0 / 0` 会被读成「这台系统一个连接都没有」，
     * 而那与「查不到」是两种结论（口径同 §3 的聚合缺键规则）。
     */
    public function connections()
    {
        try {
            $state = Db::select(
                "SELECT state, COUNT(*) AS count
                 FROM pg_stat_activity
                 WHERE datname IS NOT NULL
                 GROUP BY state
                 ORDER BY count DESC"
            );

            $total = (int) (Db::select(
                "SELECT COUNT(*) AS c FROM pg_stat_activity WHERE datname IS NOT NULL"
            )[0]['c'] ?? 0);

            $max = (int) (Db::select(
                "SHOW max_connections"
            )[0]['max_connections'] ?? 0);

            return $this->ok(DbReadFace::readable([
                'total' => $total,
                'max' => $max,
                'usage' => $max > 0 ? round($total / $max * 100, 1) : 0,
                'by_state' => array_map(static fn(array $r): array => [
                    'state' => (string) $r['state'],
                    'count' => (int) $r['count'],
                ], $state),
            ]));
        } catch (\Throwable $e) {
            return $this->ok(
                ['total' => null, 'max' => null, 'usage' => null, 'by_state' => []]
                + $this->dbDegrade($e, 'connections')
            );
        }
    }

    /**
     * GET /api/monitor/db-locks — pg_locks 锁等待查询。
     *
     * 「无锁等待」与「读不到锁」必须分得开：前者是好消息，后者什么都没说。
     */
    public function dbLocks()
    {
        try {
            $rows = Db::select(
                "SELECT blocked_locks.pid AS blocked_pid,
                        blocked_activity.usename AS blocked_user,
                        blocked_activity.query AS blocked_query,
                        now() - blocked_activity.query_start AS blocked_duration,
                        blocking_locks.pid AS blocking_pid,
                        blocking_activity.usename AS blocking_user,
                        blocking_activity.query AS blocking_query
                 FROM pg_catalog.pg_locks AS blocked_locks
                 JOIN pg_catalog.pg_locks AS blocking_locks
                     ON blocked_locks.locktype = blocking_locks.locktype
                     AND blocked_locks.relation = blocking_locks.relation
                     AND blocked_locks.pid != blocking_locks.pid
                 JOIN pg_catalog.pg_stat_activity AS blocked_activity
                     ON blocked_locks.pid = blocked_activity.pid
                 JOIN pg_catalog.pg_stat_activity AS blocking_activity
                     ON blocking_locks.pid = blocking_activity.pid
                 ORDER BY blocked_duration DESC
                 LIMIT 20"
            );

            $list = [];
            foreach ($rows as $row) {
                $list[] = [
                    'blocked_pid' => (int) $row['blocked_pid'],
                    'blocked_user' => (string) $row['blocked_user'],
                    'blocked_query' => Clip::chars((string) $row['blocked_query'], 200),
                    'blocked_duration' => (string) ($row['blocked_duration'] ?? ''),
                    'blocking_pid' => (int) $row['blocking_pid'],
                    'blocking_user' => (string) $row['blocking_user'],
                    'blocking_query' => Clip::chars((string) $row['blocking_query'], 200),
                ];
            }

            return $this->ok(DbReadFace::readable(['rows' => $list]));
        } catch (\Throwable $e) {
            return $this->ok(['rows' => []] + $this->dbDegrade($e, 'db-locks'));
        }
    }

    /**
     * 数据库诊断读口的失败判定：只做转发，映射本身在 `app\support\DbReadFace`（那里有口径全文）。
     *
     * 入口留着是因为它有自己的回归（`tests/MonitorDbDiagnosticsTest.php` 逐档断结论与「不外漏」），
     * 也是这四个读口各自的调用点；把它删掉等于把文档口径挪到一个没人指的私有方法上。
     *
     * @see DbReadFace::reason()
     */
    public static function dbDegradeReason(\Throwable $e, ?string $extension = null): string
    {
        return DbReadFace::reason($e, $extension);
    }

    /**
     * 一次读失败的落地面：结论进响应，原文进日志。
     *
     * 「扩展没装/没装载」这一档不打日志的理由见 `DbReadFace::unreadable()`。
     *
     * @return array{available: false, reason: string}
     */
    private function dbDegrade(\Throwable $e, string $handler, ?string $extension = null): array
    {
        /** @var array{available: false, reason: string} $face */
        $face = DbReadFace::unreadable($e, $handler, [], $extension);

        return $face;
    }

    private function cpuCount(): int
    {
        if (is_file('/proc/cpuinfo')) {
            $n = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor');
            if ($n > 0) {
                return $n;
            }
        }
        $out = (string) @shell_exec('sysctl -n hw.ncpu 2>/dev/null');

        return max(1, (int) trim($out));
    }

    private function memTotal(): int
    {
        if (is_file('/proc/meminfo')) {
            foreach (explode("\n", (string) @file_get_contents('/proc/meminfo')) as $line) {
                if (str_starts_with($line, 'MemTotal:')) {
                    return (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT) * 1024;
                }
            }
        }
        $out = (string) @shell_exec('sysctl -n hw.memsize 2>/dev/null');

        return max(0, (int) trim($out));
    }

    private function memAvailable(): int
    {
        if (is_file('/proc/meminfo')) {
            foreach (explode("\n", (string) @file_get_contents('/proc/meminfo')) as $line) {
                if (str_starts_with($line, 'MemAvailable:')) {
                    return (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT) * 1024;
                }
            }
        }
        // macOS：vm_stat 空闲页估算。
        $out = (string) @shell_exec('vm_stat 2>/dev/null');
        if ($out !== '' && preg_match('/page size of (\d+) bytes/', $out, $m)) {
            $page = (int) $m[1];
            $free = 0;
            foreach (explode("\n", $out) as $line) {
                if (preg_match('/^(Pages free|Pages inactive|Pages speculative):\s*(\d+)/', trim($line), $mm)) {
                    $free += (int) $mm[2];
                }
            }

            return $free * $page;
        }

        return 0;
    }
}
