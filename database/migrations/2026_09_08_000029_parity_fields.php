<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 对齐官方演示字段：用户备注、角色状态、部门状态/备注。
 */
final class OfficialParityFields extends Migration
{
    public function up(): void
    {
        Db::statement("ALTER TABLE kode_users ADD COLUMN IF NOT EXISTS remark VARCHAR(255) NOT NULL DEFAULT ''");
        Db::statement('ALTER TABLE kode_roles ADD COLUMN IF NOT EXISTS status SMALLINT NOT NULL DEFAULT 1');
        Db::statement("ALTER TABLE kode_roles ADD COLUMN IF NOT EXISTS remark VARCHAR(255) NOT NULL DEFAULT ''");
        Db::statement('ALTER TABLE kode_depts ADD COLUMN IF NOT EXISTS status SMALLINT NOT NULL DEFAULT 1');
        Db::statement("ALTER TABLE kode_depts ADD COLUMN IF NOT EXISTS remark VARCHAR(255) NOT NULL DEFAULT ''");
    }

    public function down(): void
    {
    }
}
