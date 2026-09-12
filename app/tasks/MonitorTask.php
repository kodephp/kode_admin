<?php

declare(strict_types=1);

namespace app\tasks;

use Kode\Framework\Scheduling\Attributes\Cron;

/**
 * 示例定时任务：方法级 #[Cron]，一个类里挂多条任务。
 *
 * 类级与方法级可混用：把相关性强的任务收在同一个类里，各自独立调度。
 */
final class MonitorTask
{
    /**
     * 每 5 分钟上报一次健康心跳。
     */
    #[Cron('*/5 * * * *', name: 'health-ping', description: '每 5 分钟上报健康心跳')]
    public function ping(): void
    {
        logger()->debug('[task] 健康心跳上报');
    }

    /**
     * 每小时汇总一次运行指标。
     */
    #[Cron('0 * * * *', name: 'metrics-summary', description: '每小时汇总运行指标')]
    public function metrics(): void
    {
        logger()->info('[task] 汇总运行指标');
    }
}
