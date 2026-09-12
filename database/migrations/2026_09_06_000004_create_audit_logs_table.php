<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

/**
 * 操作审计日志表（只记元数据，不记请求体/密码）。
 */
final class CreateAuditLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Schema $t): void {
            $t->id();
            $t->string('user_id', 64);
            $t->string('username', 64);
            $t->string('method', 16);
            $t->string('path', 255);
            $t->integer('status');
            $t->integer('duration_ms');
            $t->string('ip', 64);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('audit_logs');
    }
}
