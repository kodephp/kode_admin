<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use app\http\concerns\ApiResponse;

/**
 * 系统日志查询 + 清理（超管；业务代码用 app\support\SysLog::write 打点）。
 */
final class SyslogController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $page = max(1, (int) $this->query('page', 1));
        $size = min(100, max(1, (int) $this->query('size', 20)));
        $level = substr((string) $this->query('level', ''), 0, 16);
        $module = substr(trim((string) $this->query('module', '')), 0, 64);
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
        $cond = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int) (Db::select("SELECT COUNT(*) AS c FROM kode_system_logs {$cond}", $params)[0]['c'] ?? 0);
        $params[] = $size;
        $params[] = ($page - 1) * $size;
        $rows = Db::select(
            "SELECT id, level, module, message, ip, created_at FROM kode_system_logs {$cond} ORDER BY id DESC LIMIT ? OFFSET ?",
            $params
        );
        foreach ($rows as &$row) {
            if ($row['created_at'] instanceof \DateTimeInterface) {
                $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
            }
        }
        unset($row);

        return $this->page($rows, $total, $page, $size);
    }

    public function show()
    {
        $id = (int) $this->param('id', 0);
        $rows = Db::select('SELECT id, level, module, message, context, ip, created_at FROM kode_system_logs WHERE id = ? LIMIT 1', [$id]);
        if ($rows === []) {
            return $this->fail('不存在', 404);
        }
        $row = $rows[0];
        if ($row['created_at'] instanceof \DateTimeInterface) {
            $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
        }

        return $this->ok($row);
    }

    public function clean()
    {
        $days = min(3650, max(7, (int) $this->query('days', 90)));
        $deleted = Db::delete(
            "DELETE FROM kode_system_logs WHERE created_at < NOW() - (? * INTERVAL '1 day')",
            [$days]
        );

        return $this->ok(['deleted' => $deleted, 'days' => $days]);
    }
}
