<?php

declare(strict_types=1);

namespace app\support;

/**
 * 插件 API 文档生成器：从运行时路由表提取插件注册的端点，生成结构化文档。
 *
 * 与 PluginRuntimeDetail::routes() 的区别：
 *   - RuntimeDetail 返回原始路由条目（path + methods）
 *   - 本类生成面向开发者的文档（含参数解析、响应说明、分组、排序）
 *
 * 响应：{ ok, name, version, generated_at, endpoints: [{ method, path, params, summary, group }], total, message }
 */
final class PluginApiDoc
{
    /**
     * 生成指定插件的 API 文档。
     *
     * @param string $name 插件名
     * @return array{ok: bool, name: string, version: string, generated_at: string, endpoints: array, total: int, message: string}
     */
    public static function generate(string $name): array
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return self::fail($name, '非法插件名');
        }

        $discovered = PluginMarket::discover();
        if (!isset($discovered[$name])) {
            return self::fail($name, '插件不存在');
        }

        $states = PluginMarket::states();
        $version = $states[$name]['version'] ?? '1.0.0';

        // 获取路由
        $routes = self::getRoutes($name);
        if ($routes === null) {
            return self::fail($name, '路由表读取失败');
        }

        // 按分组整理
        $endpoints = [];
        foreach ($routes as $route) {
            $endpoints[] = [
                'method'  => implode(', ', $route['methods']),
                'path'    => $route['path'],
                'params'  => self::parseParams($route['path']),
                'summary' => self::inferSummary($route['methods'], $route['path']),
                'group'   => self::inferGroup($route['path']),
            ];
        }

        // 按 group 分组，再按 path 排序
        usort($endpoints, static fn (array $a, array $b) => match (true) {
            $a['group'] !== $b['group'] => strcmp($a['group'], $b['group']),
            default => strcmp($a['path'], $b['path']),
        });

        return [
            'ok'           => true,
            'name'         => $name,
            'version'      => $version,
            'generated_at' => date('Y-m-d H:i:s'),
            'endpoints'    => $endpoints,
            'total'        => count($endpoints),
            'message'      => sprintf('API 文档：%d 个端点', count($endpoints)),
        ];
    }

    /**
     * 获取插件注册的路由。
     */
    private static function getRoutes(string $name): ?array
    {
        $expected = "plugin:{$name}";
        try {
            $app = \Kode\Framework\Application::getInstance();
            $router = $app->getRouter();
            $registry = \Kode\Framework\Http\RouteRegistry::instance();

            $routes = [];
            foreach ($router->getRoutes() as $route) {
                if (($registry->sourceOf($route) ?? '') !== $expected) {
                    continue;
                }

                $routes[] = [
                    'path'    => $route->getPattern() ?? '',
                    'methods' => $route->getMethods() ?? ['GET'],
                ];
            }

            usort($routes, static fn (array $a, array $b) => strcmp($a['path'], $b['path']));

            return $routes;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 从路径解析参数。
     */
    private static function parseParams(string $path): array
    {
        $params = [];
        if (preg_match_all('/\{(\w+)\}/', $path, $matches)) {
            foreach ($matches[1] as $param) {
                $params[] = $param;
            }
        }

        return $params;
    }

    /**
     * 推断端点摘要。
     */
    private static function inferSummary(array $methods, string $path): string
    {
        $verb = match (true) {
            in_array('GET', $methods, true) => '获取',
            in_array('POST', $methods, true) => '创建',
            in_array('PUT', $methods, true) => '更新',
            in_array('PATCH', $methods, true) => '部分更新',
            in_array('DELETE', $methods, true) => '删除',
            default => '操作',
        };

        $segments = explode('/', $path);
        $lastSegment = end($segments);
        $label = str_replace('_', ' ', $lastSegment);

        return "{$verb} {$label}";
    }

    /**
     * 推断分组。
     */
    private static function inferGroup(string $path): string
    {
        $parts = explode('/', $path);
        // 取 /api/{plugin}/ 之后的第一段作为分组
        if (count($parts) >= 4 && $parts[0] === 'api') {
            return $parts[3] ?? 'default';
        }

        return 'default';
    }

    private static function fail(string $name, string $message): array
    {
        return [
            'ok'           => false,
            'name'         => $name,
            'version'      => '',
            'generated_at' => '',
            'endpoints'    => [],
            'total'        => 0,
            'message'      => $message,
        ];
    }
}
