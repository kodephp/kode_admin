<?php

declare(strict_types=1);

namespace app\support;

/**
 * 插件依赖拓扑排序：确定安装/卸载/升级的正确顺序。
 *
 * 为什么需要它：
 *   插件间有 requires() 依赖关系，安装时 A 依赖 B，必须先装 B。
 *   卸载时 A 依赖 B，必须先卸 A（或同时级联暂停下游）。
 *   当前 PluginDependency::validate() 只做单个插件的就绪检查，
 *   没有全局视角的拓扑排序——批量操作时用户不知道先装哪个。
 *
 * 算法：Kahn's algorithm（BFS 拓扑排序）
 *   - 入度为 0 的插件可最先安装
 *   - 每次移除一个入度为 0 的节点，更新邻居入度
 *   - 最后未移除的节点构成环
 *
 * 响应：
 *   install_order: 安装顺序（先装被依赖方）
 *   uninstall_order: 卸载顺序（先卸依赖方）
 *   upgrade_order: 升级顺序（与安装顺序一致）
 *   cycles: 循环依赖组（如果存在）
 */
final class PluginDependencyResolver
{
    /**
     * 计算所有插件的拓扑排序。
     *
     * @return array{ok: bool, install_order: array, uninstall_order: array, upgrade_order: array, cycles: array, message: string}
     */
    public static function resolve(): array
    {
        $discovered = PluginMarket::discover();
        $states = PluginMarket::states();

        // 构建依赖图
        $graph = []; // name => list of names it depends on (upstream)
        $reverse = []; // name => list of names that depend on it (downstream)

        foreach ($discovered as $name => $class) {
            $graph[$name] = [];
            $reverse[$name] = [];
        }

        // 解析每个插件的依赖
        foreach ($discovered as $name => $class) {
            try {
                $plugin = new $class();
            } catch (\Throwable) {
                continue;
            }

            $requires = [];
            if (method_exists($plugin, 'requires')) {
                $raw = $plugin->requires();
                foreach ((array) $raw as $req) {
                    if (is_string($req)) {
                        $requires[] = strtolower($req);
                    } elseif (is_array($req) && isset($req['name'])) {
                        $requires[] = strtolower((string) $req['name']);
                    }
                }
            }

            // 过滤不存在的依赖
            foreach ($requires as $dep) {
                if (isset($discovered[$dep])) {
                    $graph[$name][] = $dep;
                    $reverse[$dep][] = $name;
                }
            }
        }

        // 计算入度
        $indegree = array_fill_keys(array_keys($discovered), 0);
        foreach ($graph as $name => $deps) {
            $indegree[$name] = count($deps);
        }

        // Kahn's algorithm
        $queue = [];
        foreach ($indegree as $name => $deg) {
            if ($deg === 0) {
                $queue[] = $name;
            }
        }
        // 按字母序排列，确保结果确定性
        sort($queue);

        $installOrder = [];
        $visited = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            $installOrder[] = $current;
            $visited[$current] = true;

            // 更新邻居入度
            foreach ($reverse[$current] as $neighbor) {
                if (!isset($visited[$neighbor]) && $indegree[$neighbor] > 0) {
                    $indegree[$neighbor]--;
                    if ($indegree[$neighbor] === 0) {
                        // 插入队列（保持排序）
                        $queue[] = $neighbor;
                        sort($queue);
                    }
                }
            }
        }

        // 找出未访问的节点（循环依赖）
        $cycles = [];
        $unvisited = [];
        foreach ($discovered as $name => $class) {
            if (!isset($visited[$name])) {
                $unvisited[] = $name;
            }
        }

        if ($unvisited !== []) {
            // 找出循环组
            $cycles = self::findCycles($unvisited, $graph);
        }

        // 卸载顺序 = 安装顺序的反转
        $uninstallOrder = array_reverse($installOrder);
        $upgradeOrder = $installOrder; // 升级顺序同安装

        return [
            'ok'               => true,
            'install_order'    => $installOrder,
            'uninstall_order'  => $uninstallOrder,
            'upgrade_order'    => $upgradeOrder,
            'cycles'           => $cycles,
            'total'            => count($discovered),
            'resolved'         => count($installOrder),
            'circular'         => count($unvisited),
            'message'          => sprintf(
                '拓扑排序：%d 个可排序，%d 个循环依赖',
                count($installOrder),
                count($unvisited)
            ),
        ];
    }

    /**
     * 单插件的安装前置条件列表。
     *
     * @return array{ok: bool, name: string, requires: array, all_ready: bool, message: string}
     */
    public static function prerequisitesOf(string $name): array
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return ['ok' => false, 'name' => $name, 'message' => '非法插件名'];
        }

        $discovered = PluginMarket::discover();
        if (!isset($discovered[$name])) {
            return ['ok' => false, 'name' => $name, 'message' => '插件不存在'];
        }

        try {
            $plugin = new $discovered[$name]();
        } catch (\Throwable) {
            return ['ok' => false, 'name' => $name, 'message' => '实例化失败'];
        }

        $requires = [];
        $allReady = true;
        if (method_exists($plugin, 'requires')) {
            foreach ((array) $plugin->requires() as $req) {
                $depName = is_string($req) ? $req : (isset($req['name']) ? (string) $req['name'] : '');
                $depVersion = is_array($req) && isset($req['version']) ? (string) $req['version'] : '*';
                $depName = strtolower($depName);

                $installed = PluginMarket::statusOf($depName);
                $ready = $installed === PluginMarket::ENABLED;
                if (!$ready) {
                    $allReady = false;
                }

                $requires[] = [
                    'name'       => $depName,
                    'version'    => $depVersion,
                    'installed'  => $installed !== null,
                    'status'     => $installed,
                    'ready'      => $ready,
                ];
            }
        }

        return [
            'ok'        => true,
            'name'      => $name,
            'requires'  => $requires,
            'all_ready' => $allReady,
            'message'   => $allReady
                ? sprintf('%s 的全部 %d 个依赖已就绪', $name, count($requires))
                : sprintf('%s 有 %d 个依赖未就绪', $name, count(array_filter($requires, fn($r) => !$r['ready']))),
        ];
    }

    /**
     * 查找循环依赖组。
     */
    private static function findCycles(array $unvisited, array $graph): array
    {
        $cycles = [];
        $visited = [];
        $stack = [];
        $hasCycle = false;

        // DFS 查找环
        foreach ($unvisited as $start) {
            if (isset($visited[$start])) {
                continue;
            }
            $cycle = self::dfs($start, $graph, $visited, $stack, []);
            if ($cycle !== null) {
                $cycles[] = $cycle;
                $hasCycle = true;
            }
        }

        return $cycles;
    }

    /**
     * DFS 查找环。
     */
    private static function dfs(string $node, array $graph, array &$visited, array &$stack, array $path): ?array
    {
        $visited[$node] = true;
        $stack[] = $node;
        $path[] = $node;

        foreach ($graph[$node] ?? [] as $neighbor) {
            if (in_array($neighbor, $stack, true)) {
                // 找到环
                $cycleStart = array_search($neighbor, $stack, true);
                return array_slice($stack, $cycleStart);
            }
            if (!isset($visited[$neighbor])) {
                $result = self::dfs($neighbor, $graph, $visited, $stack, $path);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        array_pop($stack);
        return null;
    }
}
