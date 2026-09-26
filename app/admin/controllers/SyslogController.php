<?php

declare(strict_types=1);

namespace app\admin\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use app\concerns\RetentionGuard;
use app\support\Clip;

/**
 * 系统日志查询 + 清理（超管；业务代码用 app\support\SysLog::write 打点）。
 */
final class SyslogController extends Controller
{
    use ApiResponse;
    use PageWindow;
    use RetentionGuard;

    public function index()
    {
        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];
        $level = Clip::chars((string) $this->query('level', ''), 16);
        $module = Clip::chars(trim((string) $this->query('module', '')), 64);
        $keyword = Clip::chars(trim((string) $this->query('keyword', '')), 128);
        $ip = Clip::chars(trim((string) $this->query('ip', '')), 64);
        $start = Clip::chars(trim((string) $this->query('start_time', '')), 19);
        $end = Clip::chars(trim((string) $this->query('end_time', '')), 19);
        $where = [];
        $params = [];
        if ($level !== '') {
            $where[] = 'level = ?';
            $params[] = $level;
        }
        if ($module !== '') {
            $where[] = 'module LIKE ?';
            $params[] = '%' . $module . '%';
        }
        if ($keyword !== '') {
            $where[] = 'message LIKE ?';
            $params[] = '%' . $keyword . '%';
        }
        if ($ip !== '') {
            $where[] = 'ip = ?';
            $params[] = $ip;
        }
        if ($start !== '' && strtotime($start) !== false) {
            $where[] = 'created_at >= ?';
            $params[] = date('Y-m-d H:i:s', strtotime($start));
        }
        if ($end !== '' && strtotime($end) !== false) {
            $where[] = 'created_at <= ?';
            $params[] = date('Y-m-d H:i:s', strtotime($end));
        }
        $cond = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int) (Db::select("SELECT COUNT(*) AS c FROM kode_system_logs {$cond}", $params)[0]['c'] ?? 0);
        $params[] = $size;
        $params[] = $offset;
        $rows = Db::select(
            "SELECT id, level, module, message, ip, created_at FROM kode_system_logs {$cond} ORDER BY id DESC LIMIT ? OFFSET ?",
            $params
        );
        foreach ($rows as &$row) {
            $row['id'] = \app\support\HashId::enc((int) $row['id']);
            if ($row['created_at'] instanceof \DateTimeInterface) {
                $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
            }
        }
        unset($row);

        return $this->page($rows, $total, $page, $size);
    }

    public function show()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $rows = Db::select('SELECT id, level, module, message, context, ip, created_at FROM kode_system_logs WHERE id = ? LIMIT 1', [$id]);
        if ($rows === []) {
            return $this->fail('不存在', 404);
        }
        $row = $rows[0];
        $row['id'] = \app\support\HashId::enc((int) $row['id']);
        if ($row['created_at'] instanceof \DateTimeInterface) {
            $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
        }

        return $this->ok($row);
    }

    public function clean()
    {
        $guard = $this->retentionDays($this->query('days'));
        if ($guard['error'] !== null) {
            return $this->fail($guard['error'], 400);
        }
        $days = $guard['days'];
        $deleted = Db::delete(
            "DELETE FROM kode_system_logs WHERE created_at < NOW() - (? * INTERVAL '1 day')",
            [$days]
        );

        return $this->ok(['deleted' => $deleted, 'days' => $days]);
    }

    /**
     * GET /api/syslogs/stats — 按模块/级别聚合统计（近 N 天）。
     *
     * Query: ?days=7
     */
    public function stats()
    {
        $days = min(90, max(1, (int) $this->query('days', 7)));

        $byLevel = Db::select(
            "SELECT level, COUNT(*) AS count
             FROM kode_system_logs
             WHERE created_at >= NOW() - (? * INTERVAL '1 day')
             GROUP BY level
             ORDER BY count DESC",
            [$days]
        );

        $byModule = Db::select(
            "SELECT module, COUNT(*) AS count
             FROM kode_system_logs
             WHERE created_at >= NOW() - (? * INTERVAL '1 day')
             GROUP BY module
             ORDER BY count DESC
             LIMIT 20",
            [$days]
        );

        $total = (int) (Db::select(
            "SELECT COUNT(*) AS c FROM kode_system_logs WHERE created_at >= NOW() - (? * INTERVAL '1 day')",
            [$days]
        )[0]['c'] ?? 0);

        $errors = (int) (Db::select(
            "SELECT COUNT(*) AS c FROM kode_system_logs
             WHERE created_at >= NOW() - (? * INTERVAL '1 day') AND level IN ('error', 'critical')",
            [$days]
        )[0]['c'] ?? 0);

        return $this->ok([
            'total' => $total,
            'errors' => $errors,
            'error_rate' => $total > 0 ? round($errors / $total * 100, 2) : 0,
            'by_level' => array_map(static fn(array $r): array => [
                'level' => (string) $r['level'],
                'count' => (int) $r['count'],
            ], $byLevel),
            'by_module' => array_map(static fn(array $r): array => [
                'module' => (string) $r['module'],
                'count' => (int) $r['count'],
            ], $byModule),
            'days' => $days,
        ]);
    }

    /**
     * GET /api/syslogs/trend — 错误率时间序列（按小时/天聚合）。
     *
     * Query: ?days=7&granularity=hour|day
     */
    public function trend()
    {
        $days = min(30, max(1, (int) $this->query('days', 7)));
        $granularity = (string) $this->query('granularity', 'hour');

        if ($granularity === 'day' && $days <= 30) {
            $dateFormat = 'YYYY-MM-DD';
            $interval = "? * INTERVAL '1 day'";
        } else {
            $dateFormat = 'YYYY-MM-DD HH24';
            $interval = "? * INTERVAL '1 hour'";
        }

        $rows = Db::select(
            "SELECT TO_CHAR(created_at, '{$dateFormat}') AS bucket,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE level IN ('error', 'critical')) AS errors
             FROM kode_system_logs
             WHERE created_at >= NOW() - ($interval)
             GROUP BY bucket
             ORDER BY bucket",
            [$days]
        );

        $trend = [];
        foreach ($rows as $row) {
            $trend[] = [
                'bucket' => (string) $row['bucket'],
                'total' => (int) $row['total'],
                'errors' => (int) $row['errors'],
                'rate' => (int) $row['total'] > 0
                    ? round((int) $row['errors'] / (int) $row['total'] * 100, 1)
                    : 0,
            ];
        }

        return $this->ok([
            'trend' => $trend,
            'days' => $days,
            'granularity' => $granularity,
        ]);
    }

    /**
     * GET /api/syslogs/top-errors — Top-N 高频错误消息。
     *
     * Query: ?limit=10
     */
    public function topErrors()
    {
        $limit = min(50, max(1, (int) $this->query('limit', 10)));

        $rows = Db::select(
            // 行身份 = message，module 取代表值（同一条消息可能来自多个模块，按模块再分组会把计数拆散）
            "SELECT message, COUNT(*) AS count, MAX(created_at) AS last_seen, MIN(module) AS module
             FROM kode_system_logs
             WHERE level IN ('error', 'critical')
             GROUP BY message
             ORDER BY count DESC
             LIMIT ?",
            [$limit]
        );

        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'message' => (string) $row['message'],
                'count' => (int) $row['count'],
                'module' => (string) ($row['module'] ?? ''),
                'last_seen' => $row['last_seen'] instanceof \DateTimeInterface
                    ? $row['last_seen']->format('Y-m-d H:i:s')
                    : (string) $row['last_seen'],
            ];
        }

        return $this->ok($list);
    }
}
