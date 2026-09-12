<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

/**
 * 示例业务插件表：公告。
 */
final class CreateNoticesTable extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Schema $t): void {
            $t->id();
            $t->string('title', 128);
            $t->string('body', 2000);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('notices');
    }
}
