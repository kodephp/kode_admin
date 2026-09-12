<?php

declare(strict_types=1);

namespace Kode\Plugins\notices;

use app\support\HashId;
use app\support\Shard;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use app\http\concerns\ApiResponse;
use Kode\Http\Request;

/**
 * 示例业务插件控制器：公告 CRUD（分库分表示范 + 归属鉴权）。
 *
 *  - 开启（SHARD_ENABLED）：按创建者归属落片（db/表双维），对外 eid 为三元组编码；
 *  - 关闭：走默认库 notices 单表（零回归）；
 *  - 写操作验归属：创建者本人或 admin 可改删。
 */
final class NoticeController extends Controller
{
    use ApiResponse;

    private function owner(): int
    {
        $payload = Request::attr('auth');

        return (int) ($payload->uid ?? 0);
    }

    private function isAdmin(): bool
    {
        $payload = Request::attr('auth');
        $roles = $payload !== null ? (array) ($payload->roles ?? []) : [];

        return in_array('admin', $roles, true);
    }

    /** @return array{0: string|null, 1: string, 2: int} [conn, table, id] */
    private function locate(string $eid): ?array
    {
        // 未分片模式：单值 eid + 基表。
        if (!Shard::enabled()) {
            $id = HashId::dec($eid);

            return $id === null ? null : [null, 'notices', $id];
        }
        $parts = HashId::decIds($eid);
        if ($parts === null || count($parts) !== 3) {
            return null;
        }
        [$db, $table, $id] = $parts;
        // 越界序号直接 404（否则拼出非法库表名打到 PDO 报 500）。
        $cfg = (array) config('sharding', []);
        if ($db < 0 || $db >= max(1, (int) ($cfg['db_count'] ?? 2))
            || $table < 0 || $table >= max(1, (int) ($cfg['table_shards'] ?? 2))
            || $id <= 0
        ) {
            return null;
        }

        return [sprintf('shard_%02d', $db), sprintf('kode_notices_%02d', $table), $id];
    }

    public function index()
    {
        \app\support\Shard::ensureTables('kode_notices');
        $page = max(1, (int) $this->query('page', 1));
        $size = min(100, max(1, (int) $this->query('size', 20)));

        $statusRaw = $this->query('status', null);
        $status = $statusRaw === null || $statusRaw === '' ? null : ((int) $statusRaw ? 1 : 0);
        $isAdmin = $this->isAdmin();
        if (!Shard::enabled()) {
            $where = $status === null ? '' : ' WHERE status = ' . $status;
            if (!$isAdmin) {
                $where .= $where === '' ? ' WHERE status = 1' : ' AND status = 1';
            }
            $total = (int) (Db::select("SELECT COUNT(*) AS c FROM kode_notices{$where}")[0]['c'] ?? 0);
            $rows = Db::select(
                "SELECT id, title, body, status, created_at FROM kode_notices{$where} ORDER BY id DESC LIMIT ? OFFSET ?",
                [$size, ($page - 1) * $size]
            );
            foreach ($rows as &$row) {
                $row['id'] = HashId::enc((int) $row['id']);
            }
            unset($row);

            return $this->page($rows, $total, $page, $size);
        }

        $cfg = (array) config('sharding', []);
        $total = 0;
        $merged = [];
        $need = $page * $size;
        $shardWhere = $status === null ? '' : ' WHERE status = ' . $status;
        if (!$this->isAdmin()) {
            $shardWhere .= $shardWhere === '' ? ' WHERE status = 1' : ' AND status = 1';
        }
        for ($d = 0; $d < max(1, (int) ($cfg['db_count'] ?? 2)); $d++) {
            $conn = sprintf('shard_%02d', $d);
            for ($t = 0; $t < max(1, (int) ($cfg['table_shards'] ?? 2)); $t++) {
                $table = sprintf('kode_notices_%02d', $t);
                $total += (int) (Db::connection($conn)->select("SELECT COUNT(*) AS c FROM {$table}{$shardWhere}")[0]['c'] ?? 0);
                $rows = Db::connection($conn)->select(
                    "SELECT id, title, body, status, created_by, created_at FROM {$table}{$shardWhere} ORDER BY id DESC LIMIT ?",
                    [$need]
                );
                foreach ($rows as $row) {
                    $row['id'] = HashId::encIds([$d, $t, (int) $row['id']]);
                    $merged[] = $row;
                }
            }
        }
        usort($merged, static fn($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return $this->page(array_slice($merged, ($page - 1) * $size, $size), $total, $page, $size);
    }

    public function show()
    {
        $row = $this->load();
        if ($row === null) {
            return $this->fail('不存在', 404);
        }

        return $this->ok($row);
    }

    public function store()
    {
        \app\support\Shard::ensureTables('kode_notices');
        $data = $this->validate(Request::all(), [
            'title' => 'required|min:2|max:128',
            'body' => 'required|min:1|max:50000',
            'status' => 'nullable|integer',
        ]);
        $owner = (string) $this->owner();
        $status = array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : 1;

        $eid = Shard::onShard('kode_notices', $owner, function (?string $conn, string $table) use ($data, $owner): string {
            $db = Shard::db($conn);
            $id = $db->insert(
                "INSERT INTO {$table} (title, body, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())",
                [$data['title'], $data['body'], $status, $owner]
            );

            if ($conn === null) {
                return HashId::enc((int) $id);
            }
            $dbIndex = (int) substr($conn, -2);
            $tableIndex = (int) substr($table, -2);

            return HashId::encIds([$dbIndex, $tableIndex, (int) $id]);
        });

        return $this->ok(['id' => $eid], 201);
    }

    public function update()
    {
        $loc = $this->locate((string) $this->param('id'));
        if ($loc === null) {
            return $this->fail('不存在', 404);
        }
        [$conn, $table, $id] = $loc;
        $data = $this->validate(Request::all(), [
            'title' => 'required|min:2|max:128',
            'body' => 'required|min:1|max:50000',
            'status' => 'nullable|integer',
        ]);

        $row = $this->findIn($conn, $table, $id);
        if ($row === null) {
            return $this->fail('不存在', 404);
        }
        if (!$this->isAdmin() && (string) ($row['created_by'] ?? '') !== (string) $this->owner()) {
            return $this->fail('只能操作自己的公告', 403);
        }

        Shard::db($conn)->update(
            "UPDATE {$table} SET title = ?, body = ?, status = ?, updated_at = NOW() WHERE id = ?",
            [$data['title'], $data['body'], array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : (int) ($row['status'] ?? 1), $id]
        );

        return $this->ok(['id' => (string) $this->param('id')]);
    }

    public function destroy()
    {
        $loc = $this->locate((string) $this->param('id'));
        if ($loc === null) {
            return $this->fail('不存在', 404);
        }
        [$conn, $table, $id] = $loc;

        $row = $this->findIn($conn, $table, $id);
        if ($row === null) {
            return $this->fail('不存在', 404);
        }
        if (!$this->isAdmin() && (string) ($row['created_by'] ?? '') !== (string) $this->owner()) {
            return $this->fail('只能操作自己的公告', 403);
        }

        Shard::db($conn)->delete("DELETE FROM {$table} WHERE id = ?", [$id]);

        return $this->ok(['deleted' => (string) $this->param('id')]);
    }

    /** 公告 CSV 导出（正文去标签截断，最多 2000 条）。 */
    public function export()
    {
        \app\support\Shard::ensureTables('kode_notices');
        $merged = [];
        if (!Shard::enabled()) {
            foreach (Db::select('SELECT id, title, body, status, created_at FROM kode_notices ORDER BY id DESC LIMIT 2000') as $row) {
                $row['id'] = HashId::enc((int) $row['id']);
                $merged[] = $row;
            }
        } else {
            $cfg = (array) config('sharding', []);
            for ($d = 0; $d < max(1, (int) ($cfg['db_count'] ?? 2)); $d++) {
                $conn = sprintf('shard_%02d', $d);
                for ($t = 0; $t < max(1, (int) ($cfg['table_shards'] ?? 2)); $t++) {
                    $table = sprintf('kode_notices_%02d', $t);
                    try {
                        $rows = Db::connection($conn)->select(
                            "SELECT id, title, body, status, created_at FROM {$table} ORDER BY id DESC LIMIT 2000"
                        );
                    } catch (\Throwable) {
                        continue;
                    }
                    foreach ($rows as $row) {
                        $row['id'] = HashId::encIds([$d, $t, (int) $row['id']]);
                        $merged[] = $row;
                    }
                }
            }
        }
        usort($merged, static fn($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);
        $out = "\xEF\xBB\xBFid,title,body,status,created_at\n";
        foreach (array_slice($merged, 0, 2000) as $row) {
            $created = $row['created_at'] instanceof \DateTimeInterface
                ? $row['created_at']->format('Y-m-d H:i:s')
                : (string) ($row['created_at'] ?? '');
            $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($row['body'] ?? ''))));
            if (mb_strlen($text) > 200) {
                $text = mb_substr($text, 0, 200) . '…';
            }
            $cells = [
                $this->csvCell((string) ($row['id'] ?? '')),
                $this->csvCell((string) ($row['title'] ?? '')),
                $this->csvCell($text),
                (string) (int) ($row['status'] ?? 0),
                $created,
            ];
            $out .= implode(',', $cells) . "\n";
        }

        return \Kode\Framework\Http\Resp::make('', 200, [])
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="kode_notices_' . date('Ymd_His') . '.csv"')
            ->withHeader('Content-Length', (string) strlen($out))
            ->withBody(\Kode\Http\Psr7\Stream::create($out));
    }

    private function csvCell(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    /** @return array<string, mixed>|null */
    private function findIn(?string $conn, string $table, int $id): ?array
    {
        // 未分片兼容：locate 仅处理三元组；单库模式走基表（基表 id 即三元组 [0,0,id] 亦可解）。
        if ($conn === null) {
            $rows = Db::select("SELECT * FROM {$table} WHERE id = ? LIMIT 1", [$id]);

            return $rows[0] ?? null;
        }
        $rows = Db::connection($conn)->select("SELECT * FROM {$table} WHERE id = ? LIMIT 1", [$id]);

        return $rows[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function load(): ?array
    {
        $loc = $this->locate((string) $this->param('id'));
        if ($loc === null) {
            return null;
        }
        [$conn, $table, $id] = $loc;
        $row = $this->findIn($conn, $table, $id);
        if ($row === null) {
            return null;
        }
        $row['id'] = (string) $this->param('id');

        return $row;
    }
}
