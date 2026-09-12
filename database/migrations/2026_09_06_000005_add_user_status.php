<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 用户状态列（1 正常 / 0 禁用），禁用账号拒绝登录。
 */
final class AddUserStatusColumn extends Migration
{
    public function up(): void
    {
        Db::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS status SMALLINT NOT NULL DEFAULT 1');
    }

    public function down(): void
    {
    }
}
