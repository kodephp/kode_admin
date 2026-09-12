<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 补运维三菜单：部门管理 / 配置中心 / 审计日志（幂等，已存在 path 则跳过）。
 */
final class SeedOpsMenus extends Migration
{
    public function up(): void
    {
        $menus = [
            ['部门管理', '/depts', 'apartment', 'system', 60],
            ['配置中心', '/configs', 'setting', 'system', 70],
            ['审计日志', '/audits', 'file-text', 'system', 80],
        ];
        foreach ($menus as [$title, $path, $icon, $plugin, $sort]) {
            $exists = Db::select('SELECT id FROM kode_menus WHERE path = ? LIMIT 1', [$path]);
            if ($exists !== []) {
                continue;
            }
            Db::insert(
                'INSERT INTO kode_menus (title, path, icon, plugin, sort) VALUES (?, ?, ?, ?, ?)',
                [$title, $path, $icon, $plugin, $sort]
            );
        }
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
