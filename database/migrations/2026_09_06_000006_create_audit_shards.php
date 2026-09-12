<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

/**
 * 审计日志分片表（4 片，按 user_id 哈希，见 app\support\Shard）。
 *
 * 旧 audit_logs 单表保留（历史数据），新写入只走分片表。
 */
final class CreateAuditLogShards extends Migration
{
    public function up(): void
    {
        foreach (\app\support\Shard::tables('audit_logs') as $table) {
            Schema::create($table, function (Schema $t): void {
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
    }

    public function down(): void
    {
        foreach (\app\support\Shard::tables('audit_logs') as $table) {
            Schema::drop($table);
        }
    }
}
