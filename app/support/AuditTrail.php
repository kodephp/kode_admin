<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;

/**
 * 操作审计流水的落片与写入（唯一口径）。
 *
 * 修的是「写了但永远看不见」：`AuditMiddleware` 按 `Shard::table()` 落分片表，
 * 而 `FeatureFlagController::logChange()` 自己写了一条 `INSERT INTO kode_audit_logs`（**基表**），
 * 所有读侧（`AuditController::index/mine/stats/overview`、`DashboardController`）只遍历
 * `Shard::tables()`（返回 `_00.._NN`，不含基表；schema.sql 的分片段就写明「新写入只走分片表」）。
 * 于是特性开关的变更在审计页查不到，基表实测积了 167 行、最新一条停在 2026-09-06，没有任何入口读它。
 *
 * 列宽截断也收在这里一份：超长值在 pgsql 是直接报错，而两个写入口都 `catch (\Throwable)`
 * 静默吞掉（审计失败不许影响主流程），等于「审计悄悄不记了」这种最难查的形状。
 *
 * 这里**没有** `Shard::ensureTables()`：审计的片表全在默认连接上（写侧用 `Db::`、读侧枚举
 * 的也是默认连接的 `_00.._NN`），而按需开通只对跨库基表有意义 —— 给非 `SHARDED_BASES` 的基表
 * 建片，DDL 会打在分片库里（那里连基表都没有，`LIKE` 直接 42P01），既建不出东西也建错了地方。
 * 审计片表的结构归 `database/migrations` 管（AGENTS.md §3「表结构变更两处同步」）。
 */
final class AuditTrail
{
    /** 审计基表名：分片表 = 基名 + `_NN`；基表只当结构参照与迁移前的历史行，新写入不落它。 */
    public const BASE = 'kode_audit_logs';

    /**
     * 这条审计行落在哪张片表。
     *
     * 分片键恒取归属键：有认证身份用 uid，否则退到 ip（否则所有匿名请求挤进同一片）。
     * uid 恒用令牌载荷里的原样字符串 —— 它是 `u_` 前缀的不透明串（AGENTS.md §4），
     * 早期写侧曾 `(int)` 转过一次，转完恒为 0，等于按 ip 落片，与 `AuditMiddleware` 落到不同表。
     *
     * @param mixed $uid 未登录传 0 或空串
     */
    public static function tableFor(mixed $uid, string $ip): string
    {
        $key = (string) $uid;

        return Shard::table(self::BASE, $key !== '' && $key !== '0' ? $key : $ip);
    }

    /**
     * 写一行审计元数据（只记元数据，禁止记请求体：AGENTS.md §3）。
     *
     * @return int 行 id（片表各有自己的自增序列，按它删除必须连 `tableFor()` 一起用）
     */
    public static function record(
        mixed $uid,
        string $username,
        string $method,
        string $path,
        int $status,
        int $durationMs,
        string $ip
    ): int {
        $table = self::tableFor($uid, $ip);

        return (int) Db::insert(
            "INSERT INTO {$table} (user_id, username, method, path, status, duration_ms, ip, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                Clip::chars((string) $uid, 64),
                Clip::chars($username, 64),
                Clip::chars($method, 16),
                Clip::chars($path, 255),
                $status,
                $durationMs,
                Clip::chars($ip, 64),
            ]
        );
    }
}
