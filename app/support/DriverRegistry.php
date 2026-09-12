<?php

declare(strict_types=1);

namespace app\support;

/**
 * 驱动注册表（`PluginInterface::drivers()` 的唯一运行时消费方）
 *
 * 为什么需要它：`drivers()` 此前只被 `PluginContract` 读出做格式校验，运行时无人消费——
 * `StorageController` 直接硬编码 `'local'`，插件声明 `drivers() => ['oss']` 之后平台
 * 依然不认。声明⟺实现漂移的典型形态：声明有效、生效无效。
 *
 * 语义与运行时门禁一致（见 PluginGateMiddleware）：无安装行的插件视为 enabled，
 * 因此未登记状态的插件其驱动默认可用；paused / uninstalled 的插件驱动自动下线。
 *
 * 使用：
 *   DriverRegistry::allowed()    // ['local', 'oss'] —— 可配置的驱动键全集
 *   DriverRegistry::has('local') // true
 *   DriverRegistry::sourceOf('local') // 'storage_driver'（提供该驱动的插件名）
 *
 * 进程内静态缓存；插件启停后调 PluginMarket::setStatus() 会清驱动缓存（见下方 note）。
 */
final class DriverRegistry
{
    /** driver 名 => 提供它的插件名。 */
    private static ?array $registered = null;

    /**
     * 已注册的驱动 → 来源插件名。
     *
     * 同名冲突（两个插件声明同一驱动键）保留先发现者，冲突本身由
     * `PluginContract::audit()` 判为 drift——注册表不做静默合并。
     *
     * @return array<string, string>
     */
    public static function available(): array
    {
        if (self::$registered !== null) {
            return self::$registered;
        }

        $registered = [];
        foreach (PluginMarket::discover() as $name => $class) {
            if (!self::isEnabled($name)) {
                continue;
            }
            try {
                $plugin = new $class();
                if (!method_exists($plugin, 'drivers')) {
                    continue;
                }
                $drivers = (array) $plugin->drivers();
            } catch (\Throwable) {
                // 构造或读取失败视为未提供驱动：与能力方法失败归 pending 的口径一致，
                // 不因单个插件异常拖垮整个注册表（那是可用性事故，不是校验失败）。
                continue;
            }

            foreach (array_values($drivers) as $driver) {
                $driver = strtolower(trim((string) $driver));
                if (!preg_match(PluginContract::DRIVER_NAME_PATTERN, $driver)) {
                    continue;
                }
                $registered[$driver] ??= $name;
            }
        }

        return self::$registered = $registered;
    }

    /** 可配置的驱动键全集（排序，前端下拉 / 后端门禁共用一份 SoT）。 */
    public static function allowed(): array
    {
        $keys = array_keys(self::available());
        sort($keys);

        return $keys;
    }

    public static function has(string $driver): bool
    {
        return array_key_exists(strtolower(trim($driver)), self::available());
    }

    /** 提供该驱动的插件名（无则 null）。 */
    public static function sourceOf(string $driver): ?string
    {
        return self::available()[strtolower(trim($driver))] ?? null;
    }

    /** 使缓存失效（测试与插件启停后使用）。 */
    public static function flush(): void
    {
        self::$registered = null;
    }

    /** 状态判定与 PluginGateMiddleware 同口径：无安装行视为 enabled。 */
    private static function isEnabled(string $name): bool
    {
        $status = PluginMarket::statusOf($name);

        return $status === null || $status === PluginMarket::ENABLED;
    }
}
