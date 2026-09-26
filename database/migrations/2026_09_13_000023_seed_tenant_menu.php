<?php

declare(strict_types=1);

use Kode\Database\Db\Db;
use Kode\Framework\Database\Migration;

/**
 * 补「租户管理」菜单（多租户架构入口）。
 */
final class SeedTenantMenu extends Migration
{
    public function up(): void
    {
        $exists = Db::select('SELECT id FROM kode_menus WHERE path = ? LIMIT 1', ['/tenants']);
        if ($exists !== []) {
            return;
        }
        Db::insert(
            'INSERT INTO kode_menus (title, path, icon, plugin, sort, auth_code) VALUES (?, ?, ?, ?, ?, ?)',
            ['租户管理', '/tenants', 'shop', 'system', 85, 'audits:view']
        );
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
