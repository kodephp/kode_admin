<?php

declare(strict_types=1);

namespace app\tasks;

use app\support\DbBackup;
use Kode\Framework\Scheduling\Attributes\Cron;
use Kode\Framework\Scheduling\Task;

/**
 * 每周备份：周日 2 点全库备份到 storage/backups（保留最近 4 份）。
 *
 * 运行：php kode cron（常驻调度器自动发现）。
 */
#[Cron('0 2 * * 0', name: 'weekly-backup', description: '每周日 2 点全库备份')]
final class BackupTask extends Task
{
    public function handle(): void
    {
        try {
            // 目录由 DbBackup 建（0775）并以 0600 落盘，任务不再自己 mv 临时文件
            $dest = DbBackup::run(null, (string) base_path('storage/backups'));

            $files = glob(dirname($dest) . '/kode_backup_*.sql') ?: [];
            rsort($files);
            foreach (array_slice($files, 4) as $old) {
                @unlink($old);
            }
            logger()->info('[task] 每周备份完成：' . basename($dest));
        } catch (\Throwable $e) {
            logger()->error('[task] 每周备份失败：' . $e->getMessage());
        }
    }
}
