<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 补字典菜单（内容管理，复用 notices:view；独立字典码后续可在菜单管理里改）。
 */
final class SeedDictMenu extends Migration
{
    public function up(): void
    {
        $exists = Db::select('SELECT id FROM kode_menus WHERE path = ? LIMIT 1', ['/dicts']);
        if ($exists === []) {
            Db::insert(
                'INSERT INTO kode_menus (title, path, icon, plugin, sort, auth_code) VALUES (?, ?, ?, ?, ?, ?)',
                ['字典管理', '/dicts', 'book', 'system', 36, 'notices:view']
            );
        }
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
