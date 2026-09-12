<?php

declare(strict_types=1);

namespace app\support;

/**
 * 插件运行时详情：路由/事件/定时/命令/菜单的完整条目（非计数）。
 *
 * 与 PluginHealthChecker::check() 的区别：
 *   - HealthChecker 只给计数 + ok/failed 诊断
 *   - 本类返回完整条目数组（前端弹窗展示用）
 *
 * 只读：解析路由表/调度器/事件派发器不触发副作用。
 *
 * 响应结构：{ ok, name, routes, events, crons, commands, menus }
 *   routes:   [{ path, methods, source }]
 *   events:   [{ event, listeners, registered }]
 *   crons:    [{ name, expression, description, enabled }]
 *   commands: [{ class, name, registered }]
 *   menus:    [{ path, title, icon?, sort? }]
 */
final class PluginRuntimeDetail
{
    public static function detail(string $name): array
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return self::fail($name, '非法插件名');
        }

        $pluginClass = PluginMarket::discover()[$name] ?? null;
        if ($pluginClass === null) {
            return self::fail($name, '插件不存在');
        }

        try {
            $plugin = new $pluginClass();
        } catch (\Throwable) {
            return self::fail($name, '插件实例化失败');
        }

        return [
            'ok'       => true,
            'name'     => $name,
            'routes'   => self::routes($name),
            'events'   => self::events($plugin),
            'crons'    => self::crons($name),
            'commands' => self::commands($plugin),
            'menus'    => self::menus($plugin),
        ];
    }

    /**
     * 路由详情：从路由表提取插件注册的所有路由。
     */
    private static function routes(string $name): array
    {
        $expected = "plugin:{$name}";
        try {
            $app = resolve(\Kode\Http\AppData::class);
            $registry = resolve(\Kode\Framework\Http\RouteRegistry::class);

            $routes = [];
            foreach ($app->getRouter()->getRoutes() as $route) {
                if (($registry->sourceOf($route) ?? '') === $expected) {
                    $routes[] = [
                        'path'    => $route->getPattern() ?? '',
                        'methods' => $route->getMethods() ?? [],
                        'source'  => $expected,
                    ];
                }
            }

            // 按路径排序
            usort($routes, static fn (array $a, array $b) => strcmp($a['path'], $b['path']));

            return $routes;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 事件详情：声明的事件 + 是否已注册监听器。
     */
    private static function events(object $plugin): array
    {
        if (!method_exists($plugin, 'events')) {
            return [];
        }

        try {
            $declared = (array) $plugin->events();
            if (empty($declared)) {
                return [];
            }

            $dispatcher = resolve(\Kode\Event\Dispatcher::class);

            $events = [];
            foreach ($declared as $eventName => $listeners) {
                $events[] = [
                    'event'      => (string) $eventName,
                    'listeners'  => is_array($listeners) ? array_map('is_string', $listeners) : [is_string($listeners) ? $listeners : get_class($listeners)],
                    'registered' => $dispatcher->hasListeners($eventName),
                ];
            }

            return $events;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 定时任务详情：调度器中注册的任务（含表达式、描述、启用状态）。
     */
    private static function crons(string $name): array
    {
        $expected = "plugin:{$name}";
        try {
            $dispatcher = resolve(\Kode\Framework\Scheduling\ScheduleDispatcher::class);

            $tasks = [];
            foreach ($dispatcher->tasks() as $task) {
                if (($task->source ?? '') === $expected) {
                    $tasks[] = [
                        'name'        => $task->name,
                        'expression'  => $task->expression ?? '',
                        'description' => $task->description ?? '',
                        'enabled'     => $task->enabled ?? true,
                    ];
                }
            }

            usort($tasks, static fn (array $a, array $b) => strcmp($a['name'], $b['name']));

            return $tasks;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 命令详情：声明的命令类 + 是否已注册到内核。
     */
    private static function commands(object $plugin): array
    {
        if (!method_exists($plugin, 'commands')) {
            return [];
        }

        try {
            $declared = (array) $plugin->commands();
            if (empty($declared)) {
                return [];
            }

            $kernel = resolve(\Kode\Console\Kernel::class);
            $registered = [];
            foreach ($kernel->all() as $cmd) {
                if ($cmd instanceof \Kode\Console\Command) {
                    $registered[$cmd::class] = $cmd->getName();
                }
            }

            $commands = [];
            foreach ($declared as $cmdClass) {
                if (!is_string($cmdClass)) {
                    continue;
                }
                $commands[] = [
                    'class'      => $cmdClass,
                    'name'       => $registered[$cmdClass] ?? '',
                    'registered' => isset($registered[$cmdClass]),
                ];
            }

            return $commands;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 菜单详情：插件声明的菜单路径。
     */
    private static function menus(object $plugin): array
    {
        if (!method_exists($plugin, 'menus')) {
            return [];
        }

        try {
            $menus = (array) $plugin->menus();
            if (empty($menus)) {
                return [];
            }

            $result = [];
            foreach ($menus as $menu) {
                if (is_array($menu)) {
                    $result[] = $menu;
                }
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    private static function fail(string $name, string $message): array
    {
        return [
            'ok'       => false,
            'name'     => $name,
            'routes'   => [],
            'events'   => [],
            'crons'    => [],
            'commands' => [],
            'menus'    => [],
            'message'  => $message,
        ];
    }
}
