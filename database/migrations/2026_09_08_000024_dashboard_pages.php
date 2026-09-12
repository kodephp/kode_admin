<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 仪表盘改为目录双页：分析页 /dashboard/analytics + 工作台 /dashboard/workspace
 *（模板自带视图，前端按前缀收纳进「仪表盘」目录）。
 */
final class SeedDashboardPages extends Migration
{
    public function up(): void
    {
        Db::delete('DELETE FROM kode_menus WHERE path = ?', ['/dashboard']);
        $pages = [
            ['分析页', '/dashboard/analytics', 'area-chart', 'system', 5, 'dashboard:view'],
            ['工作台', '/dashboard/workspace', 'workspace', 'system', 6, 'dashboard:view'],
        ];
        foreach ($pages as [$title, $path, $icon, $plugin, $sort, $code]) {
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
