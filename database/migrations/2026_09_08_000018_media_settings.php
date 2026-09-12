<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 媒体库菜单 + 补充默认配置（幂等）。
 */
final class SeedMediaAndSettings extends Migration
{
    public function up(): void
    {
        $exists = Db::select('SELECT id FROM kode_menus WHERE path = ? LIMIT 1', ['/media']);
        if ($exists === []) {
            Db::insert(
                'INSERT INTO kode_menus (title, path, icon, plugin, sort, auth_code) VALUES (?, ?, ?, ?, ?, ?)',
                ['媒体库', '/media', 'picture', 'system', 35, 'notices:view']
            );
        }
        $configs = [
            ['site.icp', '', '站点备案号'],
            ['site.description', '', '站点描述'],
            ['upload.allow_exts', '', '允许扩展名（逗号分隔，空=内置白名单）'],
        ];
        foreach ($configs as [$key, $value, $remark]) {
            $exists = Db::select('SELECT id FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);
            if ($exists !== []) {
                continue;
            }
            Db::insert(
                'INSERT INTO kode_sys_configs (ckey, cvalue, remark, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
                [$key, $value, $remark]
            );
        }
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
