<?php

declare(strict_types=1);

use Kode\Database\Db\Db;
use Kode\Framework\Database\Seeder;

/**
 * 后台初始数据：超管角色 + admin 账号 + 核心菜单。
 *
 * 默认账号 admin / ChangeMe123!（首次登录后立即修改）。
 */
final class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Db::statement('DELETE FROM kode_role_user');
        Db::statement('DELETE FROM kode_menus');
        Db::statement('DELETE FROM kode_roles');
        Db::statement('DELETE FROM kode_users');

        Db::insert(
            'INSERT INTO kode_roles (name, slug, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
            ['超级管理员', 'admin']
        );

        $uid = Db::insert(
            'INSERT INTO kode_users (name, email, password, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
            ['admin', 'admin@local', password_hash('ChangeMe123!', PASSWORD_DEFAULT)]
        );

        Db::insert(
            'INSERT INTO kode_role_user (user_id, role_slug) VALUES (?, ?)',
            [(string) $uid, 'admin']
        );

        $menus = [
            ['仪表盘', '/dashboard', 'dashboard', 'system', 1, '', 'Dashboard', ''],
            ['分析页', '/dashboard/analytics', 'area-chart', 'system', 5, 'dashboard:view', 'Analytics', '/dashboard/analytics/index'],
            ['工作台', '/dashboard/workspace', 'workspace', 'system', 6, 'dashboard:view', 'Workspace', '/dashboard/workspace/index'],
            ['系统管理', '/system', 'setting', 'system', 10, '', 'System', ''],
            ['用户管理', '/system/user', 'user', 'system', 20, 'users:view', 'SystemUser', '/sys/user/list'],
            ['角色管理', '/system/role', 'team', 'system', 30, 'roles:view', 'SystemRole', '/sys/role/list'],
            ['菜单管理', '/system/menu', 'menu', 'system', 40, 'menus:view', 'SystemMenu', '/sys/menu/list'],
            ['部门管理', '/system/dept', 'apartment', 'system', 60, 'depts:view', 'SystemDept', '/sys/dept/list'],
            ['系统设置', '/settings', 'setting', 'system', 70, 'configs:view', 'Setting', '/kode/settings/index'],
            ['存储配置', '/storage', 'cloud', 'system', 76, 'configs:view', 'Storage', '/kode/storage/index'],
            ['运维监控', '/ops', 'activity', 'system', 20, '', 'Ops', ''],
            ['插件管理', '/plugins', 'appstore', 'system', 50, 'plugins:view', 'Plugins', '/kode/plugins/index'],
            ['审计日志', '/audits', 'file-text', 'system', 80, 'audits:view', 'Audits', '/kode/audits/index'],
            ['系统日志', '/syslogs', 'file-search', 'system', 81, 'audits:view', 'Syslogs', '/kode/syslogs/index'],
            ['定时任务', '/schedules', 'clock', 'system', 82, 'audits:view', 'Schedules', '/kode/schedules/index'],
            ['服务器监控', '/monitor', 'monitor', 'system', 83, 'audits:view', 'Monitor', '/kode/monitor/index'],
            ['数据库管理', '/db', 'database', 'system', 84, 'audits:view', 'Db', '/kode/db/index'],
            ['内容管理', '/cms', 'newspaper', 'system', 30, '', 'Cms', ''],
            ['媒体库', '/media', 'picture', 'system', 35, 'notices:view', 'Media', '/kode/media/index'],
            ['字典管理', '/dicts', 'book', 'system', 36, 'notices:view', 'Dicts', '/kode/dicts/index'],
            ['通知中心', '/notifications', 'bell', 'system', 37, 'notices:view', 'Notify', '/kode/notifications/index'],
        ];
        $parents = [
            '/dashboard' => ['/dashboard/analytics', '/dashboard/workspace'],
            '/system' => ['/system/user', '/system/role', '/system/menu', '/system/dept', '/settings', '/storage'],
            '/ops' => ['/plugins', '/audits', '/syslogs', '/schedules', '/monitor', '/db'],
            // 注：/notices 由 notices 插件 menus() 声明（自带 parent='/cms'），不入库，故不在此列。
            '/cms' => ['/media', '/dicts', '/notifications'],
        ];
        foreach ($menus as [$title, $path, $icon, $plugin, $sort, $code, $name, $component]) {
            Db::insert(
                'INSERT INTO kode_menus (title, path, icon, plugin, sort, auth_code, name, component) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$title, $path, $icon, $plugin, $sort, $code, $name, $component]
            );
        }
        $idByPath = [];
        foreach (Db::select('SELECT id, path FROM kode_menus') as $row) {
            $idByPath[$row['path']] = (int) $row['id'];
        }
        foreach ($parents as $parent => $children) {
            foreach ($children as $path) {
                if (isset($idByPath[$parent], $idByPath[$path]) && $idByPath[$parent] !== $idByPath[$path]) {
                    Db::update('UPDATE kode_menus SET parent_id = ? WHERE id = ?', [$idByPath[$parent], $idByPath[$path]]);
                }
            }
        }
    }
}
