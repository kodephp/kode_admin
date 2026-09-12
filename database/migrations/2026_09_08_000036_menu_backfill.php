<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 存量菜单回填 name/component（与 AdminSeeder 新环境一致）。
 */
final class MenuRouteBackfill extends Migration
{
    public function up(): void
    {
        $map = [
            '/dashboard' => ['Dashboard', ''],
            '/dashboard/analytics' => ['Analytics', '/dashboard/analytics/index'],
            '/dashboard/workspace' => ['Workspace', '/dashboard/workspace/index'],
            '/system' => ['System', ''],
            '/system/user' => ['SystemUser', '/sys/user/list'],
            '/system/role' => ['SystemRole', '/sys/role/list'],
            '/system/menu' => ['SystemMenu', '/sys/menu/list'],
            '/system/dept' => ['SystemDept', '/sys/dept/list'],
            '/configs' => ['ConfigCenter', '/kode/configs/index'],
            '/settings' => ['Setting', '/kode/settings/index'],
            '/storage' => ['Storage', '/kode/storage/index'],
            '/ops' => ['Ops', ''],
            '/plugins' => ['Plugins', '/kode/plugins/index'],
            '/audits' => ['Audits', '/kode/audits/index'],
            '/syslogs' => ['Syslogs', '/kode/syslogs/index'],
            '/schedules' => ['Schedules', '/kode/schedules/index'],
            '/monitor' => ['Monitor', '/kode/monitor/index'],
            '/db' => ['Db', '/kode/db/index'],
            '/cms' => ['Cms', ''],
            '/media' => ['Media', '/kode/media/index'],
            '/dicts' => ['Dicts', '/kode/dicts/index'],
            '/notifications' => ['Notify', '/kode/notifications/index'],
        ];
        foreach ($map as $path => [$name, $component]) {
            Db::update(
                'UPDATE kode_menus SET name = ?, component = ? WHERE path = ? AND name = ?',
                [$name, $component, $path, '']
            );
        }
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
