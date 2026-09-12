<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 系统管理菜单路径对齐官方演示（/system/user 等 canonical 路由）。
 */
final class SystemRoutePaths extends Migration
{
    public function up(): void
    {
        $map = [
            '/users' => '/system/user',
            '/roles' => '/system/role',
            '/menus' => '/system/menu',
            '/depts' => '/system/dept',
        ];
        foreach ($map as $from => $to) {
            Db::update('UPDATE kode_menus SET path = ? WHERE path = ?', [$to, $from]);
        }
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
