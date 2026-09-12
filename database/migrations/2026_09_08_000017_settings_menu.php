<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 补系统设置菜单（归系统管理，复用 configs:view 权限码）。
 */
final class SeedSettingsMenu extends Migration
{
    public function up(): void
    {
        $exists = Db::select('SELECT id FROM kode_menus WHERE path = ? LIMIT 1', ['/settings']);
        if ($exists === []) {
            Db::insert(
                'INSERT INTO kode_menus (title, path, icon, plugin, sort, auth_code) VALUES (?, ?, ?, ?, ?, ?)',
                ['系统设置', '/settings', 'setting', 'system', 75, 'configs:view']
            );
        }
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
