<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 公告归属列（分片键 + 属主鉴权依据）。
 */
final class AddNoticeOwnerColumn extends Migration
{
    public function up(): void
    {
        Db::statement('ALTER TABLE notices ADD COLUMN IF NOT EXISTS created_by VARCHAR(64) NOT NULL DEFAULT \'\'');
    }

    public function down(): void
    {
    }
}
