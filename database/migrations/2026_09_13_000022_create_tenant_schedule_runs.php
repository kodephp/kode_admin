<?php

declare(strict_types=1);

use Kode\Database\Db\Db;
use Kode\Framework\Database\Migration;

/**
 * 多租户定时任务执行记录：每租户每任务执行一条，支持审计与统计。
 */
final class CreateTenantScheduleRunsTable extends Migration
{
    public function up(): void
    {
        Db::statement("
            CREATE TABLE IF NOT EXISTS kode_tenant_schedule_runs (
                id BIGSERIAL PRIMARY KEY,
                task_name VARCHAR(64) NOT NULL,
                tenant_name VARCHAR(64) NOT NULL,
                tenant_id VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                message TEXT NOT NULL DEFAULT '',
                duration_ms INT NOT NULL DEFAULT 0,
                executed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        Db::statement("
            CREATE INDEX IF NOT EXISTS idx_tenant_schedule_runs_task
            ON kode_tenant_schedule_runs (task_name, executed_at DESC)
        ");
        Db::statement("
            CREATE INDEX IF NOT EXISTS idx_tenant_schedule_runs_tenant
            ON kode_tenant_schedule_runs (tenant_id, executed_at DESC)
        ");
    }

    public function down(): void
    {
        Db::statement('DROP TABLE IF EXISTS kode_tenant_schedule_runs');
    }
}
