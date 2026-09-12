<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

/**
 * 插件市场表：已安装插件 + 状态（enabled/paused/uninstalled）+ 版本。
 */
final class CreatePluginsTable extends Migration
{
    public function up(): void
    {
        Schema::create('plugins', function (Schema $t): void {
            $t->id();
            $t->string('name', 64);
            $t->uniqueKey('name');
            $t->string('version', 32);
            $t->string('status', 16);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('plugins');
    }
}
