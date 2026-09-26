<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;

/**
 * 权限授予面（角色编辑器能勾到的码）的唯一派生口。
 *
 * 为什么要有这个类：此前「谁能勾到什么码」只写在 `PermsController::catalog()` 里，
 * 而「路由门禁要求的码是否给得出去」判的是另一份集合（各插件 `permissions()` 的并集）。
 * 两张面从来没人对齐过，后果是插件路由上 17 个 RequirePerm 码里只有 6 个真能勾到：
 * `workflow:edit`、`notices:create`、`wallet:adjust` 一类的操作码在 kode_menus.auth_code、
 * kode_menu_buttons.code、插件 menus() 三张面里都不存在 —— 于是任何角色都拿不到它，
 * 非超管调写接口恒 403，而审计全绿（它比对的是声明面，不是授予面）。
 *
 * 所以现在授予面 = 这一个派生口，三张输入面：
 *  1. `kode_menus.auth_code`（菜单页码，含插件 menus() 落到表里的行）
 *  2. `kode_menu_buttons.code`（按钮级码）
 *  3. 各插件 `permissions()` 声明的操作码（补进所属插件分组，菜单里没有它们的行）
 *
 * 判据只写一份：`PluginContract::auditPermGates()` 与 `codes()` 用同一个集合，
 * 新增授予面只需要改这个类。
 */
final class PermCatalog
{
    /**
     * 按插件分组的勾选树（角色页数据源，出参形状保持稳定）。
     *
     * @return list<array{group: string, menus: list<array{code: string, title: string, path: string, buttons: list<array{code: string, title: string}>}>}>
     */
    public static function groups(): array
    {
        $rows = Db::select(
            "SELECT id, title, path, plugin, auth_code FROM kode_menus WHERE auth_code <> '' ORDER BY plugin, sort, id"
        );
        $btns = [];
        foreach (Db::select('SELECT menu_id, code, title FROM kode_menu_buttons ORDER BY menu_id, sort, id') as $b) {
            $btns[(int) $b['menu_id']][] = ['code' => (string) $b['code'], 'title' => (string) $b['title']];
        }

        // 插件 menus() 声明的码：菜单表里可能还没有对应行（首次安装前），但码现在就要能勾。
        // 枚举走 PluginMenus —— 和 `GET /api/menus` 的菜单面、菜单写侧的撞车判定同一个口，
        // 各自 `plugins()` 扫一遍正是「声明了却勾不到」那类错的温床。
        $declaredMenus = [];
        foreach (PluginMenus::declared() as $menu) {
            $code = (string) ($menu['auth_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $declaredMenus[] = [
                'title' => (string) ($menu['title'] ?? $menu['path']),
                'path' => (string) $menu['path'],
                'plugin' => (string) ($menu['plugin'] ?? ''),
                'auth_code' => $code,
            ];
        }

        $declaredPerms = [];
        try {
            /** @var \Kode\Framework\Plugin\PluginManager $manager */
            $manager = resolve(\Kode\Framework\Plugin\PluginManager::class);
            foreach ($manager->all() as $plugin) {
                $pluginName = (string) (method_exists($plugin, 'name') ? $plugin->name() : '');
                // 逐插件兜底：一个插件的 permissions() 抛错只让它自己缺席，
                // 不能连带丢掉后面所有插件的码（那会让授予面静默变小，正是本类要防的那种错）。
                try {
                    if ($pluginName !== '' && method_exists($plugin, 'permissions')) {
                        foreach ((array) $plugin->permissions() as $code) {
                            $code = trim((string) $code);
                            if ($code !== '') {
                                $declaredPerms[$pluginName][$code] = true;
                            }
                        }
                    }
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
            // 路由/插件表不可解析时不阻断：菜单码仍是一份可用的授予面
        }

        $groups = [];
        $seen = [];
        $add = static function (string $plugin, string $code, string $title, string $path, array $buttons) use (&$groups, &$seen): void {
            if ($code === '' || isset($seen[$plugin . "\0" . $code])) {
                return;
            }
            $seen[$plugin . "\0" . $code] = true;
            $groups[$plugin] ??= ['group' => $plugin, 'menus' => []];
            $groups[$plugin]['menus'][] = [
                'code' => $code,
                'title' => $title,
                'path' => $path,
                'buttons' => $buttons,
            ];
        };

        foreach ($rows as $row) {
            $add(
                (string) ($row['plugin'] ?? 'system'),
                (string) $row['auth_code'],
                (string) $row['title'],
                (string) $row['path'],
                $btns[(int) $row['id']] ?? []
            );
        }
        foreach ($declaredMenus as $menu) {
            $add((string) $menu['plugin'], (string) $menu['auth_code'], (string) $menu['title'], (string) $menu['path'], []);
        }
        // permissions() 声明但没有任何菜单/按钮承载的操作码：不补进来就永远勾不到（本类存在的理由）。
        foreach ($declaredPerms as $pluginName => $codes) {
            foreach (array_keys($codes) as $code) {
                $add($pluginName, (string) $code, (string) $code, '', []);
            }
        }

        return array_values($groups);
    }

    /**
     * 扁平码集合 = groups() 的派生值（唯一的「授予面」判据）。
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $codes = [];
        foreach (self::groups() as $group) {
            foreach ($group['menus'] as $menu) {
                $codes[(string) $menu['code']] = true;
                foreach ($menu['buttons'] as $btn) {
                    $codes[(string) $btn['code']] = true;
                }
            }
        }
        $list = array_keys($codes);
        sort($list);

        return $list;
    }
}
