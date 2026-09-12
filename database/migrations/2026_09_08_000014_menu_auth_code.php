<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 菜单表加 auth_code（权限码，空=登录可见；非空=需角色持有该码），并给核心菜单播种。
 * 角色 perms 与菜单 auth_code 关联：GET /api/perms/catalog 下发分组目录供前端勾选。
 */
final class MenuAuthCode extends Migration
{
    public function up(): void
    {
        Db::statement("ALTER TABLE kode_menus ADD COLUMN IF NOT EXISTS auth_code VARCHAR(128) NOT NULL DEFAULT ''");
        $seeds = [
            '/dashboard' => 'dashboard:view',
            '/users' => 'users:view',
            '/roles' => 'roles:view',
            '/menus' => 'menus:view',
            '/plugins' => 'plugins:view',
            '/depts' => 'depts:view',
            '/configs' => 'configs:view',
            '/audits' => 'audits:view',
            '/notices' => 'notices:view',
        ];
        foreach ($seeds as $path => $code) {
            Db::update(
                'UPDATE kode_menus SET auth_code = ? WHERE path = ? AND (auth_code IS NULL OR auth_code = ?)',
                [$code, $path, '']
            );
        }
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
