<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use app\concerns\RetentionGuard;
use app\support\Clip;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;

/**
 * 审计日志查询（超管）。
 */
final class AuditController extends Controller
{
    use ApiResponse;
    use PageWindow;
    use RetentionGuard;

    /** 基表名只写一份（写侧 = `AuditTrail::BASE`）：读侧另抄一遍字符串就会跟着写侧漂。 */
    private const BASE = \app\support\AuditTrail::BASE;

    /**
     * 导出行数天花板。**每片各取这么进行 PHP，再按 created_at 归并截一次**，
     * 所以「拿到的比给的多」是常态 —— 截断必须写进产物正文（见 export()），
     * 否则一份少一半行的 CSV 和一份完整的长得一模一样。
     */
    private const EXPORT_LIMIT = 5000;

    /** 对外 id 混淆（AGENTS.md §4）：id/user_id 均为 HashId 串。 */
    private function hashRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['id'] = \app\support\HashId::enc((int) $row['id']);
            $row['user_id'] = isset($row['user_id'])
                ? \app\support\HashId::enc((int) $row['user_id'])
                : null;
        }

        return $rows;
    }

    public function index()
    {
        // 归并型分页：每片各取 page*size 行进 PHP 再排序切片，所以行预算要按片数摊薄
        // （口径见 app\concerns\PageWindow，沿用 SQL 侧那个十万行上限会把 worker 撑成内存 fatal）。
        $units = \app\support\Shard::readUnits(self::BASE, false, false);
        $win = $this->mergedWindow($this->query('page'), $this->query('size'), count($units));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];
        $username = Clip::chars(trim((string) $this->query('username', '')), 64);
        $path = Clip::chars(trim((string) $this->query('path', '')), 255);
        $method = strtoupper(Clip::chars(trim((string) $this->query('method', '')), 10));
        $start = Clip::chars(trim((string) $this->query('start_time', '')), 19);
        $end = Clip::chars(trim((string) $this->query('end_time', '')), 19);
        $statusRaw = $this->query('status', null);        // 精确码（200）或百位类（2=2xx）。
        $status = null;
        $statusEnd = null;
        if ($statusRaw !== null && $statusRaw !== '') {
            $code = (int) $statusRaw;
            if ($code >= 2 && $code <= 5) {
                $status = $code * 100;
                $statusEnd = $code * 100 + 99;
            } elseif ($code >= 100 && $code <= 599) {
                $status = $code;
                $statusEnd = $code;
            }
        }
        $where = [];
        $params = [];
        if ($username !== '') {
            $where[] = 'username LIKE ?';
            $params[] = '%' . $username . '%';
        }
        if ($path !== '') {
            $where[] = 'path LIKE ?';
            $params[] = '%' . $path . '%';
        }
        if (in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            $where[] = 'method = ?';
            $params[] = $method;
        }
        if ($status !== null) {
            $where[] = 'status BETWEEN ? AND ?';
            $params[] = $status;
            $params[] = $statusEnd;
        }
        if ($start !== '' && strtotime($start) !== false) {
            $where[] = 'created_at >= ?';
            $params[] = date('Y-m-d H:i:s', strtotime($start));
        }
        if ($end !== '' && strtotime($end) !== false) {
            $where[] = 'created_at <= ?';
            $params[] = date('Y-m-d H:i:s', strtotime($end));
        }
        $cond = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        // 跨分片聚合：各片取 page*size 条（保证全局前 page 页不漏），内存归并再切片。
        // 逐片 try/catch continue 的旧写法会把读不动的一片吃成「这片没有数据」（total 跟着偏小），
        // 收敛到 Shard::scan：读不出来的片带原因进 `unreadable_shards`（AGENTS.md §3）。
        $total = 0;
        $merged = [];
        $need = $page * $size;
        $scan = \app\support\Shard::scan($units, static function (?string $conn, string $table) use ($cond, $params, $need): array {
            $handle = \app\support\Shard::db($conn);

            return [
                'total' => (int) ($handle->select("SELECT COUNT(*) AS c FROM {$table}{$cond}", $params)[0]['c'] ?? 0),
                'rows'  => $handle->select(
                    "SELECT id, user_id, username, method, path, status, duration_ms, ip, created_at
                     FROM {$table}{$cond} ORDER BY id DESC LIMIT ?",
                    [...$params, $need]
                ),
            ];
        });
        foreach ($scan['results'] as $piece) {
            $total += $piece['total'];
            foreach ($piece['rows'] as $row) {
                $merged[] = $row;
            }
        }
        usort($merged, static fn($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return $this->page(
            $this->hashRows(array_slice($merged, $offset, $size)),
            $total,
            $page,
            $size,
            ['unreadable_shards' => \app\support\Shard::unreadableKey($scan['unreadable'])]
        );
    }

    /**
     * 近 7 天审计量（跨分片聚合，供仪表盘趋势图）。
     *
     * 出参数组形状不能变（两处图表都在 stats.map）；收敛的是**行范围**：
     * 超管看全站，其他人只看自己触发的请求 —— 全站趋势本身就是审计内容
     * （哪些接口在被打、量级多大），和 /api/audits 列表同级。
     * 缺片的口径不动 `data` 形状，走信封同级的 `unreadable_shards`（读侧唯一派发口 Shard::scan）。
     */
    public function stats()
    {
        $payload = \app\support\AccountScope::payload();
        $onlyMine = \app\support\AccountScope::isAdmin($payload)
            ? null
            : \app\support\AccountScope::usernameOf($payload);
        $cond = "WHERE created_at >= NOW() - INTERVAL '6 days'";
        $params = [];
        if ($onlyMine !== null) {
            $cond .= ' AND username = ?';
            $params[] = $onlyMine;
        }
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $days[] = date('Y-m-d', strtotime("-{$i} days"));
        }
        $counts = array_fill_keys($days, 0);
        $scan = \app\support\Shard::scan(
            \app\support\Shard::readUnits(self::BASE, false, false),
            static function (?string $conn, string $table) use ($cond, $params): array {
                return \app\support\Shard::db($conn)->select(
                    "SELECT TO_CHAR(created_at, 'YYYY-MM-DD') AS d, COUNT(*) AS c
                     FROM {$table} {$cond} GROUP BY d",
                    $params
                );
            }
        );
        foreach ($scan['results'] as $rows) {
            foreach ($rows as $row) {
                $d = (string) ($row['d'] ?? '');
                if (isset($counts[$d])) {
                    $counts[$d] += (int) ($row['c'] ?? 0);
                }
            }
        }
        $out = [];
        foreach ($counts as $d => $c) {
            $out[] = ['day' => $d, 'count' => $c];
        }

        return $this->ok($out, 200, ['unreadable_shards' => \app\support\Shard::unreadableKey($scan['unreadable'])]);
    }

    /** 总览的聚合窗口：7 天。前端所有卡片与图表都是这个口径。 */
    private const OVERVIEW_WINDOW = "created_at >= NOW() - INTERVAL '7 days'";

    /** 参与跨片聚合的列（每片只把这七列投影进 UNION ALL）。 */
    private const OVERVIEW_COLUMNS = 'created_at, ip, username, method, path, status, duration_ms';

    /**
     * 审计总览（跨分片聚合）：总量/24h/7d/状态分布/方法分布/路径 Top/慢接口 Top/唯一 IP/唯一用户/平均耗时。
     * 供前端概览 Card 与图表使用；出参是全站口径，只有超管可读（路由挂 `$admin`）。
     *
     * 聚合恒在 SQL 侧：`overviewSql()` 把每片的 7 天窗口拼成一个 UNION ALL 子查询，
     * 每片只回聚合后的几行。行数正比于**分组基数**，而不是正比于历史流水。
     */
    public function overview()
    {
        [$present, $missing] = $this->existingShards(
            \app\support\Shard::tables(self::BASE)
        );
        if ($present === []) {
            return $this->fail(
                '审计分片表一张都不存在（配置 ' . count($missing) . ' 片全部缺失）：先跑迁移或核对 sharding.table_shards',
                500
            );
        }

        $from = $this->overviewSql($present, self::OVERVIEW_COLUMNS);
        // 「昨天」取自然日半开区间：旧写法用 `<= 23:59:59`，最后一秒内落库的行不属于任何一天。
        $yStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $todayStart = date('Y-m-d 00:00:00');

        try {
            // 一次扫描出全部标量卡。avg 与 total 必须同集合：旧写法分子取自被
            // LIMIT 截过的抽样、分母取自全量 count，抽样一被截平均值就静默偏小。
            $scalar = (array) (Db::select(
                "SELECT COUNT(*) AS total_7d,
                        COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL '24 hours') AS today_24h,
                        COUNT(*) FILTER (WHERE created_at >= ? AND created_at < ?) AS yesterday,
                        COALESCE(AVG(duration_ms), 0) AS avg_ms,
                        COUNT(DISTINCT NULLIF(ip, '')) AS unique_ips,
                        COUNT(DISTINCT NULLIF(username, '')) AS unique_users
                  FROM {$from} t",
                [$yStart, $todayStart]
            )[0] ?? []);

            $statusRows = Db::select(
                "SELECT CASE WHEN status >= 200 AND status < 300 THEN '2xx'
                             WHEN status >= 300 AND status < 400 THEN '3xx'
                             WHEN status >= 400 AND status < 500 THEN '4xx'
                             WHEN status >= 500 AND status < 600 THEN '5xx'
                             ELSE 'other' END AS bucket,
                        COUNT(*) AS c
                  FROM {$from} t
                 GROUP BY bucket"
            );
            $methodRows = Db::select(
                "SELECT UPPER(method) AS method, COUNT(*) AS c
                  FROM {$from} t
                  GROUP BY UPPER(method)
                  ORDER BY c DESC, method"
            );
            $pathRows = Db::select("SELECT path, COUNT(*) AS c FROM {$from} t GROUP BY path ORDER BY c DESC, path LIMIT 10");
            $slowRows = Db::select("SELECT path, MAX(duration_ms) AS ms FROM {$from} t GROUP BY path ORDER BY ms DESC, path LIMIT 10");
        } catch (\Throwable $e) {
            // 不静默回零：这里的 0 与「这台系统七天没人操作」长得一模一样。
            return $this->fail('审计总览聚合失败: ' . Clip::chars($e->getMessage(), 200), 500);
        }

        $status = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0];
        foreach ($statusRows as $row) {
            $status[(string) ($row['bucket'] ?? 'other')] = (int) ($row['c'] ?? 0);
        }

        return $this->ok([
            'total_7d' => (int) ($scalar['total_7d'] ?? 0),
            'today_24h' => (int) ($scalar['today_24h'] ?? 0),
            'yesterday' => (int) ($scalar['yesterday'] ?? 0),
            'status' => $status,
            'methods' => array_map(
                static fn(array $r): array => ['method' => (string) $r['method'], 'count' => (int) $r['c']],
                $methodRows
            ),
            'top_paths' => array_map(
                static fn(array $r): array => ['path' => (string) $r['path'], 'count' => (int) $r['c']],
                $pathRows
            ),
            'slow_paths' => array_map(
                static fn(array $r): array => ['path' => (string) $r['path'], 'duration_ms' => (int) $r['ms']],
                $slowRows
            ),
            'unique_ips' => (int) ($scalar['unique_ips'] ?? 0),
            'unique_users' => (int) ($scalar['unique_users'] ?? 0),
            'avg_duration_ms' => round((float) ($scalar['avg_ms'] ?? 0), 2),
            // 配置里有、库里没有的片表。非空即上面的数字只是部分口径 —— 宁可回给调用方，
            // 也不要像旧写法那样逐片 try/catch 把缺片吃成一个看起来正常的数字。
            'missing_tables' => $missing,
        ]);
    }

    /**
     * 跨片聚合的 FROM 子句：每片一个带窗口的子查询，UNION ALL 成一个派生表。
     *
     * 窗口写在**每条腿里面**而不是外层，否则每片都要先全表扫再让外层过滤，
     * 分片表越老越慢；这里每片各自用自己的范围去取。
     *
     * @param list<string> $tables 片表名（来自 Shard::tables()，非用户输入）
     */
    private function overviewSql(array $tables, string $columns): string
    {
        $legs = [];
        foreach ($tables as $table) {
            $legs[] = "SELECT {$columns} FROM {$table} WHERE " . self::OVERVIEW_WINDOW;
        }

        return '(' . implode(' UNION ALL ', $legs) . ')';
    }

    /**
     * 只保留真实存在的片表，并带回「配置里有、库里没有」的那部分。
     *
     * 聚合是 UNION ALL，一条腿指向不存在的表会让整条 SQL 报错（旧写法是逐片 try/catch，
     * 少一片只是数字偏小，页面毫无察觉）。所以先按目录核对一次，缺片如实进 `missing_tables`。
     *
     * @param list<string> $tables
     * @return array{0: list<string>, 1: list<string>} [存在的片表, 缺失的片表]
     */
    private function existingShards(array $tables): array
    {
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        try {
            $rows = Db::select(
                "SELECT c.relname AS name FROM pg_class c
                   JOIN pg_namespace n ON n.oid = c.relnamespace
                  WHERE n.nspname = current_schema() AND c.relname IN ({$placeholders})",
                $tables
            );
        } catch (\Throwable) {
            // 目录查不动（连接没权限等）：按配置口径去聚合，真缺表会让聚合抛错并被如实报出。
            return [$tables, []];
        }
        $live = array_map(static fn(array $r): string => (string) ($r['name'] ?? ''), $rows);

        return [
            array_values(array_intersect($tables, $live)),
            array_values(array_diff($tables, $live)),
        ];
    }

    /** 我的操作记录（按 token 用户名过滤，普通用户可见自己）。 */
    public function mine()
    {
        $payload = \app\support\AccountScope::payload();
        $username = \app\support\AccountScope::usernameOf($payload);
        if ($payload === null || $username === '') {
            return $this->fail('未认证', 401);
        }
        // 归并型分页：每片各取 page*size 行进 PHP 再排序切片，行预算要按片数摊薄
        $units = \app\support\Shard::readUnits(self::BASE, false, false);
        $win = $this->mergedWindow($this->query('page'), $this->query('size'), count($units));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];

        $total = 0;
        $merged = [];
        $need = $page * $size;
        $scan = \app\support\Shard::scan($units, static function (?string $conn, string $table) use ($username, $need): array {
            $handle = \app\support\Shard::db($conn);

            return [
                'total' => (int) ($handle->select(
                    "SELECT COUNT(*) AS c FROM {$table} WHERE username = ?",
                    [$username]
                )[0]['c'] ?? 0),
                'rows'  => $handle->select(
                    "SELECT id, method, path, status, duration_ms, ip, created_at
                     FROM {$table} WHERE username = ? ORDER BY id DESC LIMIT ?",
                    [$username, $need]
                ),
            ];
        });
        foreach ($scan['results'] as $piece) {
            $total += $piece['total'];
            foreach ($piece['rows'] as $row) {
                $merged[] = $row;
            }
        }
        usort($merged, static fn($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return $this->page(
            $this->hashRows(array_slice($merged, $offset, $size)),
            $total,
            $page,
            $size,
            ['unreadable_shards' => \app\support\Shard::unreadableKey($scan['unreadable'])]
        );
    }

    /** 审计 CSV 导出（超管，BOM 供 Excel 识别）。行数天花板与截断口径见 `EXPORT_LIMIT`。 */
    public function export()
    {
        // 复用 index() 的过滤逻辑：username / path / method / status / start_time / end_time
        $username = Clip::chars(trim((string) $this->query('username', '')), 64);
        $path = Clip::chars(trim((string) $this->query('path', '')), 255);
        $method = strtoupper(Clip::chars(trim((string) $this->query('method', '')), 10));
        $start = Clip::chars(trim((string) $this->query('start_time', '')), 19);
        $end = Clip::chars(trim((string) $this->query('end_time', '')), 19);
        $statusRaw = $this->query('status', null);
        $status = null;
        $statusEnd = null;
        if ($statusRaw !== null && $statusRaw !== '') {
            $code = (int) $statusRaw;
            if ($code >= 2 && $code <= 5) {
                $status = $code * 100;
                $statusEnd = $code * 100 + 99;
            } elseif ($code >= 100 && $code <= 599) {
                $status = $code;
                $statusEnd = $code;
            }
        }
        $where = [];
        $params = [];
        if ($username !== '') {
            $where[] = 'username LIKE ?';
            $params[] = '%' . $username . '%';
        }
        if ($path !== '') {
            $where[] = 'path LIKE ?';
            $params[] = '%' . $path . '%';
        }
        if (in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            $where[] = 'method = ?';
            $params[] = $method;
        }
        if ($status !== null) {
            $where[] = 'status BETWEEN ? AND ?';
            $params[] = $status;
            $params[] = $statusEnd;
        }
        if ($start !== '' && strtotime($start) !== false) {
            $where[] = 'created_at >= ?';
            $params[] = date('Y-m-d H:i:s', strtotime($start));
        }
        if ($end !== '' && strtotime($end) !== false) {
            $where[] = 'created_at <= ?';
            $params[] = date('Y-m-d H:i:s', strtotime($end));
        }
        $cond = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $merged = [];
        $scan = \app\support\Shard::scan(
            \app\support\Shard::readUnits(self::BASE, false, false),
            static function (?string $conn, string $table) use ($cond, $params): array {
                return \app\support\Shard::db($conn)->select(
                    "SELECT id, user_id, username, method, path, status, duration_ms, ip, created_at
                     FROM {$table}{$cond} ORDER BY id DESC LIMIT " . self::EXPORT_LIMIT,
                    $params
                );
            }
        );
        foreach ($scan['results'] as $rows) {
            foreach ($rows as $row) {
                $merged[] = $row;
            }
        }
        usort($merged, static fn($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);
        $out = "\xEF\xBB\xBFid,user_id,username,method,path,status,duration_ms,ip,created_at\n";
        foreach (array_slice($merged, 0, self::EXPORT_LIMIT) as $row) {
            $created = $row['created_at'] instanceof \DateTimeInterface
                ? $row['created_at']->format('Y-m-d H:i:s')
                : (string) ($row['created_at'] ?? '');
            $cells = [
                \app\support\HashId::enc((int) ($row['id'] ?? 0)),
                $this->csvCell(isset($row['user_id']) ? \app\support\HashId::enc((int) $row['user_id']) : ''),
                $this->csvCell((string) ($row['username'] ?? '')),
                (string) ($row['method'] ?? ''),
                $this->csvCell((string) ($row['path'] ?? '')),
                (string) (int) ($row['status'] ?? 0),
                (string) (int) ($row['duration_ms'] ?? 0),
                (string) ($row['ip'] ?? ''),
                $created,
            ];
            $out .= implode(',', $cells) . "\n";
        }
        // 半套产物必须自己说明是半套（同 notices 导出、AGENTS 对导出截断的口径：已发头改不了状态码）。
        $notes = [];
        $capped = array_filter(
            $scan['results'],
            static fn(array $rows): bool => count($rows) >= self::EXPORT_LIMIT
        );
        if ($capped !== []) {
            $notes[] = '有 ' . count($capped) . ' 个分片各达到单次上限 ' . self::EXPORT_LIMIT
                . ' 条：更早的行不在本文件里（按时间分段导出）';
        }
        if (count($merged) > self::EXPORT_LIMIT) {
            $notes[] = '跨片归并后只写入时间最近的 ' . self::EXPORT_LIMIT . ' 条，另有 '
                . (count($merged) - self::EXPORT_LIMIT) . ' 条未写入（放宽过滤条件或分批导出）';
        }
        foreach (\app\support\Shard::unreadableKey($scan['unreadable']) as $s) {
            $notes[] = $s['shard'] . '（' . $s['reason'] . '）';
        }
        if ($notes !== []) {
            $out .= '# ' . implode('；', $notes) . "\n";
        }

        return \Kode\Framework\Http\Resp::make('', 200, [])
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="kode_audits_' . date('Ymd_His') . '.csv"')
            ->withHeader('Content-Length', (string) strlen($out))
            ->withBody(\Kode\Http\Psr7\Stream::create($out));
    }

    private function csvCell(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    public function clean()
    {
        $guard = $this->retentionDays($this->query('days'));
        if ($guard['error'] !== null) {
            return $this->fail($guard['error'], 400);
        }
        $days = $guard['days'];

        $deleted = 0;
        $missing = [];
        // 基表也在清理面上：写侧不落它，但迁移前积的历史行一直躺在里面（clean 是它唯一的出口）。
        $tables = array_merge([self::BASE], \app\support\Shard::tables(self::BASE));
        foreach ($tables as $table) {
            try {
                $deleted += Db::delete(
                    "DELETE FROM {$table} WHERE created_at < NOW() - (? * INTERVAL '1 day')",
                    [$days]
                );
            } catch (\Throwable $e) {
                // 「一片没删掉」和「删了 0 行」是两件事：只回 deleted 会把前者渲染成后者。
                $missing[] = $table . '（' . \app\support\Shard::failureReason($e) . '）';
            }
        }
        if ($missing !== []) {
            return $this->fail(
                '部分表未能清理，本次 deleted 只是成功那几张的行数：' . implode('；', $missing),
                500
            );
        }

        return $this->ok(['deleted' => $deleted, 'days' => $days]);
    }
}
