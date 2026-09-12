<?php

declare(strict_types=1);

namespace app\support;

/**
 * 插件市场对接服务：健康检测、更新检查、市场插件列表代理。
 *
 * 职责边界：
 *   - health()：检测市场 API 可达性 + 返回延迟
 *   - list()：代理市场 API 获取插件列表（本地无缓存，每次实时）
 *   - checkUpdates()：对比已安装插件版本与市场版本，返回可更新列表
 *
 * 市场 API 约定（GET {api_url}/plugins）：
 *   返回 { data: [{ name, version, desc?, description?, package_url?, url?, sha256? }] }
 *   或裸数组 [{ name, version, ... }]（两种形态兼容）
 *
 * 不职责：
 *   - 执行安装（那是 PluginController::installRemote 的事）
 *   - 版本比较引擎（复用 PluginDependency::satisfies()）
 */
final class PluginMarketplace
{
    /** 市场 API 超时（秒）。 */
    private const TIMEOUT = 5;

    /**
     * 读取市场 API URL（从系统配置）。
     *
     * @return string URL（空 = 未对接）
     */
    public static function apiUrl(): string
    {
        return (string) (SysConfig::get('market.api_url') ?? '');
    }

    /**
     * 检测市场 API 健康状态。
     *
     * @return array{ok: bool, url: string, latency_ms: int|null, message: string}
     */
    public static function health(): array
    {
        $url = self::apiUrl();
        if ($url === '') {
            return [
                'ok'         => false,
                'url'        => '',
                'latency_ms' => null,
                'message'    => '市场 API 未配置（在系统设置中填写 market.api_url）',
            ];
        }

        $endpoint = rtrim($url, '/') . '/plugins';
        $start = microtime(true);
        try {
            $context = stream_context_create([
                'http' => [
                    'method'        => 'GET',
                    'timeout'       => self::TIMEOUT,
                    'ignore_errors' => true,
                ],
            ]);
            $body = @file_get_contents($endpoint, false, $context);
            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($body === false) {
                // 从 $http_response_header 获取状态码
                $status = 0;
                if (isset($http_response_header[0]) && is_string($http_response_header[0])) {
                    preg_match('/\s(\d{3})\s/', $http_response_header[0], $m);
                    $status = (int) ($m[1] ?? 0);
                }
                return [
                    'ok'         => false,
                    'url'        => $endpoint,
                    'latency_ms' => $latency,
                    'message'    => $status > 0 ? "HTTP {$status}" : '连接失败',
                ];
            }

            // 尝试解析 JSON（不要求是插件列表，只要能解析就行）
            $data = json_decode($body, true);
            $pluginCount = 0;
            if (is_array($data)) {
                $pluginCount = is_array($data['data'] ?? null) ? count($data['data']) : (array_key_exists('name', $data[0] ?? []) ? count($data) : 0);
            }

            return [
                'ok'         => true,
                'url'        => $endpoint,
                'latency_ms' => $latency,
                'message'    => "✓ 可达（{$pluginCount} 个插件，{$latency}ms）",
                'plugins'    => $pluginCount,
            ];
        } catch (\Throwable $e) {
            return [
                'ok'         => false,
                'url'        => $endpoint,
                'latency_ms' => null,
                'message'    => $e->getMessage(),
            ];
        }
    }

    /**
     * 代理市场 API 获取插件列表。
     *
     * @return array{ok: bool, plugins: array, message: string}
     */
    public static function list(): array
    {
        $url = self::apiUrl();
        if ($url === '') {
            return ['ok' => false, 'plugins' => [], 'message' => '市场 API 未配置'];
        }

        $endpoint = rtrim($url, '/') . '/plugins';
        try {
            $context = stream_context_create([
                'http' => [
                    'method'        => 'GET',
                    'timeout'       => self::TIMEOUT,
                    'ignore_errors' => true,
                ],
            ]);
            $body = @file_get_contents($endpoint, false, $context);

            if ($body === false) {
                return ['ok' => false, 'plugins' => [], 'message' => '市场 API 不可达'];
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                return ['ok' => false, 'plugins' => [], 'message' => '市场 API 返回非法 JSON'];
            }

            // 兼容两种形态：{data: [...]} 或裸数组
            $plugins = is_array($data['data'] ?? null) ? $data['data'] : $data;
            if (!is_array($plugins)) {
                return ['ok' => false, 'plugins' => [], 'message' => '市场 API 返回格式异常'];
            }

            // 规范化：统一字段名
            $normalized = [];
            foreach ($plugins as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $normalized[] = [
                    'name'        => (string) ($p['name'] ?? ''),
                    'version'     => (string) ($p['version'] ?? '1.0.0'),
                    'description' => (string) ($p['desc'] ?? $p['description'] ?? ''),
                    'package_url' => (string) ($p['package_url'] ?? $p['url'] ?? ''),
                    'sha256'      => (string) ($p['sha256'] ?? ''),
                ];
            }

            return ['ok' => true, 'plugins' => $normalized, 'message' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'plugins' => [], 'message' => $e->getMessage()];
        }
    }

    /**
     * 检查可用更新：对比已安装版本与市场版本。
     *
     * @return array{ok: bool, updates: array<int, array>, total: int, message: string}
     *   updates 每项：{ name, current_version, available_version, upgradeable: bool }
     */
    public static function checkUpdates(): array
    {
        $result = self::list();
        if (!$result['ok']) {
            return ['ok' => false, 'updates' => [], 'total' => 0, 'message' => $result['message']];
        }

        // 建立市场版本索引
        $marketVersions = [];
        foreach ($result['plugins'] as $p) {
            $name = $p['name'];
            if ($name !== '') {
                $marketVersions[$name] = $p['version'];
            }
        }

        // 对比已安装插件
        $discovered = PluginMarket::discover();
        $states = PluginMarket::states();
        $updates = [];

        foreach ($discovered as $name => $class) {
            // 只检查已启用/已安装的插件（paused/uninstalled 不检查）
            $state = $states[$name] ?? null;
            if ($state !== null && $state['status'] === PluginMarket::UNINSTALLED) {
                continue;
            }

            $currentVersion = (string) ($state['version'] ?? '0.0.0');
            $availableVersion = $marketVersions[$name] ?? null;

            if ($availableVersion === null) {
                continue; // 市场没有此插件
            }

            // 版本比较：目标 > 当前 才有更新
            $upgradeable = PluginDependency::isValidVersion($availableVersion)
                && PluginDependency::isValidVersion($currentVersion)
                && version_compare($availableVersion, $currentVersion, '>');

            if ($upgradeable) {
                $updates[] = [
                    'name'              => $name,
                    'current_version'   => $currentVersion,
                    'available_version' => $availableVersion,
                    'upgradeable'       => true,
                ];
            }
        }

        return [
            'ok'      => true,
            'updates' => $updates,
            'total'   => count($updates),
            'message' => '',
        ];
    }
}
