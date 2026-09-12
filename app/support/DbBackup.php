<?php

declare(strict_types=1);

namespace app\support;

/**
 * 数据库备份核心（控制器 + 定时任务共用）。
 *
 * 表名白名单 + 全参数转义，用户输入永不进 shell；超 50MB 拒绝（大库走命令行）。
 */
final class DbBackup
{
    /** @var list<string> */
    public const TABLES = [
        'kode_users', 'kode_roles', 'kode_role_user', 'kode_menus', 'kode_notices',
        'kode_audit_logs', 'kode_audit_logs_00', 'kode_audit_logs_01', 'kode_audit_logs_02', 'kode_audit_logs_03',
        'kode_sys_configs', 'kode_depts', 'kode_plugins', 'migrations',
    ];

    /**
     * @param list<string>|null $tables null = 全部
     * @return string 备份文件路径
     */
    public static function run(?array $tables = null): string
    {
        $list = $tables ?? self::TABLES;
        foreach ($list as $table) {
            if (!preg_match('/^[a-z0-9_]{2,64}$/', (string) $table) || !in_array($table, self::TABLES, true)) {
                throw new \InvalidArgumentException("不允许备份的表：{$table}");
            }
        }

        $bin = (string) env('PG_DUMP_BIN', 'pg_dump');
        $file = sys_get_temp_dir() . '/kode_backup_' . date('Ymd_His') . '.sql';
        $cmd = sprintf(
            '%s -h %s -p %d -U %s -d %s %s > %s 2>&1',
            escapeshellarg($bin),
            escapeshellarg((string) env('DB_HOST', '127.0.0.1')),
            (int) env('DB_PORT', 5432),
            escapeshellarg((string) env('DB_USERNAME', 'root')),
            escapeshellarg((string) env('DB_DATABASE', 'kode_app')),
            implode(' ', array_map(static fn($t) => '-t ' . escapeshellarg($t), $list)),
            escapeshellarg($file)
        );
        $env = ['PGPASSWORD' => (string) env('DB_PASSWORD', ''), 'PATH' => '/usr/bin:/bin:/opt/homebrew/bin'];
        $code = 1;
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        if (is_resource($proc)) {
            $code = proc_close($proc);
        }
        if ($code !== 0 || !is_file($file) || filesize($file) === 0) {
            @unlink($file);
            throw new \RuntimeException('备份失败（检查 pg_dump 可用性与数据库权限）');
        }
        if (filesize($file) > 50 * 1024 * 1024) {
            @unlink($file);
            throw new \RuntimeException('库过大（>50MB），请用命令行 pg_dump 备份');
        }

        return $file;
    }
}
