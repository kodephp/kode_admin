<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

/**
 * 后台核心表：角色 / 用户角色关联 / 菜单（users 表见自带迁移）。
 */
final class CreateAdminCoreTables extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Schema $t): void {
            $t->id();
            $t->string('name', 64);
            $t->string('slug', 64);
            $t->uniqueKey('slug');
            $t->timestamps();
        });

        Schema::create('role_user', function (Schema $t): void {
            $t->id();
            $t->string('user_id', 64);
            $t->string('role_slug', 64);
        });

        Schema::create('menus', function (Schema $t): void {
            $t->id();
            $t->string('title', 64);
            $t->string('path', 255);
            $t->string('icon', 64);
            $t->string('plugin', 64);
            $t->integer('sort');
        });
    }

    public function down(): void
    {
        Schema::drop('menus');
        Schema::drop('role_user');
        Schema::drop('roles');
    }
}
