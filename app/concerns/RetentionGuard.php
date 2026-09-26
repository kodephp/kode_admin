<?php

declare(strict_types=1);

namespace app\concerns;

/**
 * 保留期入参闸门（清理类接口共用）。
 *
 * 为什么单独成一份：`min(3650, max(7, (int) query('days', 90)))` 看着是兜底，
 * 实际是替用户做决定 —— 漏传、传 0、传负数统统被夹成「删掉 7 天前的全部日志」，
 * 一次手错就是一场无告警的批量删除。口径同 `BackupController::clean`（AGENTS.md §4）：
 * 越界一律 400 拒掉，让用户改参数。
 *
 * 三个入口共用这一份判定（审计日志 / 系统日志 / 登录日志），
 * 免得哪天只改对一个，剩下两个继续静默夹取。
 */
trait RetentionGuard
{
    /** 保留期下限：更激进的清理不走这个默认入口。 */
    private const RETENTION_MIN_DAYS = 7;

    /** 保留期上限（十年）。 */
    private const RETENTION_MAX_DAYS = 3650;

    /**
     * 判定 `days`：合法返回天数，非法返回原因（两者互斥）。
     *
     * @return array{days: int, error: string|null}
     */
    protected function retentionDays(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['days' => 0, 'error' => 'days 必填：清理多少天之前的记录'];
        }
        if (!is_numeric($raw) || (float) $raw !== (float) (int) $raw) {
            return ['days' => 0, 'error' => 'days 必须是整数天数'];
        }
        $days = (int) $raw;
        if ($days < self::RETENTION_MIN_DAYS || $days > self::RETENTION_MAX_DAYS) {
            return [
                'days' => 0,
                'error' => sprintf(
                    'days 须在 %d..%d 之间（不会替你夹取，避免一次误删）',
                    self::RETENTION_MIN_DAYS,
                    self::RETENTION_MAX_DAYS
                ),
            ];
        }

        return ['days' => $days, 'error' => null];
    }
}
