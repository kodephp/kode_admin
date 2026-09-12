<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;
use app\http\concerns\ApiResponse;

/**
 * 数据库管理（只读浏览 + 受限 SQL 查询，仅超管）：
 * 查表/列/分页行 + 单条 SELECT/WITH 查询（禁分号多语句，自动 LIMIT 200）。
 */
final class DbController extends Controller
{
    use ApiResponse;

    public function tables()
    {
        $rows = Db::select(
            "SELECT tablename AS name FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'kode\_%' ORDER BY tablename"
        );

        return $this->ok(array_column($rows, 'name'));
    }

    public function columns()
    {
        $table = $this->table();
        if ($table === null) {
            return $this->fail('表不存在', 404);
        }
        $rows = Db::select(
            'SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position',
            [$table]
        );

        return $this->ok($rows);
    }

    public function rows()
    {
        $table = $this->table();
        if ($table === null) {
            return $this->fail('表不存在', 404);
        }
        $page = max(1, (int) $this->query('page', 1));
        $size = min(200, max(1, (int) $this->query('size', 20)));
        $total = (int) (Db::select("SELECT COUNT(*) AS c FROM {$table}")[0]['c'] ?? 0);
        $rows = Db::select("SELECT * FROM {$table} ORDER BY 1 DESC LIMIT ? OFFSET ?", [$size, ($page - 1) * $size]);
        foreach ($rows as &$row) {
            foreach ($row as $k => $v) {
                if ($v instanceof \DateTimeInterface) {
                    $row[$k] = $v->format('Y-m-d H:i:s');
                } elseif (is_resource($v)) {
                    $row[$k] = '[binary]';
                } elseif (is_string($v) && strlen($v) > 2000) {
                    $row[$k] = substr($v, 0, 2000) . '…';
                }
            }
        }
        unset($row);

        return $this->page($rows, $total, $page, $size);
    }

    public function sql()
    {
        $data = $this->validate(Request::all(), ['sql' => 'required|min:8|max:2000']);
        $sql = trim((string) $data['sql']);
        if (!preg_match('/^(SELECT|WITH)\b/i', $sql)) {
            return $this->fail('仅允许 SELECT/WITH 查询', 400);
        }
        if (str_contains($sql, ';')) {
            return $this->fail('禁止多语句', 400);
        }
        foreach (['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'GRANT', 'COPY', 'VACUUM'] as $kw) {
            if (preg_match('/\b' . $kw . '\b/i', $sql)) {
                return $this->fail("禁止 {$kw} 关键字", 400);
            }
        }
        if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
            $sql .= ' LIMIT 200';
        }
        try {
            $rows = Db::select($sql);
        } catch (\Throwable $e) {
            return $this->fail('查询失败：' . substr($e->getMessage(), 0, 200), 400);
        }
        foreach ($rows as &$row) {
            foreach ($row as $k => $v) {
                if ($v instanceof \DateTimeInterface) {
                    $row[$k] = $v->format('Y-m-d H:i:s');
                } elseif (is_resource($v)) {
                    $row[$k] = '[binary]';
                } elseif (is_string($v) && strlen($v) > 2000) {
                    $row[$k] = substr($v, 0, 2000) . '…';
                }
            }
        }
        unset($row);

        return $this->ok(array_slice($rows, 0, 200));
    }

    /** 表名白名单：存在于 public 的 kode_ 前缀表。 */
    private function table(): ?string
    {
        $table = (string) $this->param('table', '');
        if (preg_match('/^kode_[a-z0-9_]{1,64}$/', $table) !== 1) {
            return null;
        }
        $exists = Db::select("SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND tablename = ? LIMIT 1", [$table]);

        return $exists === [] ? null : $table;
    }
}
