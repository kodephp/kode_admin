<?php

declare(strict_types=1);

namespace app\tasks;

use app\support\Shard;
use Kode\Database\Db\Db;
use Kode\Framework\Scheduling\Attributes\Cron;
use Kode\Framework\Scheduling\Task;

/**
 * 审计保留期清理：每周一 3 点删除 90 天前审计（全部分片）。
 *
 * 运行：php kode cron（常驻调度器自动发现）。
 */
#[Cron('0 3 * * 1', name: 'audit-retention', description: '每周清理 90 天前审计日志')]
final class AuditCleanTask extends Task
{
    public function handle(): void
    {
        $deleted = 0;
        $tables = array_merge(['kode_audit_logs'], Shard::tables('kode_audit_logs'));
        foreach ($tables as $table) {
            try {
                $deleted += Db::delete(
                    "DELETE FROM {$table} WHERE created_at < NOW() - (90 * INTERVAL '1 day')"
                );
            } catch (\Throwable $e) {
                logger()->error('审计清理失败', ['table' => $table, 'error' => $e->getMessage()]);
            }
        }
        logger()->info("[task] 审计保留期清理完成：删除 {$deleted} 条");
    }
}
