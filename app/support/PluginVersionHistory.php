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
 *   - 查询（list / knownVersions）：按插件名查历史、查登记过的版本
 *   - 改版本（changeVersion）：**唯一**派发口，改列 + 留痕一起做
 *   - 回滚（rollback）：把登记版本回退到账本里出现过的某个值
 *
 * 不职责：
 *   - 执行代码级回滚（删除/恢复文件）——那是备份恢复（PluginBackupService）的事
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
     * 版本变更的唯一派发口：改 `kode_plugins.version` 与留历史轨迹必须同时发生。
     *
     * 此前两者是分开的，于是「谁改了版本」取决于调用者记不记得再调一次 record()：
     * 只有 `POST /api/plugins/{name}/upgrade` 记得，install、install-remote、备份恢复
     * 三处都只改列不留痕。结果这张表看着是完整的账本，实际只记了四分之一的账 ——
     * 而 rollback() 全建在这张表上。
     *
     * 只在版本**真的变了**时记账：同版本重复调用不该往账本里灌噪声行。
     *
     * @param string|null $from 变更前的登记版本。缺省时读 `kode_plugins`（调用方已把行
     *                          建/改成目标值时必须显式传，否则「刚刚从没装过」会被读成
     *                          「上一版就是默认值」—— install 走 setStatus 建表行，
     *                          默认 version 恒为 1.0.0，不传就会记出一条假前置版本）。
     */
    public static function changeVersion(
        string $name,
        string $version,
        string $operation,
        string $changelog = '',
        string $operator = 'system',
        array $detail = [],
        ?string $from = null,
    ): void {
        $from ??= (string) (PluginMarket::states()[$name]['version'] ?? '');

        PluginMarket::setVersion($name, $version);

        if ($from !== $version) {
            self::record($name, $from, $version, $operation, $changelog, $operator, $detail);
        }
    }

    /**
     * 该插件登记过的版本（新→旧，去重，不含当前版本）。
     *
     * @return list<string>
     */
    public static function knownVersions(string $name, ?string $exclude = null): array
    {
        $out = [];
        foreach (self::list($name, 50) as $row) {
            foreach ([(string) ($row['to_version'] ?? ''), (string) ($row['from_version'] ?? '')] as $v) {
                if ($v !== '' && $v !== $exclude && !in_array($v, $out, true)) {
                    $out[] = $v;
                }
            }
        }

        return $out;
    }

    /**
     * 回滚到指定版本（只回退**登记**的版本号，不执行代码级回滚）。
     *
     * 目标版本必须是该插件账本里出现过的：不校验的话，`target=9.9.9` 这种手抖会把
     * `kode_plugins.version` 改成一个从没存在过的版本号，而磁盘代码没动 —— 版本自述面
     * 从此长期说谎，且下一轮 upgrade 会拿它当 `$from` 做 `version_compare`（把不存在的
     * 版本算成「可升级」）。真正的换代码是备份恢复（`POST /api/plugins/backup/restore`）。
     *
     * @param string $name    插件名
     * @param string $target  目标版本
     * @param string $operator 操作者
     * @return array{ok: bool, message: string, version: string, candidates?: list<string>}
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

        $candidates = self::knownVersions($name, $currentVersion);
        if (!in_array($target, $candidates, true)) {
            return [
                'ok' => false,
                'message' => "v{$target} 不是该插件登记过的版本，可回滚目标："
                    . ($candidates === [] ? '无（该插件还没有版本历史，换代码请走备份恢复）' : implode(', ', $candidates)),
                'version' => $currentVersion,
                'candidates' => $candidates,
            ];
        }

        self::changeVersion($name, $target, self::OP_ROLLBACK, '', $operator, [
            'current' => $currentVersion,
        ]);

        return [
            'ok' => true,
            'message' => "登记版本已回退至 v{$target}（只改了版本登记，插件代码未变；"
                . '要换回代码请走「备份恢复」）',
            'version' => $target,
            'candidates' => $candidates,
        ];
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
