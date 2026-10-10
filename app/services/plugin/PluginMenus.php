<?php

declare(strict_types=1);

namespace app\support;

use Kode\Framework\Plugin\PluginManager;

/**
 * 各插件 `menus()` 声明的菜单（唯一枚举口）。
 *
 * 为什么要有这一个口：插件菜单**不入库**，是在 `GET /api/menus` 读取时才和库里的核心菜单
 * 合并的（见 `MenuController::tree()`）。于是「某个 path 是不是已经被某个插件占了」这件事
 * 在写侧完全看不见 —— 管理员在菜单管理页新建一条 `/workflow-bridge`（FrontendPlugin 已经
 * 声明了它）能存下来，然后侧边栏出现两个同名条目，而库里那条永远删不掉它对应的功能。
 * 本机 `kode_menus` 里那条 id=49 的 `plugin IS NULL` 化石行就是这么来的
 * （早期版本会把插件菜单落表，后来的读侧合并取代了它，但没人回头清理）。
 *
 * 兜底口径和 `PermCatalog` 一致：**逐插件** try/catch。一个插件的 `menus()` 抛错
 * 只让它自己缺席，不能连带丢掉后面所有插件的声明 —— 那会让写侧的撞车判定静默变松。
 */
final class PluginMenus
{
    /**
     * 合并后的插件菜单行（已补齐 `plugin` / `auth_code` / `parent_id` 默认值）。
     *
     * @return list<array<string, mixed>>
     */
    public static function declared(): array
    {
        $out = [];
        try {
            /** @var PluginManager $manager */
            $manager = resolve(PluginManager::class);
            $plugins = $manager->all();
        } catch (\Throwable) {
            // 插件系统未就绪（例如极早期启动/单测未 boot）：按「没有插件菜单」处理
            return [];
        }

        foreach ($plugins as $plugin) {
            try {
                if (!method_exists($plugin, 'menus')) {
                    continue;
                }
                $name = (string) (method_exists($plugin, 'name') ? $plugin->name() : '');
                foreach ((array) $plugin->menus() as $menu) {
                    $out[] = $menu + ['plugin' => $name, 'auth_code' => (string) ($menu['auth_code'] ?? ''), 'parent_id' => 0];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $out;
    }

    /**
     * path => 声明它的插件名。写侧撞车判定用它。
     *
     * @return array<string, string>
     */
    public static function paths(): array
    {
        $out = [];
        foreach (self::declared() as $menu) {
            $path = (string) ($menu['path'] ?? '');
            if ($path !== '') {
                $out[$path] = (string) ($menu['plugin'] ?? '');
            }
        }

        return $out;
    }
}
