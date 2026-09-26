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
    /** 市场包体积上限（下载侧闸门，与 SafeArchive 的解压预算分开）。 */
    public const MAX_PACKAGE_BYTES = 20 * 1024 * 1024;

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
     * 从市场拉包并落盘 + 登记（**不**执行包内代码）。
     *
     * @param string $operator 记账操作者；HTTP 入口传登录 uid，让账本能指回是谁装的
     * @return array{ok: bool, msg?: string, name?: string, files?: int, version?: string, kode?: string}
     */
    public static function installRemote(string $url, string $sha256, string $name, string $operator = 'system'): array
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return ['ok' => false, 'msg' => '插件名非法'];
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            return ['ok' => false, 'msg' => 'sha256 非法'];
        }
        // SSRF 口径统一走 RemoteFetch：逐跳过闸 + 钉住已校验的 IP（见该类注释）
        $vet = RemoteFetch::vet($url);
        if ($vet['ok'] !== true) {
            return ['ok' => false, 'msg' => (string) ($vet['msg'] ?? '地址不可用')];
        }

        $tmp = sys_get_temp_dir() . "/plugin_dl_{$name}_" . bin2hex(random_bytes(4)) . '.zip';
        $verified = ['ok' => false];
        $manifest = [];
        $count = 0;
        try {
            $fetched = RemoteFetch::toFile($url, $tmp, self::MAX_PACKAGE_BYTES);
            if (!$fetched['ok']) {
                return ['ok' => false, 'msg' => (string) $fetched['msg']];
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

        // 状态与版本同时登记：version 缺失会让 upgrade() 从空版本起算、误判可升级。
        // 走 changeVersion 而不是 setVersion：这一处此前只改列不留痕，账本于是缺了
        // 「谁在什么时候从市场装了哪个版本」整一类记录（install/备份恢复同理）。
        $version = (string) ($verified['version'] ?? '1.0.0');
        $prior = (string) (PluginMarket::states()[$name]['version'] ?? '');
        PluginMarket::setStatus($name, PluginMarket::ENABLED);
        PluginVersionHistory::changeVersion($name, $version, PluginVersionHistory::OP_INSTALL, '', $operator, [
            'via' => 'marketplace',
            // 只记 sha256 不记 url：地址串里可能带私有市场的访问凭证，而这份 detail
            // 会经 GET /api/plugins/{name}/version-history 出给任何有 plugins:view 的账号
            'sha256' => $sha256,
        ], $prior);

        return [
            'ok'      => true,
            'name'    => $name,
            'files'   => $count,
            'version' => $version,
            'kode'    => isset($manifest['kode']) && is_string($manifest['kode'])
                ? $manifest['kode']
                : null,
        ];
    }

    /**
     * 市场包条目规则（纯函数，离线可测）：true 落盘，false 有意跳过，违规抛错。
     *
     * 「跳过」只留给业务过滤，安全违规一律抛——逐条目 `continue` 会把半装当成功。
     *
     * @throws \RuntimeException 非 .php / 隐藏条目 / 落点不在约定的两处
     */
    public static function packageEntry(string $rel, string $name): bool
    {
        // 清单由 readManifest() 在落盘前消费，不入库：它不是代码，
        // 留在 plugins/ 根下只会被别的扫描路径误认。
        if ($rel === 'plugin.json') {
            return false;
        }
        // 仅收 .php：市场协议只传代码，别的扩展名（.so/.sh/二进制）不可审阅
        if (!str_ends_with($rel, '.php')) {
            throw new \RuntimeException('包内含非 .php 条目：' . $rel);
        }
        // 点开头条目（.env.php / .htaccess）：落在 web 可达目录里就是配置注入。
        // `(^|/)\.` 顺带盖住了 `..` 段——越界在 SafeArchive::normalize 里另有一道，两层不共享实现。
        if (preg_match('#(^|/)\.#', $rel) === 1) {
            throw new \RuntimeException('包内含隐藏文件条目：' . $rel);
        }
        $isMain = (bool) preg_match('/^[A-Za-z0-9_]+Plugin\.php$/', $rel);
        if (!$isMain && !str_starts_with($rel, $name . '/')) {
            throw new \RuntimeException('包内条目位置非法：' . $rel);
        }

        return true;
    }

    /**
     * 安全解包到 plugins/（框架根目录，返回落盘文件数）。
     *
     * 违规条目由 SafeArchive 抛错并回滚已落盘文件——早先的逐条目 `continue`
     * 会把「半装」当成功返回给调用方，状态照常翻成启用，缺文件要到运行时才炸。
     */
    private static function extract(string $zipPath, string $name): int
    {
        return SafeArchive::extract(
            $zipPath,
            PluginMarket::baseDir(),
            static fn (string $rel): bool => self::packageEntry($rel, $name),
            true
        );
    }
}
