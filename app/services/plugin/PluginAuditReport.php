<?php

declare(strict_types=1);

namespace app\support;

use Kode\Framework\Plugin\PluginManager;

/**
 * 插件审计报表：聚合全量插件的契约审计 + 健康检查 + 依赖概览。
 *
 * 与单插件接口（contract / health / dependencies）的区别：
 *   - 单插件接口按需查一个，适合插件详情场景；
 *   - 本类一次性扫全量插件，适合运维总览 / 定时巡检 / 批量导出。
 *
 * 只读：不触发任何副作用（不调用 install/uninstall/upgrade）。
 *
 * 使用：
 *   $report = PluginAuditReport::generate();
 *   // ['summary' => [...], 'plugins' => [...], 'generated_at' => '...']
 */
final class PluginAuditReport
{
    /**
     * 生成全量插件审计报表。
     *
     * @return array{summary: array, plugins: array<int, array>, generated_at: string}
     */
    public static function generate(): array
    {
        $discovered = PluginMarket::discover();
        $states = PluginMarket::states();
        $plugins = [];
        $summary = [
            'total'        => count($discovered),
            'enabled'      => 0,
            'paused'       => 0,
            'uninstalled'  => 0,
            'healthy'      => 0,
            'degraded'     => 0,
            'unhealthy'    => 0,
            'drift_count'  => 0,
            'cycle_count'  => 0,
            'missing_deps' => 0,
        ];

        foreach ($discovered as $name => $class) {
            $state = $states[$name] ?? [];
            $status = $state['status'] ?? PluginMarket::ENABLED;

            // 契约审计
            $plugin = null;
            $audit = ['ok' => false, 'drift' => [], 'pending' => [], 'error' => ''];
            try {
                $plugin = new $class();
                $audit = PluginContract::audit($plugin);
            } catch (\Throwable $e) {
                $audit['error'] = $e->getMessage();
            }

            // 健康检查（仅在已安装时执行）
            $health = null;
            if ($plugin !== null && $status === PluginMarket::ENABLED) {
                try {
                    $health = PluginHealthChecker::check($plugin);
                } catch (\Throwable) {
                    // health check 不可用不影响报表生成
                }
            }

            // 依赖概览
            $dep = null;
            try {
                $dep = PluginDependency::overview($name);
            } catch (\Throwable) {
                // 依赖解析失败不影响报表
            }

            // 统计
            if ($status === PluginMarket::ENABLED) {
                $summary['enabled']++;
            } elseif ($status === PluginMarket::PAUSED) {
                $summary['paused']++;
            } elseif ($status === PluginMarket::UNINSTALLED) {
                $summary['uninstalled']++;
            }

            $healthStatus = $health['status'] ?? null;
            if ($healthStatus === 'healthy') {
                $summary['healthy']++;
            } elseif ($healthStatus === 'degraded') {
                $summary['degraded']++;
            } elseif ($healthStatus === 'unhealthy') {
                $summary['unhealthy']++;
            }

            if (!$audit['ok']) {
                $summary['drift_count']++;
            }
            if (!empty($dep['cycle'])) {
                $summary['cycle_count']++;
            }
            $missingDeps = $dep['dependencies'] ?? [];
            foreach ($missingDeps as $d) {
                if (!$d['ok']) {
                    $summary['missing_deps']++;
                }
            }

            // 收集插件详情
            $desc = null;
            if ($plugin !== null) {
                try {
                    $desc = PluginContract::describe($plugin);
                } catch (\Throwable) {
                    // 描述失败不影响报表
                }
            }

            $plugins[] = [
                'name'            => $name,
                'class'           => $class,
                'version'         => $desc['version'] ?? '1.0.0',
                'installed_version' => $state['version'] ?? null,
                'status'          => $status,
                'health_status'   => $health['status'] ?? null,
                'health_summary'  => $health['summary'] ?? null,
                'health_checks'   => $health['checks'] ?? null,
                'contract_ok'     => $audit['ok'],
                'contract_drift'  => $audit['drift'],
                'contract_pending' => $audit['pending'],
                'contract_error'  => $audit['error'],
                'capabilities'    => $desc['capabilities'] ?? [],
                'groups'          => $desc['groups'] ?? [],
                'permissions'     => $desc['permissions'] ?? [],
                'drivers'         => $desc['drivers'] ?? [],
                'requires'        => $desc['requires'] ?? [],
                'config_schema'   => $desc['config_schema'] ?? [],
                'meta'            => $desc['meta'] ?? [],
                'errors'          => $desc['errors'] ?? [],
                'dependencies'    => $dep['dependencies'] ?? [],
                'dependents'      => $dep['dependents'] ?? [],
                'transitive'      => $dep['transitive'] ?? [],
                'cycle'           => $dep['cycle'] ?? false,
                // 能力统计（供报表概览用）
                'routes'          => $health['checks']['routes']['count'] ?? 0,
                'commands'        => $health['checks']['commands']['count'] ?? 0,
                'events'          => $health['checks']['events']['count'] ?? 0,
                'crons'           => $health['checks']['crons']['count'] ?? 0,
                'menus'           => $health['checks']['menus']['count'] ?? 0,
                'config_fields'   => $health['checks']['config']['schema_count'] ?? 0,
            ];
        }

        // 按名称排序（稳定输出）
        usort($plugins, static fn(array $a, array $b) => strcmp($a['name'], $b['name']));

        return [
            'summary'      => $summary,
            'plugins'      => $plugins,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 导出为 JSON（供下载）。
     *
     * @return string JSON 字符串
     */
    public static function export(): string
    {
        $report = self::generate();

        return json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * 导出为 CSV（供下载）。
     *
     * 列：name, version, status, health, contract, drift, pending, routes, commands, events, crons, menus, deps
     *
     * @return string CSV 字符串（含 UTF-8 BOM）
     */
    public static function exportCsv(): string
    {
        $report = self::generate();
        $rows = [];
        $rows[] = ['插件', '版本', '状态', '健康', '契约', '漂移数', '待确认数', '路由', '命令', '事件', '定时', '菜单', '依赖'];

        foreach ($report['plugins'] as $p) {
            $rows[] = [
                $p['name'],
                $p['version'],
                $p['status'],
                $p['health_status'] ?? '-',
                $p['contract_ok'] ? '零漂移' : '漂移',
                count($p['contract_drift']),
                count($p['contract_pending']),
                $p['routes'],
                $p['commands'],
                $p['events'],
                $p['crons'],
                $p['menus'],
                count($p['dependencies']),
            ];
        }

        // 写 CSV（带 UTF-8 BOM）
        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($output, $row, ',', '"', '\\');
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
