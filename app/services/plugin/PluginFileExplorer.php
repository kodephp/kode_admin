<?php

declare(strict_types=1);

namespace app\support;

/**
 * 插件文件浏览器：列出插件目录内的实际文件（大小/修改时间/类型），并代编辑器/恢复落盘。
 *
 * 「这个插件有哪些文件」= 反射定位的主类文件 + `PluginMarket::baseDir()/name/` 整棵树。
 * 这条枚举面同时就是备份的抓取面与恢复的落盘面，所以**插件的兄弟类必须放在
 * `plugins/<name>/` 之下**（目录名 = name() 返回值）；放在根级或另起一个不同名的目录，
 * 备份就少抓文件，恢复出来是「半套文件 + 已启用」——下次启动直接 fatal。
 * 该约定由 tests/PluginLayoutGateTest.php 静态守住，不靠人记。
 *
 * 读写同一条归属线（ownershipFailure()）：落点只能属于这个插件，且始终留在插件根内，不可越界。
 *
 * 响应结构：{ ok, name, base_path, files: [{ path, size, human_size, extension, modified_at, is_main }], total, total_human }
 */
final class PluginFileExplorer
{
    /**
     * 单文件大小上限（读与写共用同一个数）。
     *
     * 为什么必须是一个数：备份=先 list 再 read，恢复=先 check 再 write。
     * 两边各自写死过（读 512KB、写 256KB），于是 256KB~512KB 的文件「导得出去、恢复不回来」，
     * 而 512KB 以上的文件导出时被跳过、恢复时压根看不见——一份少了最大文件的备份照样能
     * 「恢复成功」并把状态翻成启用，正是 AGENTS §4 点名的「半套文件 + 已启用 = 下次启动 fatal」。
     * 本项目的 plugins/frontend/WorkflowBridgeController.php 就有 515KB，这条不是假想敌。
     */
    public const MAX_FILE_BYTES = 1048576;

    /**
     * 列出插件目录内的所有文件。
     *
     * @param string $name 插件名（如 notices / demo）
     * @return array{ok: bool, name: string, base_path: string, files: array, total: int, total_human: string}
     */
    public static function list(string $name): array
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return self::fail($name, '非法插件名');
        }

        $base = PluginMarket::baseDir();
        $subDir = $base . '/' . $name;

        // 主类文件：通过反射定位
        $class = PluginMarket::discover()[$name] ?? null;
        $mainFile = null;
        if ($class !== null) {
            try {
                $mainFile = (new \ReflectionClass($class))->getFileName();
            } catch (\Throwable) {
                $mainFile = null;
            }
        }

        $files = [];
        $totalBytes = 0;

        // 1. 主类文件
        if ($mainFile !== null && is_file($mainFile)) {
            $files[] = self::fileEntry(
                $mainFile,
                $base,
                true,
            );
            $totalBytes += filesize($mainFile);
        }

        // 2. 子目录文件
        if (is_dir($subDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($subDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }
                // 点开头的文件不是插件代码（macOS 的 .DS_Store、编辑器的 .foo.swp），
                // 却会被下面的恢复白名单一口回掉，整份备份因此变成「导得出、恢复不回」
                if (str_starts_with($file->getFilename(), '.')) {
                    continue;
                }
                $relativePath = substr($file->getPathname(), strlen($base) + 1);
                $size = $file->getSize();
                $totalBytes += $size;

                $files[] = [
                    'path'        => $relativePath,
                    'size'        => $size,
                    'human_size'  => self::humanBytes($size),
                    'extension'   => strtolower($file->getExtension()),
                    'modified_at' => date('Y-m-d H:i:s', (int) $file->getMTime()),
                    'is_main'     => false,
                ];
            }
        }

        // 排序：主类优先，然后按路径字典序
        usort($files, static fn (array $a, array $b) => match (true) {
            $a['is_main'] && !$b['is_main'] => -1,
            !$a['is_main'] && $b['is_main'] => 1,
            default => strcmp($a['path'], $b['path']),
        });

        return [
            'ok'          => true,
            'name'        => $name,
            'base_path'   => $base,
            'files'       => $files,
            'total'       => count($files),
            'total_human' => self::humanBytes($totalBytes),
        ];
    }

    /**
     * 读取单个文件的源码（只读，上限 self::MAX_FILE_BYTES）。
     *
     * 归属线与 target() 完全一致：只允许读「这个插件自己的主类文件」与 `plugins/<name>/` 之下。
     * 老实现只判「在插件根内」，于是 name=notices 传 `wallet/WalletService.php`
     * 就能把别的插件源码读进响应（备份导出也是同一条路：list 谁的文件、read 谁的都拦不住）。
     *
     * @param string $name  插件名
     * @param string $path  相对路径（如 notices/Controller.php）
     * @return array{ok: bool, name: string, path: string, content: string, size: int}
     */
    public static function read(string $name, string $path): array
    {
        $rel = self::normalizePath($path);
        $refusal = self::ownershipFailure($name, $rel);
        if ($refusal !== null) {
            return ['ok' => false, 'name' => $name, 'path' => $path, 'content' => '', 'size' => 0, 'message' => $refusal];
        }

        $base = PluginMarket::baseDir();
        $realBase = realpath($base);
        $resolved = realpath($base . '/' . $rel);
        if ($resolved === false || !is_file($resolved)) {
            return ['ok' => false, 'name' => $name, 'path' => $path, 'content' => '', 'size' => 0, 'message' => '文件不存在'];
        }

        // 安全红线：必须在插件根目录内
        if ($realBase === false || strpos($resolved, $realBase . '/') !== 0) {
            return ['ok' => false, 'name' => $name, 'path' => $path, 'content' => '', 'size' => 0, 'message' => '路径越界'];
        }

        // 符号链接可以把 `plugins/notices/x.php` 指到 `plugins/wallet/y.php`：
        // 字符串归属与「还在插件根内」两道都过得了，所以按真实落点再判一次归属。
        $realRel = substr($resolved, strlen($realBase) + 1);
        $again = self::ownershipFailure($name, $realRel);
        if ($again !== null) {
            return ['ok' => false, 'name' => $name, 'path' => $path, 'content' => '', 'size' => 0, 'message' => '该路径不属于这个插件'];
        }

        $size = filesize($resolved);
        if ($size > self::MAX_FILE_BYTES) {
            return ['ok' => false, 'name' => $name, 'path' => $path, 'content' => '', 'size' => $size, 'message' => '文件超过 1MB 限制'];
        }

        return [
            'ok'      => true,
            'name'    => $name,
            'path'    => $path,
            'content' => file_get_contents($resolved),
            'size'    => $size,
        ];
    }

    /**
     * 写入文件内容（代码编辑器保存 / 备份恢复落盘）。
     *
     * 安全策略见 check()（判定）与 target()（归属）：白名单、大小上限、归属、符号链接，
     * 以及 `$allowCreate=false`（编辑器）时要求目标已存在。
     *
     * @param string $name    插件名
     * @param string $path    相对路径（如 notices/Controller.php）
     * @param string $content 新内容（允许空串：空文件是合法产物）
     * @param bool   $allowCreate 允许在插件自己的目录内新建缺失文件
     * @return array{ok: bool, name: string, path: string, size: int, message: string}
     */
    public static function write(string $name, string $path, string $content, bool $allowCreate = false): array
    {
        $target = self::target($name, $path, $content, $allowCreate);
        if ($target['ok'] !== true) {
            return self::writeResult($name, $path, 0, (string) $target['message'], false);
        }
        $resolved = (string) $target['path'];

        // 恢复允许补建目录：走到这里说明 target() 已判过归属，但「判的时候目录还不存在」，
        // 建完必须再认一次落点还在插件根内（不能拿 check 时的结论给 mkdir 后的世界背书）
        $dir = dirname($resolved);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                return self::writeResult($name, $path, 0, '目录创建失败', false);
            }
        }
        $realDir = realpath($dir);
        $realBase = realpath(PluginMarket::baseDir());
        if ($realDir === false || $realBase === false
            || ($realDir !== $realBase && strpos($realDir, $realBase . '/') !== 0)) {
            return self::writeResult($name, $path, 0, '路径越界', false);
        }

        // 写入前备份
        $bak = $resolved . '.bak';
        if (is_file($resolved)) {
            copy($resolved, $bak);
        }

        $bytes = file_put_contents($resolved, $content);
        if ($bytes === false) {
            // 写入失败，恢复备份
            if (is_file($bak)) {
                rename($bak, $resolved);
            }

            return self::writeResult($name, $path, 0, '写入失败（已恢复原文件）', false);
        }
        if (!is_file($bak)) {
            // 新建的文件：mode 由 umask 决定过一次了，再写死一次才算「明确授权的模式」
            @chmod($resolved, 0644);
        } else {
            // 写入成功，清理备份
            @unlink($bak);
        }

        return self::writeResult($name, $path, $bytes, "已保存（{$bytes} 字节）", true);
    }

    private static function writeResult(string $name, string $path, int $size, string $message, bool $ok): array
    {
        return ['ok' => $ok, 'name' => $name, 'path' => $path, 'size' => $size, 'message' => $message];
    }

    /**
     * 只判定、不落盘：可以写返回 null，否则返回拒绝原因。
     *
     * 为什么单独开这一个口：备份恢复必须**先把所有条目判完再落盘**（远程安装那轮学的
     * 同一课）。边判边写在失败路径上会留下半套文件，而「半套文件 + 状态已启用」是最难查的坏。
     */
    public static function check(string $name, string $path, string $content, bool $allowCreate = false): ?string
    {
        $target = self::target($name, $path, $content, $allowCreate);

        return $target['ok'] === true ? null : (string) $target['message'];
    }

    /**
     * 安全策略：
     *   1. 落点必须**属于这个插件**且留在插件根内：根级主文件或 `plugins/<name>/` 之下，
     *      `..`/空段/绝对路径在拼路径之前就拒，不等 `realpath()` 兜底
     *   2. 扩展名白名单：php / json / md / txt / yaml / yml
     *   3. 内容大小上限 self::MAX_FILE_BYTES（与 read() 同一个数，见常量注释）
     *   4. `$allowCreate=false`（编辑器）要求目标已存在；只有 `true`（备份恢复）才允许
     *      在插件目录内补建缺失的文件与目录（建目录 0775、新文件 0644，不跟 umask 赌）
     *   5. 符号链接一律不写：恢复的 JSON 是用户递进来的，链接指向哪儿都有可能
     *
     * 归一落点：先判归属，再谈存在；全程不写不建（建由 write() 负责并复验）。
     *
     * 为什么归属必须在字符串阶段判：`realpath()` 会把 `../` 折掉，折完仍在 `plugins/` 里
     * 就算「没出插件根」，于是 name=notices 的一次保存可以写到 wallet 的文件上去
     * ——老实现只有这一道越界判定。恢复读的是用户递进来的 JSON，这条路必须堵死。
     *
     * 允许的形状与 export 的枚举面严格一致：插件根下的主类文件、或 `plugins/<name>/` 之内。
     * 根级那一档还要再认一次身份：只收**这个插件自己的主类文件**（由 discover() 反查），
     * 否则 name=notices 的一份 JSON 就能拿 `NoticePlugin.php` 当「根级文件」写进去。
     *
     * @return array{ok: bool, path?: string, message?: string}
     */
    private static function target(string $name, string $path, string $content, bool $allowCreate): array
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return ['ok' => false, 'message' => '非法插件名'];
        }

        // 扩展名白名单
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed = ['php', 'json', 'md', 'txt', 'yaml', 'yml'];
        if (!in_array($ext, $allowed, true)) {
            return ['ok' => false, 'message' => "不支持的文件类型 .{$ext}（仅允许: " . implode(', ', $allowed) . "）"];
        }

        if (strlen($content) > self::MAX_FILE_BYTES) {
            return ['ok' => false, 'message' => '内容超过 1MB 限制'];
        }

        $realBase = realpath(PluginMarket::baseDir());
        if ($realBase === false) {
            return ['ok' => false, 'message' => '插件根目录不存在'];
        }

        $rel = self::normalizePath($path);
        $refusal = self::ownershipFailure($name, $rel);
        if ($refusal !== null) {
            return ['ok' => false, 'message' => $refusal];
        }

        // 逐段下探已存在的部分：每落地一层就 realpath 一次，确认还在插件根内、且这一段不是符号链接。
        // 遇到第一个不存在的目录就收手——后面的段还没落地，没什么可判的，归属已由上面的
        // 字符串检查兜住；真正要建的目录由 write() 建完再复验一次。
        $segments = explode('/', $rel);
        $parent = $realBase;
        $depth = count($segments) - 1;
        for ($i = 0; $i < $depth; $i++) {
            $candidate = $parent . '/' . $segments[$i];
            if (is_link($candidate)) {
                return ['ok' => false, 'message' => '路径含符号链接，拒绝写入'];
            }
            if (!is_dir($candidate)) {
                if (!$allowCreate) {
                    return ['ok' => false, 'message' => '文件不存在'];
                }

                return ['ok' => true, 'path' => $candidate . '/' . implode('/', \array_slice($segments, $i + 1))];
            }
            $real = realpath($candidate);
            if ($real === false || strpos($real, $realBase . '/') !== 0) {
                return ['ok' => false, 'message' => '路径越界'];
            }
            $parent = $real;
        }

        $resolved = $parent . '/' . $segments[$depth];
        if (is_link($resolved)) {
            return ['ok' => false, 'message' => '目标是符号链接，拒绝写入'];
        }
        if ($allowCreate === false && !is_file($resolved)) {
            return ['ok' => false, 'message' => '文件不存在'];
        }

        return ['ok' => true, 'path' => $resolved];
    }

    /**
     * 路径归一（只统一分隔符与空白，不做判定）：判定见 ownershipFailure()。
     */
    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    /**
     * 归属判定：这个 `path` 是不是 `name` 这个插件的文件？不是则返回拒绝原因。
     *
     * 读、写、导出、恢复四条路共用这一份判据（各写一遍迟早只改对一个）。允许的形状只有两种：
     *   - 插件根级、且**正是这个插件自己的主类文件**（身份由 discover() 反查，不按命名约定猜）；
     *   - `plugins/<name>/` 之下（含多级子目录）。
     *
     * 全程不碰文件系统：`realpath()` 会把 `../` 折掉，折完仍在 `plugins/` 里就查不出
     * 「换了插件」——归属必须在拼路径之前判。
     */
    private static function ownershipFailure(string $name, string $path): ?string
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return '非法插件名';
        }

        $rel = self::normalizePath($path);
        if ($rel === '' || str_contains($rel, "\0") || str_starts_with($rel, '/')
            || preg_match('#^[a-zA-Z]:[/\\\\]#', $rel) === 1) {
            return '路径非法';
        }
        $segments = explode('/', $rel);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return '路径含 `..`、`.` 或空段';
        }

        $dir = dirname($rel);
        if ($dir === '.') {
            return basename($rel) === self::mainFileOf($name) ? null : '根级只允许这个插件自己的主类文件';
        }
        if ($dir !== $name && !str_starts_with($dir, $name . '/')) {
            return '该路径不属于这个插件';
        }

        return null;
    }

    /**
     * 这个插件的主类文件名（`plugins/XxxPlugin.php` 那一段），认不出来返回空串。
     *
     * 唯一可靠来源是 discover()：插件名与文件名并不总是对得上（`workflow` 的主类是
     * `WorkflowBridgePlugin.php`），按命名约定拼字符串必错。
     */
    private static function mainFileOf(string $name): string
    {
        $class = PluginMarket::discover()[$name] ?? null;
        if ($class === null) {
            return '';
        }
        try {
            $file = (new \ReflectionClass($class))->getFileName();
        } catch (\Throwable) {
            return '';
        }

        return is_string($file) ? basename($file) : '';
    }

    private static function fileEntry(string $filepath, string $base, bool $isMain): array
    {
        $size = filesize($filepath);
        $relativePath = substr($filepath, strlen($base) + 1);
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

        return [
            'path'        => $relativePath,
            'size'        => $size,
            'human_size'  => self::humanBytes($size),
            'extension'   => $ext,
            'modified_at' => date('Y-m-d H:i:s', (int) filemtime($filepath)),
            'is_main'     => $isMain,
        ];
    }

    private static function fail(string $name, string $message): array
    {
        return [
            'ok'          => false,
            'name'        => $name,
            'base_path'   => '',
            'files'       => [],
            'total'       => 0,
            'total_human' => '',
            'message'     => $message,
        ];
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1024 / 1024, 2) . ' MB';
    }
}
