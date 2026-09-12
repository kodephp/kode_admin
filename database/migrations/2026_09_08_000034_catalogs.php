<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 一级目录进库：仪表盘 / 系统管理 / 运维监控 / 内容管理；
 * 存量菜单按归属挂 parent_id（前端分组逻辑同步退役，直通后端树）。
 */
final class SeedCatalogMenus extends Migration
{
    public function up(): void
    {
        $catalogs = [
            ['仪表盘', '/dashboard', 'dashboard', 'system', 1],
            ['系统管理', '/system', 'setting', 'system', 10],
            ['运维监控', '/ops', 'activity', 'system', 20],
            ['内容管理', '/cms', 'newspaper', 'system', 30],
        ];
        $ids = [];
        foreach ($catalogs as [$title, $path, $icon, $plugin, $sort]) {
            $exists = Db::select('SELECT id FROM kode_menus WHERE path = ? LIMIT 1', [$path]);
            if ($exists !== []) {
                $ids[$path] = (int) $exists[0]['id'];
                continue;
            }
            $ids[$path] = (int) Db::insert(
                'INSERT INTO kode_menus (title, path, icon, plugin, sort, auth_code, status) VALUES (?, ?, ?, ?, ?, ?, 1)',
                [$title, $path, $icon, $plugin, $sort, '']
            );
        }
        $mapping = [
            '/dashboard' => ['/dashboard/analytics', '/dashboard/workspace'],
            '/system' => ['/system/user', '/system/role', '/system/menu', '/system/dept', '/configs', '/settings', '/storage'],
            '/ops' => ['/plugins', '/audits', '/syslogs', '/schedules', '/monitor', '/db'],
            '/cms' => ['/notices', '/media', '/dicts', '/notifications'],
        ];
        foreach ($mapping as $parent => $children) {
            foreach ($children as $path) {
                Db::update(
                    'UPDATE kode_menus SET parent_id = ? WHERE path = ? AND id <> ?',
                    [$ids[$parent], $path, $ids[$parent]]
                );
            }
        }
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
