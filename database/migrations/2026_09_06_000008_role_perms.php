<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 角色权限字段（JSON 数组字符串，如 ["user:create","user:delete"]）。
 */
final class AddRolePermsColumn extends Migration
{
    public function up(): void
    {
        Db::statement('ALTER TABLE roles ADD COLUMN IF NOT EXISTS perms TEXT NOT NULL DEFAULT \'[]\'');
    }

    public function down(): void
    {
    }
}
