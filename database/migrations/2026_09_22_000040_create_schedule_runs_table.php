<?php

declare(strict_types=1);

use Kode\Database\Db\Db;
use Kode\Framework\Database\Migration;

/**
 * 调度执行历史表 kode_schedule_runs：由框架 ScheduleDispatcher 读写。
 *
 * 框架侧 logRun() 会在首次写入时 CREATE TABLE IF NOT EXISTS 兜底，但读历史
 * （runHistory / tenantRunHistory，后台「任务调度」页每次打开都调）早于任何一次写入时
 * 表还不存在，会持续刷 warning 并返回空列表。这里预先建表，让只读路径不再依赖写路径的副作用。
 *
 * DDL 与 vendor/kode/framework/src/Scheduling/ScheduleDispatcher.php::logRun() 保持一致；
 * 框架若变更该表结构，需同步本迁移（原生 SQL 建表，避开 Schema 链式 index 签名差异）。
 */
final class CreateScheduleRunsTable extends Migration
{
    public function up(): void
    {
        Db::statement("
            CREATE TABLE IF NOT EXISTS kode_schedule_runs (
                id BIGSERIAL PRIMARY KEY,
                task_name VARCHAR(128) NOT NULL,
                status VARCHAR(16) NOT NULL,
                duration_ms INTEGER NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                tenant_id INTEGER NOT NULL DEFAULT 0,
                node_id VARCHAR(128) NULL,
                started_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        Db::statement('CREATE INDEX IF NOT EXISTS idx_schedule_runs_task_name ON kode_schedule_runs (task_name, started_at DESC)');
        Db::statement('CREATE INDEX IF NOT EXISTS idx_schedule_runs_status ON kode_schedule_runs (status, started_at DESC)');

        foreach ([
            'COMMENT ON TABLE kode_schedule_runs IS \'调度任务执行历史\'',
            'COMMENT ON COLUMN kode_schedule_runs.task_name IS \'任务名\'',
            'COMMENT ON COLUMN kode_schedule_runs.status IS \'执行状态：success/failed/skipped\'',
            'COMMENT ON COLUMN kode_schedule_runs.duration_ms IS \'耗时（毫秒）\'',
            'COMMENT ON COLUMN kode_schedule_runs.error_message IS \'失败原因，成功为 NULL\'',
            'COMMENT ON COLUMN kode_schedule_runs.tenant_id IS \'归属租户，0 表示全局任务\'',
            'COMMENT ON COLUMN kode_schedule_runs.node_id IS \'执行节点标识，多机部署区分来源\'',
        ] as $ddl) {
            Db::statement($ddl);
        }
    }

    public function down(): void
    {
        Db::statement('DROP TABLE IF EXISTS kode_schedule_runs');
    }
}
