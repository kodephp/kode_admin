<?php

declare(strict_types=1);

use Kode\Database\Db\Db;
use Kode\Framework\Database\Migration;

/**
 * 定时任务执行历史表：记录非租户任务执行记录。
 *
 * 用原生 SQL 建表 + 单条索引语句，避免 Schema 链式 ->index()（Laravel API）
 * 与 kode Schema 签名不匹配。
 */
final class CreateCronRunsTable extends Migration
{
    public function up(): void
    {
        Db::statement("
            CREATE TABLE IF NOT EXISTS kode_cron_runs (
                id BIGSERIAL PRIMARY KEY,
                task_name VARCHAR(128) NOT NULL DEFAULT '',
                status VARCHAR(16) NOT NULL DEFAULT '',
                message VARCHAR(512) NOT NULL DEFAULT '',
                duration_ms INTEGER NOT NULL DEFAULT 0,
                executed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        Db::statement('CREATE INDEX IF NOT EXISTS idx_cron_task_name ON kode_cron_runs (task_name)');
        Db::statement('CREATE INDEX IF NOT EXISTS idx_cron_status ON kode_cron_runs (status)');
        Db::statement('CREATE INDEX IF NOT EXISTS idx_cron_executed_at ON kode_cron_runs (executed_at)');
        Db::statement('CREATE INDEX IF NOT EXISTS idx_cron_task_executed ON kode_cron_runs (task_name, executed_at)');
    }

    public function down(): void
    {
        Db::statement('DROP TABLE IF EXISTS kode_cron_runs');
    }
}
