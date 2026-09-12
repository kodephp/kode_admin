<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

/**
 * 系统配置 + 部门表；用户归属部门列。
 */
final class CreateConfigDeptTables extends Migration
{
    public function up(): void
    {
        Schema::create('sys_configs', function (Schema $t): void {
            $t->id();
            $t->string('ckey', 128);
            $t->uniqueKey('ckey');
            $t->string('cvalue', 2000);
            $t->string('remark', 255);
            $t->timestamps();
        });

        Schema::create('depts', function (Schema $t): void {
            $t->id();
            $t->string('name', 64);
            $t->integer('parent_id');
            $t->integer('sort');
            $t->timestamps();
        });

        \Kode\Database\Db\Db::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS dept_id BIGINT NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        Schema::drop('depts');
        Schema::drop('sys_configs');
    }
}
