<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 补运维三菜单：定时任务 / 服务器监控 / 数据库管理。
 */
final class SeedOpsMonitorMenus extends Migration
{
    public function up(): void
    {
        $menus = [
            ['定时任务', '/schedules', 'clock', 'system', 82, 'audits:view'],
            ['服务器监控', '/monitor', 'monitor', 'system', 83, 'audits:view'],
            ['数据库管理', '/db', 'database', 'system', 84, 'audits:view'],
        ];
        foreach ($menus as [$title, $path, $icon, $plugin, $sort, $code]) {
            $exists = Db::select('SELECT id FROM kode_menus WHERE path = ? LIMIT 1', [$path]);
            if ($exists !== []) {
                continue;
            }
            Db::insert(
                'INSERT INTO kode_menus (title, path, icon, plugin, sort, auth_code) VALUES (?, ?, ?, ?, ?, ?)',
                [$title, $path, $icon, $plugin, $sort, $code]
            );
        }
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
