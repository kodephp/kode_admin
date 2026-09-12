<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;
use Kode\Http\Request;

/**
 * 系统日志打点（失败绝不影响主流程）。
 */
final class SysLog
{
    public static function write(string $level, string $module, string $message, array $context = []): void
    {
        try {
            Db::insert(
                'INSERT INTO kode_system_logs (level, module, message, context, ip, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
                [
                    substr($level, 0, 16), substr($module, 0, 64), substr($message, 0, 500),
                    substr((string) json_encode($context), 0, 8000),
                    substr((string) (Request::ip() ?? ''), 0, 64),
                ]
            );
        } catch (\Throwable) {
        }
    }

    public static function info(string $module, string $message, array $context = []): void
    {
        self::write('info', $module, $message, $context);
    }

    public static function error(string $module, string $message, array $context = []): void
    {
        self::write('error', $module, $message, $context);
    }
}
