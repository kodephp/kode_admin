<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 再次下线「配置中心」菜单（/configs）。
 *
 * 背景：000020 已删除该菜单，但 000021（profile_config）与 000034（catalogs）
 * 又把它重新写回，前端 `views/kode/configs/index.vue` 后来被删除
 * （自定义配置本就归设置中心 custom 分组维护，两页职责重复）。
 * 菜单还在库里而组件已不存在，点击会解析不到模块，故在此收口。
 *
 * 说明：只删菜单行，不动配置数据（`kode_configs` 表与 `/api/configs` API 全部保留），
 * 自定义配置项继续在「系统设置」页的 custom 分组维护。
 */
final class DropConfigsMenuAgain extends Migration
{
    public function up(): void
    {
        // 先摘掉指向它的角色-菜单授权，避免留下孤儿外键。
        // 注意：Db::statement() 不带绑定参数，凡有占位符的语句必须走 delete()/update()。
        foreach (['kode_role_menus', 'kode_menu_roles'] as $pivot) {
            $cols = Db::select(
                'SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position',
                [$pivot]
            );
            if (!in_array('menu_id', array_column($cols, 'column_name'), true)) {
                continue;
            }
            $idCol = in_array('id', array_column($cols, 'column_name'), true) ? 'id' : null;
            $expr = $idCol !== null ? "{$pivot}.{$idCol}" : 'kode_role_menus.menu_id';
            Db::delete(
                "DELETE FROM {$pivot} USING kode_menus WHERE {$expr} = kode_menus.id AND kode_menus.path = ?",
                ['/configs']
            );
        }

        Db::delete('DELETE FROM kode_menus WHERE path = ?', ['/configs']);
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
        // 幂等：仅当缺失时补回，component 指向系统设置页（自定义配置的实际归属）。
        $exists = Db::select('SELECT id FROM kode_menus WHERE path = ? LIMIT 1', ['/configs']);
        if ($exists !== []) {
            return;
        }
        $system = Db::select('SELECT id FROM kode_menus WHERE path = ? LIMIT 1', ['/system']);
        $parentId = isset($system[0]['id']) ? (int) $system[0]['id'] : null;
        Db::insert(
            'INSERT INTO kode_menus (title, path, icon, plugin, sort, auth_code, name, component, parent_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['配置中心', '/configs', 'setting', 'system', 70, 'configs:view', 'Setting', '/kode/settings/index', $parentId]
        );
        \app\support\HotCache::forget('menus:tree');
    }
}
