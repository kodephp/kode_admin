<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;
use Kode\Framework\Plugin\PluginDiscoverer;

/**
 * 插件市场（运行时状态机，免重启启停）。
 *
 * 状态：installed/enabled（路由放行）/ paused（路由 404，数据保留）/ uninstalled。
 * 路由级门禁由 PluginGateMiddleware 按 `/api/<name>/*` 前缀 + 本表状态判定；
 * 开关即时生效，无需重启（config/plugins.php 只管"代码是否加载"）。
 *
 * 分层：本类只负责**运行时状态**（kode_plugins 表 + HotCache）。
 * 「代码层有哪些插件」的发现职责已下沉到框架 {@see PluginDiscoverer}——
 * 框架的 PluginServiceProvider 与本站后台读同一份发现结果，不存在第二份清单。
 * baseDir() / discover() / flush() 均为薄委托，保留是为了既有 17 处调用点零改动。
 */
final class PluginMarket
{
    public const ENABLED = 'enabled';
    public const PAUSED = 'paused';
    public const UNINSTALLED = 'uninstalled';

    /** 插件根目录（委托框架发现器）。 */
    public static function baseDir(): string
    {
        return PluginDiscoverer::baseDir();
    }

    /**
     * 代码层发现的插件。
     *
     * 委托 {@see PluginDiscoverer::discover()}（唯一真值源）：
     * 扫描 plugins/*Plugin.php → 类名后缀 Plugin → 必须可实例化且有合法 name()。
     * 进程内静态缓存，插件增删需重启服务（与路由/插件启动期加载一致）。
     *
     * @return array<string, class-string> name => class（非法名跳过）
     */
    public static function discover(): array
    {
        return PluginDiscoverer::discover();
    }

    /** @return array<string, array<string, mixed>> name => row */
    public static function states(): array
    {
        try {
            $rows = Db::select('SELECT name, version, status FROM kode_plugins');
        } catch (\Throwable) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['name']] = $row;
        }

        return $map;
    }

    public static function statusOf(string $name): ?string
    {
        $key = "plugin:status:{$name}";
        $hit = HotCache::get($key);
        if (is_string($hit)) {
            return $hit;
        }
        $states = self::states();
        $status = isset($states[$name]) ? (string) $states[$name]['status'] : null;
        // 未安装行视为 enabled（保持旧行为：config 加载即用），null 不缓存以便安装后即时生效。
        if ($status !== null) {
            self::setStatusCache($name, $status);
        }

        return $status;
    }

    public static function setStatus(string $name, string $status): void
    {
        $states = self::states();
        if (isset($states[$name])) {
            Db::update(
                'UPDATE kode_plugins SET status = ?, updated_at = NOW() WHERE name = ?',
                [$status, $name]
            );
        } else {
            Db::insert(
                'INSERT INTO kode_plugins (name, version, status, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
                [$name, '1.0.0', $status]
            );
        }
        self::setStatusCache($name, $status);
        // 驱动注册表随启停即时失效：paused 插件声明的驱动必须立刻下线，
        // 否则会出现「插件已暂停但存储页还能新建该驱动」的静默漂移。
        DriverRegistry::flush();
        // 定时任务随启停即时生效：否则会出现「插件已暂停/卸载，但其 cron 仍按时跑」。
        PluginScheduleSync::sync($name, $status);
    }

    public static function setVersion(string $name, string $version): void
    {
        Db::update('UPDATE kode_plugins SET version = ?, updated_at = NOW() WHERE name = ?', [$version, $name]);
        self::forgetStatusCache($name);
    }

    private static function setStatusCache(string $name, string $status): void
    {
        try {
            HotCache::set("plugin:status:{$name}", $status, 300);
        } catch (\Throwable) {
        }
    }

    public static function forgetStatusCache(string $name): void
    {
        HotCache::forget("plugin:status:{$name}");
    }

    /** 使发现缓存失效（测试用；新增/删除插件文件后需调用）。委托框架发现器。 */
    public static function flush(): void
    {
        PluginDiscoverer::flush();
    }
}
