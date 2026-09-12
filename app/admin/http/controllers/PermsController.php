<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use app\http\concerns\ApiResponse;

/**
 * 权限目录：按插件分组下发「菜单 auth_code + 按钮码」（供角色勾选，与菜单管理联动）。
 * 空码菜单不进目录（登录可见，无需授权）。插件 menus() 声明 auth_code 的同样收录。
 */
final class PermsController extends Controller
{
    use ApiResponse;
    public function catalog()
    {
        $rows = Db::select(
            "SELECT id, title, path, plugin, auth_code FROM kode_menus WHERE auth_code <> '' ORDER BY plugin, sort, id"
        );
        $btns = [];
        foreach (Db::select('SELECT menu_id, code, title FROM kode_menu_buttons ORDER BY menu_id, sort, id') as $b) {
            $btns[(int) $b['menu_id']][] = ['code' => (string) $b['code'], 'title' => (string) $b['title']];
        }
        try {
            /** @var \Kode\Framework\Plugin\PluginManager $manager */
            $manager = resolve(\Kode\Framework\Plugin\PluginManager::class);
            foreach ($manager->all() as $plugin) {
                if (!method_exists($plugin, 'menus')) {
                    continue;
                }
                foreach ((array) $plugin->menus() as $menu) {
                    $code = (string) ($menu['auth_code'] ?? '');
                    if ($code === '') {
                        continue;
                    }
                    $rows[] = [
                        'id' => 0,
                        'title' => (string) ($menu['title'] ?? $menu['path']),
                        'path' => (string) $menu['path'],
                        'plugin' => $plugin->name(),
                        'auth_code' => $code,
                    ];
                }
            }
        } catch (\Throwable) {
        }
        $groups = [];
        foreach ($rows as $row) {
            $plugin = (string) ($row['plugin'] ?? 'system');
            $groups[$plugin]['group'] = $plugin;
            $groups[$plugin]['menus'][] = [
                'code' => (string) $row['auth_code'],
                'title' => (string) $row['title'],
                'path' => (string) $row['path'],
                'buttons' => $btns[(int) $row['id']] ?? [],
            ];
        }

        return $this->ok(array_values($groups));
    }
}
