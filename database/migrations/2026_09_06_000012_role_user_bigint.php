<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * role_user.user_id 改 BIGINT（与 users.id 同型；pgsql 隐式转换虽可用，但类型一致才是通用做法）。
 */
final class AlterRoleUserIdType extends Migration
{
    public function up(): void
    {
        Db::statement('ALTER TABLE kode_role_user ALTER COLUMN user_id TYPE BIGINT USING user_id::bigint');
    }

    public function down(): void
    {
    }
}
