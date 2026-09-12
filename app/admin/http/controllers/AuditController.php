<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use app\http\concerns\ApiResponse;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;

/**
 * 审计日志查询（超管）。
 */
final class AuditController extends Controller
{
    use ApiResponse;

    public function index()
    {
        \app\support\Shard::ensureTables('kode_audit_logs');
        $page = max(1, (int) $this->query('page', 1));
        $size = min(100, max(1, (int) $this->query('size', 20)));
        $username = substr(trim((string) $this->query('username', '')), 0, 64);
        $path = substr(trim((string) $this->query('path', '')), 0, 255);
        $method = strtoupper(substr(trim((string) $this->query('method', '')), 0, 10));
        $start = substr(trim((string) $this->query('start_time', '')), 0, 19);
        $end = substr(trim((string) $this->query('end_time', '')), 0, 19);
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
        $total = 0;
        $merged = [];
        $need = $page * $size;
        foreach (\app\support\Shard::tables('kode_audit_logs') as $table) {
            try {
                $total += (int) (Db::select("SELECT COUNT(*) AS c FROM {$table}{$cond}", $params)[0]['c'] ?? 0);
                $rows = Db::select(
                    "SELECT id, user_id, username, method, path, status, duration_ms, ip, created_at
                     FROM {$table}{$cond} ORDER BY id DESC LIMIT ?",
                    [...$params, $need]
                );
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $row) {
                $merged[] = $row;
            }
        }
        usort($merged, static fn($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return $this->page(array_slice($merged, ($page - 1) * $size, $size), $total, $page, $size);
    }

    /** 近 7 天审计量（跨分片聚合，供仪表盘趋势图）。 */
    public function stats()
    {
        \app\support\Shard::ensureTables('kode_audit_logs');
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $days[] = date('Y-m-d', strtotime("-{$i} days"));
        }
        $counts = array_fill_keys($days, 0);
        foreach (\app\support\Shard::tables('kode_audit_logs') as $table) {
            try {
                $rows = Db::select(
                    "SELECT TO_CHAR(created_at, 'YYYY-MM-DD') AS d, COUNT(*) AS c
                     FROM {$table} WHERE created_at >= NOW() - INTERVAL '6 days' GROUP BY d"
                );
            } catch (\Throwable) {
                continue;
            }
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

        return $this->ok($out);
    }

    /** 我的操作记录（按 token 用户名过滤，普通用户可见自己）。 */
    public function mine()
    {
        $payload = \Kode\Http\Request::attr('auth');
        $username = (string) ($payload->username ?? '');
        if ($username === '') {
            return $this->fail('未认证', 401);
        }
        \app\support\Shard::ensureTables('kode_audit_logs');
        $page = max(1, (int) $this->query('page', 1));
        $size = min(100, max(1, (int) $this->query('size', 20)));

        $total = 0;
        $merged = [];
        $need = $page * $size;
        foreach (\app\support\Shard::tables('kode_audit_logs') as $table) {
            try {
                $total += (int) (Db::select(
                    "SELECT COUNT(*) AS c FROM {$table} WHERE username = ?",
                    [$username]
                )[0]['c'] ?? 0);
                $rows = Db::select(
                    "SELECT id, method, path, status, duration_ms, ip, created_at
                     FROM {$table} WHERE username = ? ORDER BY id DESC LIMIT ?",
                    [$username, $need]
                );
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $row) {
                $merged[] = $row;
            }
        }
        usort($merged, static fn($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return $this->page(array_slice($merged, ($page - 1) * $size, $size), $total, $page, $size);
    }

        /** 审计 CSV 导出（超管，最多 5000 条，BOM 供 Excel 识别）。 */
    public function export()
    {
        \app\support\Shard::ensureTables('kode_audit_logs');
        $merged = [];
        foreach (\app\support\Shard::tables('kode_audit_logs') as $table) {
            try {
                $rows = Db::select(
                    "SELECT id, user_id, username, method, path, status, duration_ms, ip, created_at
                     FROM {$table} ORDER BY id DESC LIMIT 5000"
                );
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $row) {
                $merged[] = $row;
            }
        }
        usort($merged, static fn($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);
        $out = "\xEF\xBB\xBFid,user_id,username,method,path,status,duration_ms,ip,created_at\n";
        foreach (array_slice($merged, 0, 5000) as $row) {
            $created = $row['created_at'] instanceof \DateTimeInterface
                ? $row['created_at']->format('Y-m-d H:i:s')
                : (string) ($row['created_at'] ?? '');
            $cells = [
                (string) ($row['id'] ?? ''),
                $this->csvCell((string) ($row['user_id'] ?? '')),
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

    public function clean()    {
        $days = (int) $this->query('days', 90);
        $days = min(3650, max(7, $days));

        $deleted = 0;
        $tables = array_merge(['kode_audit_logs'], \app\support\Shard::tables('kode_audit_logs'));
        foreach ($tables as $table) {
            try {
                $deleted += Db::delete(
                    "DELETE FROM {$table} WHERE created_at < NOW() - (? * INTERVAL '1 day')",
                    [$days]
                );
            } catch (\Throwable) {
            }
        }

        return $this->ok(['deleted' => $deleted, 'days' => $days]);
    }
}
