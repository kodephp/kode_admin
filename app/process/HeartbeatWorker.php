<?php

declare(strict_types=1);

namespace app\process;

use Kode\Framework\Process\Worker;

/**
 * 演示常驻 worker：周期性把心跳写入 storage/heartbeat.log。
 *
 * 仅作示例。启用方式：取消 config/process.php 里 workers 的注释。
 * 验证（不 fork）：kode process:check
 * 启动（fork）  ：kode process:start
 */
final class HeartbeatWorker extends Worker
{
    public function name(): string
    {
        return 'heartbeat';
    }

    public function interval(): float
    {
        return 5.0;
    }

    public function handle(): void
    {
        $line = sprintf("[%s] heartbeat ok\n", date('Y-m-d H:i:s'));
        @file_put_contents(base_path('storage/heartbeat.log'), $line, FILE_APPEND);
    }
}
