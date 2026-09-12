<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

/**
 * 示例迁移：创建 users 表。
 *
 * 运行：php kode migrate
 * 回滚：php kode migrate:rollback
 */
final class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Schema $t): void {
            $t->id();
            $t->string('name', 64);
            $t->string('email', 191);
            $t->uniqueKey('email');
            $t->string('password', 255);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('users');
    }
}
