<?php

declare(strict_types=1);

namespace Kode\Plugins\notices;

use app\support\HashId;
use app\support\Shard;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use Kode\Http\Request;

/**
 * 示例业务插件控制器：公告 CRUD（分库分表示范 + 归属鉴权）。
 *
 *  - 开启（SHARD_ENABLED）：按创建者归属落片（db/表双维），对外 eid 为三元组编码；
 *  - 关闭：走默认库的基表（见 self::TABLE）单表模式（零回归）；
 *  - 写操作验归属：创建者本人或 admin 可改删；
 *  - 分页窗口口径见 app\concerns\PageWindow（size 夹取、page 越界 400）。
 */
final class NoticeController extends Controller
{
    use ApiResponse;
    use PageWindow;

    /**
     * 本插件的基表名，唯一口径。
     *
     * 表名只此一处：分片开关决定「写侧落点」也决定「读侧表名」，两侧各写一遍就会漂
     * —— 这里曾经在未分片分支写成裸 `notices`，于是默认配置（SHARD_ENABLED=false）下
     * 列表读得到、点进详情/改/删全部 500（回归见 tests/NoticeSingleTableTest.php）。
     * 与 NoticePlugin::tables() 的声明必须同名，静态口径见 tests/PluginSchemaCoverageTest.php。
     */
    private const TABLE = 'kode_notices';

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

            return $id === null ? null : [null, self::TABLE, $id];
        }
        $parts = HashId::decIds($eid);
        if ($parts === null || count($parts) !== 3) {
            return null;
        }
        [$db, $table, $id] = $parts;
        // 越界序号直接 404（否则拼出非法库表名打到 PDO 报 500）。
        // 上限与写侧路由同源（Shard::config() 认 DB 热配置），两处各读一份必然漂移。
        if ($db < 0 || $db >= Shard::dbCount() || $table < 0 || $table >= Shard::tableShards() || $id <= 0) {
            return null;
        }

        return [sprintf('shard_%02d', $db), sprintf('%s_%02d', self::TABLE, $table), $id];
    }

    public function index()
    {
        // 分片模式是归并型分页（每片各取 page*size 行进 PHP 再切片），行预算按片数摊薄；
        // 单表模式的代价在 SQL 侧 OFFSET，mergedWindow 对它自动退回默认预算。
        $shards = Shard::enabled() ? Shard::dbCount() * Shard::tableShards() : 1;
        $win = $this->mergedWindow($this->query('page'), $this->query('size'), $shards);
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];

        \app\support\Shard::ensureTables(self::TABLE);

        $statusRaw = $this->query('status', null);
        $status = $statusRaw === null || $statusRaw === '' ? null : ((int) $statusRaw ? 1 : 0);
        // type 白名单：仅允许已知值；未知值忽略（避免注入 + 避免污染过滤条件）
        $type = $this->normalizeType($this->query('type', null));
        $isAdmin = $this->isAdmin();

        $typeCond = $type === null ? '' : $this->quoteTypeCond($type);

        if (!Shard::enabled()) {
            $where = '';
            if ($status !== null) {
                $where .= " WHERE status = {$status}";
            }
            if ($typeCond !== '') {
                $where .= ($where === '' ? ' WHERE ' : ' AND ') . $typeCond;
            }
            if (!$isAdmin) {
                $where .= ($where === '' ? ' WHERE ' : ' AND ') . 'status = 1';
            }
            $total = (int) (Db::select("SELECT COUNT(*) AS c FROM " . self::TABLE . "{$where}")[0]['c'] ?? 0);
            $rows = Db::select(
                "SELECT id, title, body, type, status, created_at FROM " . self::TABLE . "{$where} ORDER BY id DESC LIMIT ? OFFSET ?",
                [$size, $offset]
            );
            foreach ($rows as &$row) {
                $row['id'] = HashId::enc((int) $row['id']);
                $row['type'] = self::validType($row['type']) ? $row['type'] : 'announcement';
            }
            unset($row);

            return $this->page($rows, $total, $page, $size, ['unreadable_shards' => []]);
        }

        $total = 0;
        $merged = [];
        // 每个分片各取 $need 行再合并排序；$page 已被 mergedWindow 拦住（预算按片数摊薄），
        // 所以这里乘出来的行数在内存预算内，也不会乘成 float。
        $need = $page * $size;
        $shardWhere = '';
        if ($status !== null) {
            $shardWhere .= " WHERE status = {$status}";
        }
        if ($typeCond !== '') {
            $shardWhere .= ($shardWhere === '' ? ' WHERE ' : ' AND ') . $typeCond;
        }
        if (!$this->isAdmin()) {
            $shardWhere .= ($shardWhere === '' ? ' WHERE ' : ' AND ') . 'status = 1';
        }
        // 片清单与派发都在 Shard 里（读侧与写侧同一份模数）：一片读不动时如实进
        // `unreadable_shards`，而不是被 `catch (\Throwable) continue` 吃成「这一片没有数据」。
        $scan = Shard::scan(Shard::readUnits(self::TABLE), function (?string $conn, string $table, int $db, int $t) use ($shardWhere, $need): array {
            $handle = Shard::db($conn);

            return [
                'total' => (int) ($handle->select("SELECT COUNT(*) AS c FROM {$table}{$shardWhere}")[0]['c'] ?? 0),
                'rows' => $handle->select(
                    "SELECT id, title, body, type, status, created_by, created_at FROM {$table}{$shardWhere} ORDER BY id DESC LIMIT ?",
                    [$need]
                ),
                'at' => [$db, $t],
            ];
        });

        $unreadable = Shard::unreadableKey($scan['unreadable']);
        foreach ($scan['results'] as $piece) {
            $total += $piece['total'];
            foreach ($piece['rows'] as $row) {
                $row['id'] = HashId::encIds([$piece['at'][0], $piece['at'][1], (int) $row['id']]);
                $row['type'] = self::validType($row['type']) ? $row['type'] : 'announcement';
                $merged[] = $row;
            }
        }
        usort($merged, static fn($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return $this->page(
            array_slice($merged, $offset, $size),
            $total,
            $page,
            $size,
            ['unreadable_shards' => $unreadable]
        );
    }

    /**
     * type 白名单校验。返回 null 表示"不过滤"；返回合法字符串表示"仅该类型"。
     */
    private function normalizeType($raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        return self::validType($raw) ? (string) $raw : null;
    }

    /** 仅允许两个已知类型，其他一律归一为 announcement */
    private static function validType($value): bool
    {
        return $value === 'announcement' || $value === 'internal';
    }

    /**
     * 构造 SQL WHERE 片段（type = 'x'）。
     * 入参已由 normalizeType() 白名单校验，仅两个已知常量，字符串插值安全。
     */
    private function quoteTypeCond(string $type): string
    {
        // 再次断言：即便调用方绕过 normalizeType 直接调用，也保证只写入白名单值
        if ($type !== 'announcement' && $type !== 'internal') {
            return '1=0';
        }

        return "type = '{$type}'";
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
        \app\support\Shard::ensureTables(self::TABLE);
        $data = $this->validate(Request::all(), [
            'title' => 'required|min:2|max:128',
            'body' => 'required|min:1|max:50000',
            'status' => 'nullable|integer',
            'type' => 'nullable|string|in:announcement,internal',
        ]);
        $owner = (string) $this->owner();
        $status = array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : 1;
        $type = self::validType($data['type'] ?? null) ? $data['type'] : 'announcement';

        $eid = Shard::onShard(self::TABLE, $owner, function (?string $conn, string $table) use ($data, $owner, $status, $type): string {
            $db = Shard::db($conn);
            $id = $db->insert(
                "INSERT INTO {$table} (title, body, type, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
                [$data['title'], $data['body'], $type, $status, $owner]
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
            'type' => 'nullable|string|in:announcement,internal',
        ]);

        $row = $this->findIn($conn, $table, $id);
        if ($row === null) {
            return $this->fail('不存在', 404);
        }
        if (!$this->isAdmin() && (string) ($row['created_by'] ?? '') !== (string) $this->owner()) {
            return $this->fail('只能操作自己的公告', 403);
        }

        $newStatus = array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : (int) ($row['status'] ?? 1);
        if (self::validType($data['type'] ?? null)) {
            $newType = $data['type'];
        } elseif (self::validType($row['type'] ?? null)) {
            $newType = $row['type'];
        } else {
            $newType = 'announcement';
        }

        Shard::db($conn)->update(
            "UPDATE {$table} SET title = ?, body = ?, type = ?, status = ?, updated_at = NOW() WHERE id = ?",
            [$data['title'], $data['body'], $newType, $newStatus, $id]
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
        \app\support\Shard::ensureTables(self::TABLE);
        $merged = [];
        $unreadable = [];
        if (!Shard::enabled()) {
            foreach (Db::select('SELECT id, title, body, type, status, created_at FROM ' . self::TABLE . ' ORDER BY id DESC LIMIT 2000') as $row) {
                $row['id'] = HashId::enc((int) $row['id']);
                $merged[] = $row;
            }
        } else {
            // 与列表同一个派发口：读不动的片要在产物里点名，不能让半套 CSV 看着像全量。
            $scan = Shard::scan(Shard::readUnits(self::TABLE), static function (?string $conn, string $table, int $db, int $t): array {
                $handle = Shard::db($conn);
                $rows = $handle->select(
                    "SELECT id, title, body, type, status, created_at FROM {$table} ORDER BY id DESC LIMIT 2000"
                );
                foreach ($rows as &$row) {
                    $row['id'] = HashId::encIds([$db, $t, (int) $row['id']]);
                }
                unset($row);

                return $rows;
            });
            foreach ($scan['results'] as $rows) {
                foreach ($rows as $row) {
                    $merged[] = $row;
                }
            }
            $unreadable = Shard::unreadableKey($scan['unreadable']);
        }
        usort($merged, static fn($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);
        $out = "\xEF\xBB\xBFid,title,body,type,status,created_at\n";
        foreach (array_slice($merged, 0, 2000) as $row) {
            $created = $row['created_at'] instanceof \DateTimeInterface
                ? $row['created_at']->format('Y-m-d H:i:s')
                : (string) ($row['created_at'] ?? '');
            $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($row['body'] ?? ''))));
            if (mb_strlen($text) > 200) {
                $text = mb_substr($text, 0, 200) . '…';
            }
            $type = self::validType($row['type'] ?? null) ? $row['type'] : 'announcement';
            $cells = [
                $this->csvCell((string) ($row['id'] ?? '')),
                $this->csvCell((string) ($row['title'] ?? '')),
                $this->csvCell($text),
                $this->csvCell((string) $type),
                (string) (int) ($row['status'] ?? 0),
                $created,
            ];
            $out .= implode(',', $cells) . "\n";
        }
        // 半套产物必须自己说明是半套（同 AGENTS 对导出截断的口径：已发头改不了状态码）。
        if ($unreadable !== []) {
            $notes = [];
            foreach ($unreadable as $s) {
                $notes[] = $s['shard'] . '（' . $s['reason'] . '）';
            }
            $out .= '# 以下分片未能读取，本文件不含它们的行：' . implode('；', $notes) . "\n";
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
