<?php

declare(strict_types=1);

namespace app\support;

use Kode\Framework\Http\Resp;

/**
 * 插件打包 / 远程安装（官网市场联动）。
 *
 * 包格式（zip）：<Name>Plugin.php + <name>/*.php（控制器等）+ plugin.json 清单。
 * 安全红线：sha256 强制校验（缺失/不符直接拒绝）；解包拒绝 `..`/绝对路径；
 * 落盘目标固定框架根目录 plugins/ 下，不可越界；安装只落文件+登记，不执行包内代码
 * （路由/插件类启动期加载，reload 后生效）。
 */
final class PluginPackager
{
    /**
     * 打包本地插件为 zip（返回临时文件路径，失败 null）。
     */
    public static function pack(string $name): ?string
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return null;
        }
        $base = PluginMarket::baseDir();
        // 主类文件经反射定位（文件名与类名一致即可，不硬拼单复数）。
        $class = PluginMarket::discover()[$name] ?? null;
        if ($class === null) {
            return null;
        }
        $main = (new \ReflectionClass($class))->getFileName();
        if ($main === false || !is_file($main)) {
            return null;
        }

        $zipPath = sys_get_temp_dir() . "/plugin_{$name}_" . date('YmdHis') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $zip->addFile($main, basename($main));
        $dir = $base . '/' . $name;
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $zip->addFile($file->getPathname(), $name . '/' . substr($file->getPathname(), strlen($dir) + 1));
                }
            }
        }
        $manifest = [
            'name' => $name,
            'version' => '1.0.0',
            'tables' => [],
            'menus' => [],
            // 框架下限随真实环境导出（如 >=1.3），市场侧据此判断兼容性；
            // 读不到 installed.json 才回退 >=1.0（老环境/离线打包）。
            'kode' => self::frameworkConstraint(),
        ];
        try {
            $inst = PluginMarket::discover()[$name] ?? null;
            if ($inst !== null) {
                $obj = new $inst();
                if (method_exists($obj, 'version')) {
                    $manifest['version'] = (string) $obj->version();
                }
                if (method_exists($obj, 'tables')) {
                    $manifest['tables'] = array_values((array) $obj->tables());
                }
                if (method_exists($obj, 'menus')) {
                    $manifest['menus'] = array_values((array) $obj->menus());
                }
                // 随包携带能力契约，市场侧无需加载代码即可审阅/审计。
                $manifest['contract'] = PluginContract::describe($obj);
            }
        } catch (\Throwable) {
        }
        $zip->addFromString('plugin.json', (string) json_encode($manifest, JSON_UNESCAPED_UNICODE));
        $zip->close();

        return is_file($zipPath) ? $zipPath : null;
    }

    /**
     * 导出框架版本下限（`>=主.次`）。
     *
     * 从 vendor/composer/installed.json 读真实安装版本——硬编码 `>=1.0` 会让市场侧
     * 误判兼容性（插件可能已经用上了 1.3 才有的 Scheduling 能力）。只取主.次，
     * 避免把 patch 号也钉死导致小版本升级就「不兼容」。
     *
     * 读不到（离线打包、vendor 未安装）回退 `>=1.0`：保守放宽而非拒绝打包。
     */
    public static function frameworkConstraint(): string
    {
        $version = self::frameworkVersion();
        $parts = explode('.', $version);
        if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
            return '>=' . (int) $parts[0] . '.' . (int) $parts[1];
        }

        return '>=1.0';
    }

    /**
     * 当前框架真实版本（如 `1.3.12`，去 `v` 前缀）。
     *
     * 供两处消费：① `frameworkConstraint()` 导出打包清单下限；
     * ② `installRemote()` 校验市场包声明的 `kode` 下限（声明⟺实现零漂移）。
     *
     * 读不到回退 `1.0.0`：保守放宽而非拒绝安装。
     */
    public static function frameworkVersion(): string
    {
        // base_path() 在引导前可能返回相对路径，故同时试 __DIR__ 兜底（app/support → 上 2 层为根）。
        $file = base_path('vendor/composer/installed.json');
        if (!is_file($file)) {
            $file = dirname(__DIR__, 2) . '/vendor/composer/installed.json';
        }
        if (!is_file($file)) {
            return '1.0.0';
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (!is_array($decoded)) {
            return '1.0.0';
        }
        $packages = isset($decoded['packages']) && is_array($decoded['packages'])
            ? $decoded['packages']
            : (is_array($decoded) ? array_values($decoded) : []);
        foreach ($packages as $pkg) {
            if (($pkg['name'] ?? '') === 'kode/framework' && is_string($pkg['version'] ?? null)) {
                return ltrim($pkg['version'], 'v');
            }
        }

        return '1.0.0';
    }

    public static function download(string $zipPath, string $filename): \Psr\Http\Message\ResponseInterface
    {
        $body = (string) file_get_contents($zipPath);
        @unlink($zipPath);

        return Resp::make('', 200, [])
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($body))
            ->withBody(\Kode\Http\Psr7\Stream::create($body));
    }

    /**
     * 读 zip 内的 `plugin.json` 清单（只读，不落盘）。
     *
     * `extract()` 按扩展名过滤只收 `.php`，清单会被静默丢弃——但它是市场包
     * 唯一自述身份/版本/框架下限的地方，必须在落盘前读出来校验。
     *
     * @return array<string, mixed> 清单缺失或非法时返回 []
     */
    public static function readManifest(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [];
        }
        try {
            $raw = $zip->getFromName('plugin.json');
        } finally {
            $zip->close();
        }
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 校验市场包清单（纯函数，离线可测）。
     *
     * 三道门禁：
     *  1. 清单必须存在且声明了 name——市场包不自述身份就无法防偷名；
     *  2. 清单 name 必须等于请求的 name——否则「请求 foo、落盘 BarPlugin.php」
     *     会被登记成 foo=enabled，而 discover() 发现的是 bar，状态与实体错位；
     *  3. 清单 `kode` 下限必须被当前框架版本满足——声明了兼容性就必须校验，
     *     否则 `>=2.0` 的包能装到 1.3 上运行。
     *
     * @param array<string, mixed> $manifest
     * @return array{ok: bool, msg?: string, version?: string}
     */
    public static function verifyManifest(array $manifest, string $name): array
    {
        $declared = strtolower(trim((string) ($manifest['name'] ?? '')));
        if ($declared === '') {
            return ['ok' => false, 'msg' => '包内缺 plugin.json 或未声明 name（市场包必须自述身份）'];
        }
        if ($declared !== strtolower($name)) {
            return ['ok' => false, 'msg' => '清单声明插件名「' . $declared . '」与请求的「' . $name . '」不一致（疑似偷名）'];
        }

        $constraint = isset($manifest['kode']) && is_string($manifest['kode']) ? trim($manifest['kode']) : null;
        if ($constraint !== null && $constraint !== ''
            && !PluginDependency::satisfies(self::frameworkVersion(), $constraint)) {
            return ['ok' => false, 'msg' => '包要求框架 ' . $constraint . '，当前 ' . self::frameworkVersion() . '，不兼容'];
        }

        return ['ok' => true, 'version' => isset($manifest['version']) && is_string($manifest['version'])
            ? ltrim($manifest['version'], 'v')
            : '1.0.0'];
    }

    /**
     * @return array{ok: bool, msg?: string, name?: string, files?: int, version?: string, kode?: string}
     */
    public static function installRemote(string $url, string $sha256, string $name): array
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return ['ok' => false, 'msg' => '插件名非法'];
        }
        if (!preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'msg' => '只允许 http(s) 地址'];
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            return ['ok' => false, 'msg' => 'sha256 非法'];
        }
        // SSRF 防护：拒绝内网/保留地址（云元数据 169.254.169.254 等）。
        // 本地联调市场可用 MARKET_ALLOW_PRIVATE=true 放行。
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ip = gethostbyname($host);
        $allowPrivate = (bool) env('MARKET_ALLOW_PRIVATE', false);
        if (!$allowPrivate && filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return ['ok' => false, 'msg' => '拒绝内网地址'];
        }

        $tmp = sys_get_temp_dir() . "/plugin_dl_{$name}_" . bin2hex(random_bytes(4)) . '.zip';
        $verified = ['ok' => false];
        $manifest = [];
        try {
            $ch = curl_init($url);
            $fp = fopen($tmp, 'w');
            if ($fp === false) {
                return ['ok' => false, 'msg' => '下载失败'];
            }
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_FAILONERROR => true,
            ]);
            $ok = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);
            if (!$ok || $httpCode >= 400 || !is_file($tmp) || filesize($tmp) > 20 * 1024 * 1024) {
                @unlink($tmp);

                return ['ok' => false, 'msg' => '下载失败或包超限（20MB）'];
            }
            if (hash_file('sha256', $tmp) !== $sha256) {
                @unlink($tmp);

                return ['ok' => false, 'msg' => '校验失败：sha256 不匹配'];
            }

            // 清单门禁：身份一致 + 框架下限满足（必须在落盘前，落盘了就难回滚）。
            $manifest = self::readManifest($tmp);
            $verified = self::verifyManifest($manifest, $name);
            if (!$verified['ok']) {
                @unlink($tmp);

                return ['ok' => false, 'msg' => $verified['msg'] ?? '清单校验失败'];
            }

            $count = self::extract($tmp, $name);
            @unlink($tmp);
            if ($count === 0) {
                return ['ok' => false, 'msg' => '包内无有效文件'];
            }
        } catch (\Throwable $e) {
            @unlink($tmp);

            return ['ok' => false, 'msg' => '安装失败：' . $e->getMessage()];
        }

        // 状态与版本同时登记：version 缺失会让 upgrade() 永远从 0.0.0 起算、误判可升级。
        PluginMarket::setStatus($name, PluginMarket::ENABLED);
        PluginMarket::setVersion($name, (string) ($verified['version'] ?? '1.0.0'));

        return [
            'ok'      => true,
            'name'    => $name,
            'files'   => $count,
            'version' => (string) ($verified['version'] ?? '1.0.0'),
            'kode'    => isset($manifest['kode']) && is_string($manifest['kode'])
                ? $manifest['kode']
                : null,
        ];
    }

    /**
     * 安全解包到 plugins/（框架根目录，返回落盘文件数）。
     */
    private static function extract(string $zipPath, string $name): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return 0;
        }
        $base = PluginMarket::baseDir();
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            $entry = ltrim(str_replace('\\', '/', $entry), '/');
            // 越界/绝对路径/隐藏文件一律拒绝；仅收 .php。
            if ($entry === '' || str_contains($entry, '..') || !str_ends_with($entry, '.php')) {
                continue;
            }
            // 仅允许根级 *Plugin.php 与 <name>/ 下文件。
            $isMain = (bool) preg_match('/^[A-Za-z0-9_]+Plugin\.php$/', $entry);
            if (!$isMain && !str_starts_with($entry, $name . '/')) {
                continue;
            }
            $dest = $base . '/' . $entry;
            $dir = dirname($dest);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            if (is_file($dest)) {
                continue; // 已存在不覆盖（升级走 upgrade 流程，防偷梁换柱）。
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            file_put_contents($dest, $content);
            $count++;
        }
        $zip->close();

        return $count;
    }
}
