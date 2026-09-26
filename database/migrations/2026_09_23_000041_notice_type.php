<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 公告加 type（announcement 公告 / internal 内部通知），与 NoticePlugin v1.1.0 的读写口径对齐。
 *
 * 为什么要进迁移：type 原先只由插件 install()/upgrade() 补，而 install() 只在「装插件」那一刻跑，
 * upgrade() 只在「点升级」那一刻跑 —— 于是既有库里 kode_notices 没这列，公告列表与导出
 * （SELECT id, title, body, type …）直接 42703 报错。表结构的口径归迁移（AGENTS：两处同步）。
 *
 * 注意：跨库分片表（kode_app_00/01.*）不在此列——迁移内切连接会打断迁移事务，
 *      而且分片库里根本没有基表可 ALTER。片表的列缺口由 `Shard::ensureTables()` 在
 *      读写入口按主库基表的真实列集合补齐（只加不删），`php kode shard:init` 会顺带跑一次
 *      并汇报「补了什么/还缺什么」；只读口径见 `Shard::drift()` 与 `GET /api/shard/config`
 *      的 `column_drift`。回归见 tests/ShardColumnAlignTest.php。
 */
final class NoticeType extends Migration
{
    public function up(): void
    {
        Db::statement("ALTER TABLE kode_notices ADD COLUMN IF NOT EXISTS type VARCHAR(24) NOT NULL DEFAULT 'announcement'");
        Db::statement('CREATE INDEX IF NOT EXISTS idx_kode_notices_type_status ON kode_notices (type, status)');
    }

    public function down(): void
    {
        // 列保留：删列会连带丢数据，回滚窗口里也没有需要它消失的场景
    }
}
