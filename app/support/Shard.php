<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;

/**
 * 水平分片路由（亿级架构）：库 + 表双维路由，错误自带片标识。
 *
 *  - 关闭（sharding.enabled=false）：全部走默认连接 + 基表名，零回归；
 *  - 开启：$h = crc32($key)，库序号取高 16 位 mod 库数，表序号取低位 mod 片数；
 *  - 同一归属键（owner/user_id）恒落同一库表（归属亲和），跨片查询走聚合；
 *  - select/execute 包装器：PDO 异常重抛时前缀 `[shard:库.表]`，结合 trace_id
 *    即可定位故障片（见 audit 日志 + 错误响应 trace_id）。
 */
final class Shard
{
    public const COUNT = 4;

    public static function index(string $key): int
    {
        return abs(crc32($key)) % self::COUNT;
    }

    public static function table(string $base, string $key): string
    {
        return sprintf('%s_%02d', $base, self::index($key));
    }

    /** @return list<string> 全部分片表名（聚合查询用） */
    public static function tables(string $base): array
    {
        $tables = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $tables[] = sprintf('%s_%02d', $base, $i);
        }

        return $tables;
    }

    public static function enabled(): bool
    {
        try {
            return (bool) config('sharding.enabled', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 分片键 → [连接名, 表名]。关闭时返回 [默认连接, 基表名]。
     *
     * @return array{0: string|null, 1: string} [connection|null, table]
     */
    public static function route(string $base, string $key): array
    {
        if (!self::enabled()) {
            return [null, $base];
        }
        $cfg = (array) config('sharding', []);
        $dbCount = max(1, (int) ($cfg['db_count'] ?? 2));
        $tableShards = max(1, (int) ($cfg['table_shards'] ?? 2));

        $h = abs(crc32($key));
        $dbIndex = (int) (($h >> 16) % $dbCount);
        $tableIndex = (int) ($h % $tableShards);

        return [sprintf('shard_%02d', $dbIndex), sprintf('%s_%02d', $base, $tableIndex)];
    }

    /**
     * 在目标分片上执行回调（连接缺失/失败时异常带片标识）。
     *
     * 回调签名 fn(?string $conn, string $table)：$conn 为 null 时用默认连接，
     * 否则用 Db::connection($conn) 在该分片库上执行。
     *
     * @template T
     * @param callable(?string, string): T $fn
     * @return T
     */
    public static function onShard(string $base, string $key, callable $fn): mixed
    {
        [$conn, $table] = self::route($base, $key);
        try {
            return $fn($conn, $table);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                '[shard:' . ($conn ?? 'default') . ".{$table}] " . $e->getMessage(), 0, $e
            );
        }
    }

    /** @var array<string, true> worker 内已确保建表的基名 */
    private static array $ensured = [];

    /**
     * 按需开通分片表（后台开启分片后自动转移，无需预建）。
     *
     * 每个 worker 进程每个基名只检查一次；缺表即按规范 DDL 创建
     * （同库 LIKE 基表，跨库用内置 DDL），属主即当前连接账号。
     */
    public static function ensureTables(string $base): void
    {
        if (!self::enabled() || isset(self::$ensured[$base])) {
            return;
        }
        self::$ensured[$base] = true;

        $cfg = (array) config('sharding', []);
        $dbCount = max(1, (int) ($cfg['db_count'] ?? 2));
        $tableShards = max(1, (int) ($cfg['table_shards'] ?? 2));
        for ($d = 0; $d < $dbCount; $d++) {
            $conn = sprintf('shard_%02d', $d);
            for ($t = 0; $t < $tableShards; $t++) {
                $table = sprintf('%s_%02d', $base, $t);
                try {
                    $db = Db::connection($conn);
                    $exists = $db->select(
                        "SELECT to_regclass('public.{$table}') AS r"
                    );
                    if (($exists[0]['r'] ?? null) !== null) {
                        continue;
                    }
                    $db->statement(self::ddlFor($base, $table));
                } catch (\Throwable) {
                }
            }
        }
    }

    private static function ddlFor(string $base, string $table): string
    {
        if ($base === 'kode_notices') {
            return "CREATE TABLE IF NOT EXISTS {$table} (
                id BIGSERIAL PRIMARY KEY,
                title VARCHAR(128) NOT NULL,
                body TEXT NOT NULL,
                status SMALLINT NOT NULL DEFAULT 1,
                created_by VARCHAR(64) NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )";
        }

        return "CREATE TABLE IF NOT EXISTS {$table} (LIKE {$base} INCLUDING ALL)";
    }

    /**
     * 取分片库连接包装器（$conn 为 null 时返回默认 Db 门面，两者 select/insert/update/delete 同形）。
     */
    public static function db(?string $conn): object
    {
        if ($conn === null) {
            return new class {
                public function select(string $sql, array $b = []): array
                {
                    return \Kode\Database\Db\Db::select($sql, $b);
                }
                public function insert(string $sql, array $b = []): int|string
                {
                    return \Kode\Database\Db\Db::insert($sql, $b);
                }
                public function update(string $sql, array $b = []): int
                {
                    return \Kode\Database\Db\Db::update($sql, $b);
                }
                public function delete(string $sql, array $b = []): int
                {
                    return \Kode\Database\Db\Db::delete($sql, $b);
                }
            };
        }

        return Db::connection($conn);
    }
}
