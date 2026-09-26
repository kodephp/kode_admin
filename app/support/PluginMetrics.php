<?php

declare(strict_types=1);

namespace app\support;

/**
 * 插件统计面板：聚合所有插件的运行时指标，供前端仪表盘展示。
 *
 * 统计维度：
 *   - 文件指标：总文件数、总代码行数、总大小、最大文件
 *   - 路由指标：总路由数、按 HTTP 方法分布
 *   - 能力指标：路由/事件/命令/定时/菜单数量
 *   - 健康指标：健康/降级/异常插件数、契约通过率
 *   - 依赖指标：有依赖/无依赖/有下游的插件数
 *
 * 只读：不调用 install/uninstall/upgrade，仅读取声明 + 运行时注册状态。
 */
final class PluginMetrics
{
    /** 文件扩展名白名单（与 PluginFileExplorer 一致） */
    private const EXTENSIONS = ['php', 'json', 'md', 'txt', 'yaml', 'yml'];

    /**
     * 生成全局统计面板。
     *
     * @return array{ok: bool, generated_at: string, totals: array, plugins: array, message: string}
     */
    public static function summary(): array
    {
        $discovered = PluginMarket::discover();
        $states = PluginMarket::states();
        $now = date('Y-m-d H:i:s');

        $totalPlugins = count($discovered);
        $enabled = $paused = $uninstalled = 0;
        $contractOk = $contractDrift = 0;

        $totalFiles = 0;
        $totalRoutes = 0;
        $totalEvents = 0;
        $totalCommands = 0;
        $totalCrons = 0;
        $totalMenus = 0;
        $totalTables = 0;
        $totalConfigFields = 0;
        $totalCodeLines = 0;
        $totalSize = 0;

        $methodDistribution = ['GET' => 0, 'POST' => 0, 'PUT' => 0, 'DELETE' => 0, 'PATCH' => 0];
        $healthStatus = ['healthy' => 0, 'degraded' => 0, 'unhealthy' => 0, 'unknown' => 0];

        $plugins = [];
        $largestFile = ['path' => '', 'size' => 0, 'plugin' => ''];
        $topRoutePlugin = ['name' => '', 'routes' => 0];

        foreach ($discovered as $name => $class) {
            $status = $states[$name]['status'] ?? PluginMarket::ENABLED;
            if ($status === PluginMarket::ENABLED) $enabled++;
            elseif ($status === PluginMarket::PAUSED) $paused++;
            else $uninstalled++;

            $plugin = new $class();
            $desc = PluginContract::describe($plugin);
            $audit = PluginContract::audit($plugin);

            if ($audit['ok']) $contractOk++;
            else $contractDrift++;

            // 文件统计
            $fileStats = self::fileStats($name);
            $totalFiles += $fileStats['files'];
            $totalCodeLines += $fileStats['lines'];
            $totalSize += $fileStats['size'];
            if ($fileStats['largest_size'] > $largestFile['size']) {
                $largestFile = [
                    'path' => $fileStats['largest_path'],
                    'size' => $fileStats['largest_size'],
                    'plugin' => $name,
                ];
            }

            // 能力统计
            $runtime = PluginRuntimeDetail::detail($name);
            $routeList = $runtime['routes'] ?? [];
            $routes = count($routeList);
            $events = count($desc['events']);
            $commands = count($desc['commands']);
            $crons = count($desc['crons']);
            $menus = count($desc['menus']);
            $tables = count($desc['tables']);
            $configFields = count($desc['config_schema'] ?? []);

            // 计算路由方法分布
            $methodCounts = ['GET' => 0, 'POST' => 0, 'PUT' => 0, 'DELETE' => 0, 'PATCH' => 0];
            foreach ($routeList as $r) {
                foreach ($r['methods'] ?? [] as $m) {
                    $m = strtoupper($m);
                    if (isset($methodCounts[$m])) {
                        $methodCounts[$m]++;
                    }
                }
            }

            $totalRoutes += $routes;
            $totalEvents += $events;
            $totalCommands += $commands;
            $totalCrons += $crons;
            $totalMenus += $menus;
            $totalTables += $tables;
            $totalConfigFields += $configFields;

            // 路由方法分布
            foreach ($methodDistribution as $m => $unused) {
                if ($methodCounts[$m] > 0) {
                    $methodDistribution[$m] += $methodCounts[$m];
                }
            }

            // 健康检查
            $health = PluginHealthChecker::check($plugin);
            $healthStatus[$health['status']] = ($healthStatus[$health['status']] ?? 0) + 1;

            // 依赖
            $dep = PluginDependency::overview($name);

            // 单插件记录
            $plugins[] = [
                'name'            => $name,
                'version'         => $desc['version'],
                'status'          => $status,
                'files'           => $fileStats['files'],
                'code_lines'      => $fileStats['lines'],
                'size'            => $fileStats['size'],
                'routes'          => $routes,
                'events'          => $events,
                'commands'        => $commands,
                'crons'           => $crons,
                'menus'           => $menus,
                'tables'          => $tables,
                'config_fields'   => $configFields,
                'capabilities'    => count($desc['capabilities']),
                'contract_ok'     => $audit['ok'],
                'health'          => $health['status'],
                'dependencies'    => count($dep['dependencies']),
                'dependents'      => count($dep['dependents']),
            ];

            if ($routes > $topRoutePlugin['routes']) {
                $topRoutePlugin = ['name' => $name, 'routes' => $routes];
            }
        }

        // 按路由数降序排序
        usort($plugins, static fn (array $a, array $b) => $b['routes'] <=> $a['routes']);

        return [
            'ok'           => true,
            'generated_at' => $now,
            'totals'       => [
                'plugins'        => $totalPlugins,
                'enabled'        => $enabled,
                'paused'         => $paused,
                'uninstalled'    => $uninstalled,
                'contract_ok'    => $contractOk,
                'contract_drift' => $contractDrift,
                'contract_rate'  => $totalPlugins > 0
                    ? round($contractOk / $totalPlugins * 100, 1)
                    : 0.0,
                'files'          => $totalFiles,
                'code_lines'     => $totalCodeLines,
                'total_size'     => $totalSize,
                'routes'         => $totalRoutes,
                'events'         => $totalEvents,
                'commands'       => $totalCommands,
                'crons'          => $totalCrons,
                'menus'          => $totalMenus,
                'tables'         => $totalTables,
                'config_fields'  => $totalConfigFields,
                'method_distribution' => array_filter($methodDistribution, fn ($v) => $v > 0),
                'health'         => $healthStatus,
                'largest_file'   => $largestFile,
                'top_route_plugin' => $topRoutePlugin,
            ],
            'plugins'    => $plugins,
            'message'    => sprintf(
                '统计：%d 个插件，%d 条路由，%d 个文件，契约通过率 %.1f%%',
                $totalPlugins,
                $totalRoutes,
                $totalFiles,
                $totalPlugins > 0 ? $contractOk / $totalPlugins * 100 : 0
            ),
        ];
    }

    /**
     * 单插件文件统计。
     *
     * @return array{files: int, lines: int, size: int, largest_path: string, largest_size: int}
     */
    private static function fileStats(string $name): array
    {
        $files = PluginFileExplorer::list($name);
        $list = $files['files'] ?? [];

        $count = 0;
        $lines = 0;
        $size = 0;
        $largestPath = '';
        $largestSize = 0;

        foreach ($list as $file) {
            $count++;
            $fileSize = (int) $file['size'];
            $size += $fileSize;

            if ($fileSize > $largestSize) {
                $largestSize = $fileSize;
                $largestPath = $file['path'];
            }

            // 只统计 PHP 文件的行数（避免解析 JSON/MD 等）
            $ext = $file['extension'] ?? '';
            if ($ext === 'php' && $fileSize > 0 && $fileSize <= PluginFileExplorer::MAX_FILE_BYTES) {
                $content = PluginFileExplorer::read($name, $file['path']);
                if (($content['ok'] ?? false) && !empty($content['content'])) {
                    $lines += substr_count($content['content'], "\n") + 1;
                }
            }
        }

        return [
            'files' => $count,
            'lines' => $lines,
            'size' => $size,
            'largest_path' => $largestPath,
            'largest_size' => $largestSize,
        ];
    }
}
