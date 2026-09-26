<?php

declare(strict_types=1);

namespace app\support;

/**
 * 插件依赖树：构建插件之间的依赖关系树。
 *
 * 与 PluginDependency::overview() 的区别：
 *   - overview() 返回扁平的 dependencies/dependents/transitive/cycle 列表
 *   - 本类构建递归树结构，适合前端可视化（Tree 组件直接消费）
 *
 * 响应：{ ok, name, tree: [{ name, status, version, children: [...] }], roots: string[], cycle: bool }
 */
final class PluginDependencyTree
{
    /**
     * 构建以指定插件为根的依赖树。
     *
     * @param string $name 插件名（树根）
     * @return array{ok: bool, name: string, tree: array, roots: array, cycle: bool, message: string}
     */
    public static function tree(string $name): array
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return ['ok' => false, 'name' => $name, 'tree' => [], 'roots' => [], 'cycle' => false, 'message' => '非法插件名'];
        }

        $discovered = PluginMarket::discover();
        if (!isset($discovered[$name])) {
            return ['ok' => false, 'name' => $name, 'tree' => [], 'roots' => [], 'cycle' => false, 'message' => '插件不存在'];
        }

        $states = PluginMarket::states();
        $overview = PluginDependency::overview($name);

        // 构建邻接表（name => [dependencies]）
        $graph = self::buildGraph($discovered, $states);

        // 从根节点开始递归建树
        $visited = [];
        $tree = self::buildNode($name, $graph, $states, $visited, 0);

        // 找所有根节点（无被依赖者的插件）
        $roots = self::findRoots($graph);

        return [
            'ok'      => true,
            'name'    => $name,
            'tree'    => $tree,
            'roots'   => $roots,
            'cycle'   => $overview['cycle'] ?? false,
            'message' => sprintf('依赖树：%d 个节点', count($tree)),
        ];
    }

    /**
     * 全量依赖图（所有插件的树结构）。
     *
     * @return array{ok: bool, trees: array, cycle_plugins: string[], message: string}
     */
    public static function all(): array
    {
        $discovered = PluginMarket::discover();
        $states = PluginMarket::states();
        $graph = self::buildGraph($discovered, $states);
        $roots = self::findRoots($graph);

        $trees = [];
        $cyclePlugins = [];

        foreach ($roots as $root) {
            $visited = [];
            $tree = self::buildNode($root, $graph, $states, $visited, 0);
            $trees[] = $tree;
        }

        // 检查有环的插件
        foreach ($discovered as $name => $class) {
            $overview = PluginDependency::overview($name);
            if ($overview['cycle'] ?? false) {
                $cyclePlugins[] = $name;
            }
        }

        return [
            'ok'            => true,
            'trees'         => $trees,
            'cycle_plugins' => $cyclePlugins,
            'message'       => sprintf('依赖图：%d 棵树，%d 个有环插件', count($trees), count($cyclePlugins)),
        ];
    }

    /**
     * 构建邻接表。
     */
    private static function buildGraph(array $discovered, array $states): array
    {
        $graph = [];
        foreach ($discovered as $name => $class) {
            $graph[$name] = [];
            try {
                $plugin = new $class();
                if (method_exists($plugin, 'requires')) {
                    $requires = (array) $plugin->requires();
                    foreach ($requires as $dep) {
                        if (is_string($dep)) {
                            $dep = strtolower($dep);
                            if (isset($discovered[$dep])) {
                                $graph[$name][] = $dep;
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // 跳过无法实例化的插件
            }
        }

        return $graph;
    }

    /**
     * 递归构建树节点。
     */
    private static function buildNode(string $name, array $graph, array $states, array &$visited, int $depth): array
    {
        $state = $states[$name] ?? ['status' => PluginMarket::ENABLED, 'version' => '1.0.0'];

        $node = [
            'name'    => $name,
            'status'  => $state['status'],
            'version' => $state['version'] ?? '1.0.0',
            'depth'   => $depth,
            'children' => [],
        ];

        // 检测环
        if (in_array($name, $visited, true)) {
            $node['cycle'] = true;
            return $node;
        }
        if ($depth > 10) { // 安全限制
            return $node;
        }

        $visited[] = $name;

        foreach ($graph[$name] ?? [] as $dep) {
            $child = self::buildNode($dep, $graph, $states, $visited, $depth + 1);
            $node['children'][] = $child;
        }

        return $node;
    }

    /**
     * 找所有根节点（没有被其他插件依赖的）。
     */
    private static function findRoots(array $graph): array
    {
        $allDeps = [];
        foreach ($graph as $deps) {
            foreach ($deps as $dep) {
                $allDeps[$dep] = true;
            }
        }

        $roots = [];
        foreach (array_keys($graph) as $name) {
            if (!isset($allDeps[$name])) {
                $roots[] = $name;
            }
        }

        sort($roots);
        return $roots;
    }
}
