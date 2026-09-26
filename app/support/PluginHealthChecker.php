<?php

declare(strict_types=1);

namespace app\support;

use Kode\Framework\Plugin\PluginManager;

/**
 * 插件健康检查：读取运行时注册状态（路由/命令/事件/定时任务/菜单/配置/驱动/依赖）。
 *
 * 与 PluginContract::audit() 的区别：
 *   - audit() 做「声明⟺实现零漂移」校验（契约层面的合规性）
 *   - 本类做「运行时状态」诊断（实际有多少东西在跑）
 *
 * 只读：解析路由表/命令内核/事件派发器/调度器不会触发任何副作用。
 *
 * ok 的口径（各 check 统一，别一处「有一个就算过」一处「全到才算过」）：
 *  - 没声明 = 不适用：applicable=false、ok=true（组件插件没有菜单/命令是正常形态）；
 *  - 声明了 = 必须**全部**落地：ok 取「已注册数 === 声明数」，
 *    「3 条命令只注册上 1 条」是注册链路真断了，回 ok=true 等于把缺失渲染成健康。
 *
 * 使用：
 *   $health = PluginHealthChecker::check($plugin);
 *   // ['status' => 'healthy'|'degraded'|'unhealthy', 'checks' => [...], 'summary' => '...']
 */
final class PluginHealthChecker
{
    /**
     * 检查单个插件的运行时健康状态。
     *
     * @param object $plugin 插件实例（实现 PluginInterface）
     * @return array{status: string, checks: array, summary: string}
     */
    public static function check(object $plugin): array
    {
        $name = method_exists($plugin, 'name') ? (string) $plugin->name() : 'unknown';
        $checks = [];

        // 1. 路由
        $checks['routes'] = self::checkRoutes($name);

        // 2. 命令
        $checks['commands'] = self::checkCommands($plugin);

        // 3. 事件
        $checks['events'] = self::checkEvents($plugin);

        // 4. 定时任务
        $checks['crons'] = self::checkCrons($name, $plugin);

        // 5. 菜单
        $checks['menus'] = self::checkMenus($plugin);

        // 6. 配置
        $checks['config'] = self::checkConfig($name);

        // 7. 驱动
        $checks['drivers'] = self::checkDrivers($name);

        // 8. 依赖
        $checks['dependencies'] = self::checkDependencies($name);

        // 9. 版本
        $checks['version'] = self::checkVersion($plugin);

        // 汇总状态
        $failed = 0;
        $passed = 0;
        $notApplicable = 0;
        foreach ($checks as $check) {
            if ($check['applicable'] === false) {
                $notApplicable++;
                continue;
            }
            $check['ok'] ? $passed++ : $failed++;
        }

        $status = match (true) {
            $failed === 0 && $notApplicable === 0 => 'healthy',
            $failed === 0 && $passed > 0 => 'healthy',
            $failed > 0 && $passed > 0 => 'degraded',
            default => 'unhealthy',
        };

        $summary = sprintf(
            '%d 项通过 / %d 项异常 / %d 项不适用',
            $passed,
            $failed,
            $notApplicable,
        );

        return ['status' => $status, 'checks' => $checks, 'summary' => $summary];
    }

    /**
     * 检查路由：有多少条路由标记了 plugin:<name> 来源。
     */
    private static function checkRoutes(string $name): array
    {
        $expected = "plugin:{$name}";
        try {
            $app = resolve(\Kode\Http\App::class);
            $registry = resolve(\Kode\Framework\Http\RouteRegistry::class);

            $routes = [];
            foreach ($app->getRouter()->getRoutes() as $route) {
                if (($registry->sourceOf($route) ?? '') === $expected) {
                    $routes[] = [
                        'path' => $route->getPattern() ?? '',
                        'methods' => $route->getMethods() ?? [],
                    ];
                }
            }

            return [
                'applicable' => true,
                // 路由数只作信息展示，不判健康：CAPABILITY_SCHEMA 里没有 routes 这条能力，
                // 没有「声明了却没注册」的对账基准；纯服务/命令型插件 0 路由是正常形态
                // （detail 的兜底文案本来也承认这点），把它算成异常会让每个组件插件都 degraded。
                'ok' => true,
                'count' => count($routes),
                'routes' => $routes,
                'detail' => count($routes) > 0
                    ? sprintf('已注册 %d 条路由', count($routes))
                    : '未注册任何路由（插件可能只提供服务/命令/事件）',
            ];
        } catch (\Throwable $e) {
            return [
                'applicable' => false,
                'ok' => false,
                'count' => 0,
                'routes' => [],
                'detail' => '路由表不可解析：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 检查命令：声明的命令类是否已注册进 Console Kernel。
     */
    private static function checkCommands(object $plugin): array
    {
        if (!method_exists($plugin, 'commands')) {
            return ['applicable' => false, 'ok' => true, 'count' => 0, 'commands' => [], 'detail' => '未声明命令'];
        }

        try {
            $commands = (array) $plugin->commands();
            if (empty($commands)) {
                return ['applicable' => false, 'ok' => true, 'count' => 0, 'commands' => [], 'detail' => '未声明命令'];
            }

            $kernel = resolve(\Kode\Console\Kernel::class);
            $registered = [];
            foreach ($kernel->all() as $cmd) {
                if ($cmd instanceof \Kode\Console\Command) {
                    $registered[$cmd::class] = $cmd->getName();
                }
            }

            $registeredCount = 0;
            $missing = [];
            foreach ($commands as $cmd) {
                $class = is_string($cmd) ? $cmd : '';
                if ($class !== '' && isset($registered[$class])) {
                    $registeredCount++;
                } else {
                    $missing[] = $class;
                }
            }

            return [
                'applicable' => true,
                'ok' => $registeredCount === count($commands),
                'count' => $registeredCount,
                'declared' => count($commands),
                'commands' => array_values(array_intersect_key($registered, array_flip(array_filter(array_map('strval', $commands))))),
                'missing' => $missing,
                'detail' => $registeredCount === count($commands)
                    ? sprintf('全部 %d 条命令已注册', $registeredCount)
                    : sprintf('%d/%d 条命令已注册，%d 条缺失', $registeredCount, count($commands), count($missing)),
            ];
        } catch (\Throwable $e) {
            return [
                'applicable' => false,
                'ok' => false,
                'count' => 0,
                'commands' => [],
                'detail' => '命令内核不可解析：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 检查事件：声明的事件监听是否已注册进 Event Dispatcher。
     */
    private static function checkEvents(object $plugin): array
    {
        if (!method_exists($plugin, 'events')) {
            return ['applicable' => false, 'ok' => true, 'count' => 0, 'events' => [], 'detail' => '未声明事件'];
        }

        try {
            $events = (array) $plugin->events();
            if (empty($events)) {
                return ['applicable' => false, 'ok' => true, 'count' => 0, 'events' => [], 'detail' => '未声明事件'];
            }

            $dispatcher = resolve(\Kode\Event\Dispatcher::class);
            $registeredCount = 0;
            $registered = [];
            foreach (array_keys($events) as $eventName) {
                if ($dispatcher->hasListeners($eventName)) {
                    $registeredCount++;
                    $registered[] = $eventName;
                }
            }

            $missing = array_diff(array_keys($events), $registered);

            return [
                'applicable' => true,
                'ok' => $registeredCount === count($events),
                'count' => $registeredCount,
                'declared' => count($events),
                'events' => $registered,
                'missing' => array_values($missing),
                'detail' => $registeredCount === count($events)
                    ? sprintf('全部 %d 个事件监听已注册', $registeredCount)
                    : sprintf('%d/%d 个事件监听已注册', $registeredCount, count($events)),
            ];
        } catch (\Throwable $e) {
            return [
                'applicable' => false,
                'ok' => false,
                'count' => 0,
                'events' => [],
                'detail' => '事件派发器不可解析：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 检查定时任务：声明了多少条、调度器里真有多少条来自本插件。
     *
     * 用 bySource() 而不是 tasks()：ScheduleDispatcher 上没有 tasks()（那是
     * Scheduler 的方法），旧写法在 foreach 处抛 \Error，被下面的 catch(\Throwable)
     * 吞成「调度器不可解析」，而插件页的定时任务 tab 因此恒空——
     * 「什么都没查」和「查了是 0 条」长得一样，正是这类缺陷最难发现的地方。
     */
    private static function checkCrons(string $name, object $plugin): array
    {
        if (!method_exists($plugin, 'crons')) {
            return ['applicable' => false, 'ok' => true, 'count' => 0, 'tasks' => [], 'detail' => '未声明定时任务'];
        }

        $expected = "plugin:{$name}";
        try {
            $declared = (array) $plugin->crons();
            if (empty($declared)) {
                return ['applicable' => false, 'ok' => true, 'count' => 0, 'tasks' => [], 'detail' => '未声明定时任务'];
            }

            $dispatcher = resolve(\Kode\Framework\Scheduling\ScheduleDispatcher::class);

            $tasks = [];
            foreach ($dispatcher->bySource($expected) as $task) {
                $tasks[] = [
                    'name' => $task->name,
                    'expression' => $task->expression,
                    'description' => $task->description ?? '',
                    'enabled' => $task->enabled,
                ];
            }

            return [
                'applicable' => true,
                'ok' => count($tasks) === count($declared),
                'count' => count($tasks),
                'declared' => count($declared),
                'tasks' => $tasks,
                'detail' => count($tasks) === count($declared)
                    ? sprintf('全部 %d 条定时任务已注册', count($tasks))
                    : sprintf('%d/%d 条定时任务已注册', count($tasks), count($declared)),
            ];
        } catch (\Throwable $e) {
            return [
                'applicable' => false,
                'ok' => false,
                'count' => 0,
                'tasks' => [],
                'detail' => '定时任务读取失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 检查菜单：声明了多少个菜单项。
     */
    private static function checkMenus(object $plugin): array
    {
        if (!method_exists($plugin, 'menus')) {
            return ['applicable' => false, 'ok' => true, 'count' => 0, 'menus' => [], 'detail' => '未声明菜单'];
        }

        try {
            $menus = (array) $plugin->menus();
            if (empty($menus)) {
                return ['applicable' => false, 'ok' => true, 'count' => 0, 'menus' => [], 'detail' => '未声明菜单'];
            }

            return [
                'applicable' => true,
                'ok' => true,
                'count' => count($menus),
                'menus' => array_map(static fn ($m) => [
                    'path' => $m['path'] ?? '',
                    'title' => $m['title'] ?? '',
                ], array_values($menus)),
                'detail' => sprintf('已声明 %d 个菜单项', count($menus)),
            ];
        } catch (\Throwable $e) {
            return [
                'applicable' => false,
                'ok' => false,
                'count' => 0,
                'menus' => [],
                'detail' => '菜单读取失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 检查配置：插件是否有已保存的配置值。
     */
    private static function checkConfig(string $name): array
    {
        try {
            $config = PluginConfig::allFor($name);
            $saved = $config['saved'] ?? [];
            $schema = $config['schema'] ?? [];

            return [
                // 只作信息展示，不判健康：schema 里的 default 是产品支持的一种运行状态
                // （PluginConfig::get() 未落库就回落 default），「装完还没配」既不是坏、
                // 也不该让整只插件在列表里翻黄——否则每只新装插件都是 degraded，
                // 黄色就失去意义了。已保存/总数写在 detail 里。
                'applicable' => count($schema) > 0,
                'ok' => true,
                'saved' => count($saved),
                'schema_count' => count($schema),
                'detail' => count($schema) === 0
                    ? '未声明配置 schema'
                    : (count($saved) > 0
                        ? sprintf('已保存 %d/%d 项配置', count($saved), count($schema))
                        : sprintf('未保存任何配置（%d 项使用默认值）', count($schema))),
            ];
        } catch (\Throwable $e) {
            return [
                'applicable' => false,
                'ok' => false,
                'saved' => 0,
                'schema_count' => 0,
                'detail' => '配置读取失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 检查驱动：声明的驱动是否已注册进 DriverRegistry。
     */
    private static function checkDrivers(string $name): array
    {
        try {
            $plugin = PluginMarket::discover()[$name] ?? null;
            if ($plugin === null) {
                return ['applicable' => false, 'ok' => false, 'count' => 0, 'drivers' => [], 'detail' => '插件未发现'];
            }

            $instance = new $plugin();
            if (!method_exists($instance, 'drivers')) {
                return ['applicable' => false, 'ok' => true, 'count' => 0, 'drivers' => [], 'detail' => '未声明驱动'];
            }

            $declared = array_map('strval', (array) $instance->drivers());
            if (empty($declared)) {
                return ['applicable' => false, 'ok' => true, 'count' => 0, 'drivers' => [], 'detail' => '未声明驱动'];
            }

            $available = DriverRegistry::available();
            $registered = [];
            foreach ($declared as $driver) {
                if (isset($available[$driver])) {
                    $registered[] = $driver;
                }
            }

            $missing = array_diff($declared, $registered);

            return [
                'applicable' => true,
                'ok' => count($registered) === count($declared),
                'count' => count($registered),
                'declared' => count($declared),
                'drivers' => $registered,
                'missing' => array_values($missing),
                'detail' => count($registered) === count($declared)
                    ? sprintf('全部 %d 个驱动已注册', count($registered))
                    : sprintf('%d/%d 个驱动已注册', count($registered), count($declared)),
            ];
        } catch (\Throwable $e) {
            return [
                'applicable' => false,
                'ok' => false,
                'count' => 0,
                'drivers' => [],
                'detail' => '驱动注册表不可解析：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 检查依赖：声明的依赖是否全部就绪。
     */
    private static function checkDependencies(string $name): array
    {
        try {
            $result = PluginDependency::overview($name);
            $deps = $result['dependencies'] ?? [];
            $missing = array_filter($deps, static fn ($d) => !$d['ok']);

            return [
                'applicable' => count($deps) > 0,
                'ok' => count($missing) === 0,
                'count' => count($deps),
                'missing' => array_values(array_keys($missing)),
                'detail' => count($deps) === 0
                    ? '无依赖'
                    : (count($missing) === 0
                        ? sprintf('全部 %d 个依赖就绪', count($deps))
                        : sprintf('%d 个依赖中 %d 个未就绪', count($deps), count($missing))),
            ];
        } catch (\Throwable $e) {
            return [
                'applicable' => false,
                'ok' => false,
                'count' => 0,
                'missing' => [],
                'detail' => '依赖解析失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 检查版本：是否声明了合法版本号。
     */
    private static function checkVersion(object $plugin): array
    {
        if (!method_exists($plugin, 'version')) {
            return ['applicable' => false, 'ok' => true, 'version' => null, 'detail' => '未声明版本号'];
        }

        try {
            $version = trim((string) $plugin->version());
            $valid = PluginDependency::isValidVersion($version);

            return [
                'applicable' => true,
                'ok' => $valid && $version !== '',
                'version' => $version !== '' ? $version : null,
                'valid' => $valid,
                'detail' => $version === ''
                    ? '版本号为空'
                    : ($valid
                        ? "版本号 {$version} 合法"
                        : "版本号「{$version}」非法"),
            ];
        } catch (\Throwable $e) {
            return [
                'applicable' => false,
                'ok' => false,
                'version' => null,
                'valid' => false,
                'detail' => '版本号读取失败：' . $e->getMessage(),
            ];
        }
    }
}
