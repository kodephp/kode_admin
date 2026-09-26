<?php

declare(strict_types=1);

use Kode\Database\Db\Db;
use Kode\Framework\Database\Migration;

/**
 * 种子：迁移管理 + 特性开关 菜单项。
 *
 * 用原生 SQL 插入以规避菜单表 schema 差异（kode_menus 无 created_at/updated_at 字段）。
 */
final class SeedNewMenus extends Migration
{
    public function up(): void
    {
        $seeds = [
            ['title' => '迁移管理', 'path' => '/migrations', 'icon' => 'database', 'sort' => 90, 'auth' => 'menu.migrations'],
            ['title' => '特性开关', 'path' => '/feature-flags', 'icon' => 'experiment', 'sort' => 95, 'auth' => 'menu.feature_flags'],
        ];
        foreach ($seeds as $s) {
            if (Db::select('SELECT 1 FROM kode_menus WHERE path = ?', [$s['path']]) !== []) {
                continue;
            }
            Db::insert(
                "INSERT INTO kode_menus (title, path, icon, plugin, sort, auth_code)
                 VALUES (?, ?, ?, 'core', ?, ?)",
                [$s['title'], $s['path'], $s['icon'], $s['sort'], $s['auth']]
            );
        }
    }

    public function down(): void
    {
        Db::delete("DELETE FROM kode_menus WHERE path IN ('/migrations', '/feature-flags')");
    }
}
