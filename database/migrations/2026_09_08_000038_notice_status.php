<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 公告加 status（1 发布 / 0 草稿）。
 * 注意：跨库分片表（kode_app_00/01.*）需手动对齐；新分片按 Shard::ddlFor 建表自带。
 * （迁移内避免调用 Shard::tables，其连接切换会打断迁移事务。）
 */
final class NoticeStatus extends Migration
{
    public function up(): void
    {
        Db::statement('ALTER TABLE kode_notices ADD COLUMN IF NOT EXISTS status SMALLINT NOT NULL DEFAULT 1');
    }

    public function down(): void
    {
    }
}
