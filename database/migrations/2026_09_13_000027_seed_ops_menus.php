<?php

declare(strict_types=1);

use Kode\Database\Db\Db;
use Kode\Framework\Database\Migration;

/**
 * 种子：会话管理 + 错误分析 + 备份管理 菜单项。
 *
 * 用原生 SQL 插入以规避菜单表 schema 差异（kode_menus 无 created_at/updated_at 字段）。
 */
final class SeedOpsMenus extends Migration
{
    public function up(): void
    {
        $seeds = [
            ['title' => '会话管理', 'path' => '/sessions', 'icon' => 'user', 'sort' => 100, 'auth' => 'menu.sessions'],
            ['title' => '错误分析', 'path' => '/error-analytics', 'icon' => 'warning', 'sort' => 105, 'auth' => 'menu.error_analytics'],
            ['title' => '备份管理', 'path' => '/backups', 'icon' => 'database', 'sort' => 110, 'auth' => 'menu.backups'],
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
        Db::delete("DELETE FROM kode_menus WHERE path IN ('/sessions', '/error-analytics', '/backups')");
    }
}
