<?php

declare(strict_types=1);

namespace app\tasks;

use app\support\AuditTrail;
use app\support\Shard;
use Kode\Framework\Scheduling\Attributes\Cron;
use Kode\Framework\Scheduling\Task;

/**
 * 审计保留期清理：每周一 3 点删除 90 天前审计（全部分片）。
 *
 * 逐片派发走 `Shard::scan()`：旧写法是每片一个 try/catch 只 `logger()->error()` 一句
 * 「审计清理失败」，最后一行 `deleted` 照旧汇总成功那几张 —— 跑日志的人看到的是「清完了」，
 * 实际有一张片一张都没动。现在缺片/连不上的片按 `[连接.表] + 原因` 逐条落日志，
 * 汇总行也带上失败片数。
 *
 * 运行：php kode cron（常驻调度器自动发现）。
 */
#[Cron('0 3 * * 1', name: 'audit-retention', description: '每周清理 90 天前审计日志')]
final class AuditCleanTask extends Task
{
    public function handle(): void
    {
        // 基表也要清：迁移前的历史行留在它上面（新写入只落片表，见 AuditTrail）
        $units = [[null, AuditTrail::BASE, 0, 0]];
        foreach (Shard::readUnits(AuditTrail::BASE, false, false) as $unit) {
            $units[] = $unit;
        }

        $scan = Shard::scan($units, static function (?string $conn, string $table): int {
            return Shard::db($conn)->delete(
                "DELETE FROM {$table} WHERE created_at < NOW() - (90 * INTERVAL '1 day')"
            );
        });

        $deleted = 0;
        foreach ($scan['results'] as $rows) {
            $deleted += (int) $rows;
        }

        foreach ($scan['unreadable'] as $bad) {
            logger()->error('审计清理失败', ['shard' => $bad['shard'], 'error' => $bad['reason'], 'code' => $bad['code']]);
        }

        $failed = count($scan['unreadable']);
        logger()->info($failed === 0
            ? "[task] 审计保留期清理完成：删除 {$deleted} 条"
            : "[task] 审计保留期清理**不完整**：删除 {$deleted} 条，另有 {$failed} 张表未能清理（逐条原因见上一条错误日志）");
    }
}
