<?php

declare(strict_types=1);

namespace app\support;

/**
 * 插件备份/恢复：导出插件完整状态（文件+配置+运行时状态）为 JSON，从 JSON 恢复。
 *
 * 导出结构：
 * {
 *   version: 1,
 *   exported_at: "2026-09-13 02:00:00",
 *   framework: "1.4.3",
 *   plugins: [
 *     {
 *       name: "notices",
 *       status: "enabled",
 *       version: "1.0.0",
 *       config: [{ key, value, label, ... }],
 *       complete: true,
 *       missing: ['notices/big.md（文件超过 1MB 限制）'],
 *       files: [{ path, content, size, is_main }],
 *     }
 *   ]
 * }
 *
 * 恢复时逐个插件写入文件 + 恢复配置 + 设置状态；**任一条目没落盘就不算这个插件恢复成功**，
 * 也不会去翻它的配置与状态（宁可失败得明明白白，不要「启用但缺文件」）。
 * `complete:false` 同样整插件判失败：那种备份里压根没有缺的那个文件，恢复侧看不出来。
 * 安全：落点经归属校验（只能属于该插件、留在插件根内，见 PluginFileExplorer::target()），
 * 扩展名白名单 + 单文件大小上限（与 read() 同一个常量，见 PluginFileExplorer::MAX_FILE_BYTES）。
 * 边界：插件目录被整个删掉时，根级主类那一条认不出归属（discover() 已经没有这个插件），
 * 恢复会照实报失败 —— 那种情况请走市场重装，而不是让一份 JSON 凭空铸一个新主类文件。
 */
final class PluginBackupService
{
    private const FORMAT_VERSION = 1;

    /**
     * 导出指定插件的完整备份。
     *
     * @param string $name 插件名
     * @return array{ok: bool, name: string, json: string, missing: list<string>, message: string}
     */
    public static function export(string $name): array
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return self::fail($name, '非法插件名');
        }

        $discovered = PluginMarket::discover();
        if (!isset($discovered[$name])) {
            return self::fail($name, '插件不存在');
        }

        $states = PluginMarket::states();
        $state = $states[$name] ?? ['status' => PluginMarket::ENABLED, 'version' => '1.0.0'];

        // 文件列表
        $fileList = PluginFileExplorer::list($name);
        $files = [];
        $missing = [];
        foreach ($fileList['files'] as $entry) {
            $read = PluginFileExplorer::read($name, $entry['path']);
            if ($read['ok']) {
                $files[] = [
                    'path'    => $entry['path'],
                    'content' => $read['content'],
                    'size'    => $entry['size'],
                    'is_main' => $entry['is_main'],
                ];
                continue;
            }
            // 读不动的条目过去是静默跳过的：这份备份就此缺一个文件，而消息只说
            // 「已导出 N 个文件」，谁也看不出它不完整（超过单文件上限是常态原因）
            $missing[] = $entry['path'] . '（' . (string) $read['message'] . '）';
        }

        // 配置
        $config = PluginConfig::all($name);

        $payload = [
            'version'       => self::FORMAT_VERSION,
            'exported_at'   => date('Y-m-d H:i:s'),
            'framework'     => \Kode\Framework\Application::VERSION,
            'plugins'       => [
                [
                    'name'    => $name,
                    'status'  => $state['status'],
                    'version' => $state['version'] ?? '1.0.0',
                    // 自述完整性：读不动的文件在恢复侧根本不会出现，缺哪个只有导出时知道。
                    // 不落这两个键，一份少了最大文件的备份就能「恢复成功」并把状态翻成启用。
                    'complete' => $missing === [],
                    'missing' => $missing,
                    'config'  => $config,
                    'files'   => $files,
                ],
            ],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return self::fail($name, 'JSON 编码失败');
        }

        return [
            'ok'      => true,
            'name'    => $name,
            'json'    => $json,
            'missing' => $missing,
            'message' => "已导出（" . count($files) . " 个文件，" . count($config) . " 项配置）"
                . ($missing === [] ? '' : '；未含 ' . count($missing) . ' 个读不动的条目：' . implode('、', $missing)),
        ];
    }

    /**
     * 从 JSON 恢复插件。
     *
     * 每个条目都要有下落：文件写不进去就不算恢复，也不去动它的配置与状态。
     * 老写法把 `write()` 的返回值丢在一边（注释写着「写入失败不阻断」），
     * 而 `write()` 在目标不存在时本来就失败 —— 于是一个被删掉的插件「恢复完成：1 个成功」，
     * 状态还被翻成 enabled，下次启动直接 fatal。
     *
     * @param string $json 备份 JSON 字符串
     * @param string $operator 记账操作者；HTTP 入口传登录 uid
     * @return array{ok: bool, restored: string[], skipped: string[], failed: array<string, list<string>>, message: string}
     *   `failed` 为 `插件名 => 原因列表`；有 failed 即 `ok:false`（部分成功不是成功）。
     */
    public static function import(string $json, string $operator = 'system'): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return self::importResult([], [], [], 'JSON 解析失败');
        }

        if (!isset($data['version']) || !isset($data['plugins'])) {
            return self::importResult([], [], [], '备份格式无效');
        }
        // 版本号不是摆设：将来结构变了，按 v1 的形状半恢复一遍比不恢复更难查
        if ((int) $data['version'] !== self::FORMAT_VERSION) {
            return self::importResult([], [], [], '不支持的备份格式版本 ' . $data['version'] . '（本实现只认 v' . self::FORMAT_VERSION . '）');
        }

        $restored = [];
        $skipped = [];
        /** @var array<string, list<string>> $failed */
        $failed = [];

        foreach ((array) $data['plugins'] as $rawPlugin) {
            $plugin = (array) $rawPlugin;
            $name = (string) ($plugin['name'] ?? '');
            if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
                $skipped[] = $name === '' ? '(无名条目)' : $name;
                continue;
            }

            // 恢复文件：先全量判定，再全量落盘。
            // 反序做（逐个 write）会在第 N 条失败时留下前 N-1 条已经写进磁盘的状态——
            // 那正是「半装即启用」的另一个入口，且这时候配置和状态还不能翻。
            $files = (array) ($plugin['files'] ?? []);
            $errors = [];
            $ready = [];
            // 备份自述不完整 ⇒ 一条都不落。导出时读不动的文件（超过单文件上限）
            // 根本不在 `files` 里，恢复侧看不见缺了什么，只能靠导出记下的小票。
            $unreadable = array_values(array_filter((array) ($plugin['missing'] ?? []), 'is_string'));
            if (($plugin['complete'] ?? true) === false || $unreadable !== []) {
                $errors[] = '备份不完整，缺 ' . count($unreadable) . ' 个文件：' . implode('、', $unreadable)
                    . '（该插件请改用代码仓库或市场重装补齐）';
            }
            if ($files === []) {
                $errors[] = '备份里一个文件都没有';
            }
            foreach ($files as $rawFile) {
                $file = (array) $rawFile;
                $path = trim((string) ($file['path'] ?? ''));
                if ($path === '') {
                    $errors[] = '(缺 path)';
                    continue;
                }
                // 空内容是合法产物（空文件），只有「没有这个字段」才算条目坏了
                if (!array_key_exists('content', $file) || !is_string($file['content'])) {
                    $errors[] = $path . '：条目缺少 content';
                    continue;
                }
                $refusal = PluginFileExplorer::check($name, $path, $file['content'], true);
                if ($refusal !== null) {
                    $errors[] = $path . '：' . $refusal;
                    continue;
                }
                $ready[] = [$path, $file['content']];
            }

            if ($errors === []) {
                foreach ($ready as [$path, $content]) {
                    $write = PluginFileExplorer::write($name, $path, $content, true);
                    if ($write['ok'] !== true) {
                        // 判过还失败 = 落盘时才出的事（磁盘/权限/竞态）。照实记，别吞
                        $errors[] = $path . '：' . $write['message'];
                    }
                }
            }

            if ($errors !== []) {
                $failed[$name] = $errors;
                continue;
            }

            // 恢复配置
            $config = (array) ($plugin['config'] ?? []);
            if ($config !== []) {
                PluginConfig::purge($name);
                PluginConfig::save($name, $config);
            }

            // 恢复状态
            $status = (string) ($plugin['status'] ?? PluginMarket::ENABLED);
            $version = (string) ($plugin['version'] ?? '1.0.0');
            $prior = (string) (PluginMarket::states()[$name]['version'] ?? '');
            if (in_array($status, [PluginMarket::ENABLED, PluginMarket::PAUSED, PluginMarket::UNINSTALLED], true)) {
                PluginMarket::setStatus($name, $status);
            }
            if ($version !== '') {
                // 版本登记走唯一派发口：恢复过的痕迹此前在账本里完全不存在，
                // 于是「这台机器上的版本号是被哪一次恢复带进来的」无从回答。
                // 方向按比较定：没有登记行=装回来，往低走=回滚，其余=升级
                // （changeVersion 只在版本真变了时记账，同版本恢复不留噪声行）。
                if ($prior === '') {
                    $op = PluginVersionHistory::OP_INSTALL;
                } elseif (version_compare($version, $prior, '<')) {
                    $op = PluginVersionHistory::OP_ROLLBACK;
                } else {
                    $op = PluginVersionHistory::OP_UPGRADE;
                }
                PluginVersionHistory::changeVersion(
                    $name,
                    $version,
                    $op,
                    '',
                    $operator,
                    ['via' => 'backup-restore'],
                    $prior
                );
            }

            $restored[] = $name;
        }

        $message = sprintf('恢复完成：%d 个成功，%d 个跳过', count($restored), count($skipped));
        if ($failed !== []) {
            $detail = [];
            foreach ($failed as $pluginName => $reasons) {
                $detail[] = $pluginName . '（' . $reasons[0] . (count($reasons) > 1 ? ' 等 ' . count($reasons) . ' 项' : '') . '）';
            }
            $message .= sprintf('，%d 个插件未落盘（其配置与状态均未改动）：%s', count($failed), implode('、', $detail));
        }

        return self::importResult($restored, $skipped, $failed, $message);
    }

    /**
     * @param list<string>                $restored
     * @param list<string>                $skipped
     * @param array<string, list<string>> $failed
     */
    private static function importResult(array $restored, array $skipped, array $failed, string $message): array
    {
        return [
            'ok'       => $restored !== [] && $failed === [],
            'restored' => $restored,
            'skipped'  => $skipped,
            'failed'   => $failed,
            'message'  => $message,
        ];
    }

    private static function fail(string $name, string $message): array
    {
        return [
            'ok'      => false,
            'name'    => $name,
            'json'    => '',
            'missing' => [],
            'message' => $message,
        ];
    }
}
