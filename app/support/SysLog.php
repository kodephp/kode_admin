<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;

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
                    Clip::chars($level, 16), Clip::chars($module, 64), Clip::chars($message, 500),
                    // context 是 TEXT（没有列宽要防），这一刀限的是体积：切在半个汉字上
                    // 会让整条 INSERT 报 22021，而外面的 catch 把它吃成「日志没打出来」。
                    Clip::chars((string) json_encode($context), 8000),
                    Clip::chars(ClientIp::resolve(), 64),
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
