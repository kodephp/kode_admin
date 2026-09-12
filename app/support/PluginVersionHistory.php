<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;
use Kode\Framework\Plugin\PluginDiscoverer;

/**
 * 插件版本历史：记录升级/回滚/安装/卸载的版本变更轨迹。
 *
 * 职责边界：
 *   - 记录（record）：写入一条版本变更
 *   - 查询（list / latest）：按插件名查历史
 *   - 回滚（rollback）：将 kode_plugins.version 回退到指定版本
 *
 * 不职责：
 *   - 执行代码级回滚（删除/恢复文件）——那是 PluginPackager 的事
 *   - 执行 migration 回退——那是框架 migrate:rollback 的事
 */
final class PluginVersionHistory
{
    public const OP_INSTALL = 'install';
    public const OP_UPGRADE = 'upgrade';
    public const OP_ROLLBACK = 'rollback';
    public const OP_UNINSTALL = 'uninstall';

    /**
     * 记录一条版本变更。
     *
     * @param string $name        插件名
     * @param string $fromVersion 变更前版本
     * @param string $toVersion   变更后版本
     * @param string $operation   install / upgrade / rollback / uninstall
     * @param string $changelog   变更日志（可为空）
     * @param string $operator    操作者
     * @param array  $detail      附加信息（序列化为 JSON）
     * @return int|false 插入行数或 false
     */
    public static function record(
        string $name,
        string $fromVersion,
        string $toVersion,
        string $operation,
        string $changelog = '',
        string $operator = 'system',
        array $detail = [],
    ) {
        return Db::insert(
            'INSERT INTO kode_plugin_versions (name, from_version, to_version, operation, changelog, upgraded_by, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$name, $fromVersion, $toVersion, $operation, $changelog, $operator, json_encode($detail, JSON_UNESCAPED_UNICODE)]
        );
    }

    /**
     * 按插件名查版本历史（最新在前，限制 $limit 条）。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function list(string $name, int $limit = 20): array
    {
        try {
            $rows = Db::select(
                'SELECT id, name, from_version, to_version, operation, changelog, upgraded_by, detail, created_at FROM kode_plugin_versions WHERE name = ? ORDER BY created_at DESC, id DESC LIMIT ?',
                [$name, $limit]
            );
        } catch (\Throwable) {
            return [];
        }
        return $rows;
    }

    /**
     * 获取指定插件的上一版本（用于回滚目标定位）。
     *
     * @return string|null 上一个版本号
     */
    public static function previousVersion(string $name): ?string
    {
        $history = self::list($name, 10);
        // 找到最近一条 upgrade 记录，其 from_version 即为可回滚目标
        foreach ($history as $row) {
            if (($row['operation'] ?? '') === self::OP_UPGRADE) {
                return (string) $row['from_version'];
            }
        }
        // 没有 upgrade 记录，找 install 的 to_version 之前的版本（通常无）
        foreach ($history as $row) {
            if (($row['operation'] ?? '') === self::OP_INSTALL) {
                return null; // 没有升级历史，无法回滚
            }
        }
        return null;
    }

    /**
     * 回滚到指定版本（仅更新 kode_plugins.version，不执行代码级回滚）。
     *
     * @param string $name    插件名
     * @param string $target  目标版本
     * @param string $operator 操作者
     * @return array{ok: bool, message: string, version: string}
     */
    public static function rollback(string $name, string $target, string $operator = 'system'): array
    {
        $current = PluginMarket::states();
        $currentVersion = isset($current[$name]) ? (string) $current[$name]['version'] : null;

        if ($currentVersion === null) {
            return ['ok' => false, 'message' => "插件 '{$name}' 未安装", 'version' => ''];
        }
        if ($currentVersion === $target) {
            return ['ok' => false, 'message' => '当前已是目标版本', 'version' => $currentVersion];
        }

        // 更新 kode_plugins.version
        Db::update(
            'UPDATE kode_plugins SET version = ?, updated_at = NOW() WHERE name = ?',
            [$target, $name]
        );

        // 记录回滚
        self::record($name, $currentVersion, $target, self::OP_ROLLBACK, '', $operator, [
            'current' => $currentVersion,
        ]);

        PluginMarket::forgetStatusCache($name);

        return ['ok' => true, 'message' => "已回滚至 v{$target}", 'version' => $target];
    }

    /**
     * 从插件清单或 CHANGELOG.md 提取版本变更日志。
     *
     * 策略：
     *   1. 查 plugins/<Name>Plugin.plugin.json 的 changelog 字段
     *   2. 查 plugins/CHANGELOG.md 中对应版本的段落
     *   3. 返回空字符串
     *
     * @param string $name   插件名
     * @param string $fromV  变更前的版本
     * @param string $toV    变更后的版本
     */
    public static function changelogFor(string $name, string $fromV, string $toV): string
    {
        $baseDir = PluginDiscoverer::baseDir();

        // 1. 尝试 plugin.json 的 changelog 字段
        $class = PluginMarket::discover()[$name] ?? null;
        if ($class !== null) {
            $manifest = $baseDir . '/' . basename($class, 'php') . '.plugin.json';
            if (is_file($manifest)) {
                $data = json_decode((string) file_get_contents($manifest), true);
                $log = (string) ($data['changelog'] ?? '');
                if ($log !== '') {
                    return $log;
                }
                // 尝试 changelog 数组（按版本索引）
                if (isset($data['changelog'][$toV]) && is_string($data['changelog'][$toV])) {
                    return $data['changelog'][$toV];
                }
            }
        }

        // 2. 尝试 plugins/CHANGELOG.md
        $changelogPath = $baseDir . '/CHANGELOG.md';
        if (is_file($changelogPath)) {
            $content = (string) file_get_contents($changelogPath);
            // 查找 v<toV> 或 <toV> 段落
            $pattern = '#^##?\s*\[?(?:v)?' . preg_quote($toV, '#') . '\]?\s*\n(.*?)(?=^##?\s|\z)#ms';
            if (preg_match($pattern, $content, $m)) {
                return trim($m[1]);
            }
        }

        return '';
    }

    /**
     * 检查从 $fromV 到 $toV 的升级兼容性。
     *
     * 返回 { ok: bool, warnings: string[], blockers: string[] }
     *   - blockers 非空 = 不可升级
     *   - warnings 非空 = 可升级但有注意事项
     */
    public static function checkCompatibility(
        string $name,
        string $fromV,
        string $toV,
    ): array {
        $warnings = [];
        $blockers = [];

        // 版本比较：目标必须高于当前
        if (version_compare($toV, $fromV, '<=')) {
            $blockers[] = "目标版本 v{$toV} 不高于当前版本 v{$fromV}";
        }

        // 依赖校验
        $dep = PluginDependency::validate($name);
        if (!$dep['ok']) {
            $blockers[] = '依赖未就绪：' . implode('；', $dep['failed'] ?? []);
        }

        // 契约审计：零漂移才允许升级
        $pluginClass = PluginMarket::discover()[$name] ?? null;
        if ($pluginClass !== null) {
            $plugin = new $pluginClass();
            $audit = PluginContract::audit($plugin);
            if (!$audit['ok']) {
                $warnings[] = '存在契约漂移：' . implode('；', $audit['drift']);
            }
            if (!empty($audit['pending'])) {
                $warnings[] = '存在待确认项：' . implode('；', $audit['pending']);
            }
        }

        return [
            'ok' => $blockers === [],
            'warnings' => $warnings,
            'blockers' => $blockers,
        ];
    }
}
