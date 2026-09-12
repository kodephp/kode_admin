<?php

declare(strict_types=1);

namespace app\support;

/**
 * 插件文件浏览器：列出插件目录内的实际文件（大小/修改时间/类型）。
 *
 * 只读：不修改任何文件。扫描范围限定为 PluginMarket::baseDir()/name/ 子目录，
 * 加上通过反射定位的主类文件。不可越界访问。
 *
 * 响应结构：{ ok, name, base_path, files: [{ path, size, human_size, extension, modified_at, is_main }], total, total_human }
 */
final class PluginFileExplorer
{
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
     * 读取单个文件的源码（只读，限 500KB）。
     *
     * @param string $name  插件名
     * @param string $path  相对路径（如 notices/Controller.php）
     * @return array{ok: bool, name: string, path: string, content: string, size: int}
     */
    public static function read(string $name, string $path): array
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return ['ok' => false, 'name' => $name, 'path' => $path, 'content' => '', 'size' => 0, 'message' => '非法插件名'];
        }

        $base = PluginMarket::baseDir();
        $resolved = realpath($base . '/' . $path);
        if ($resolved === false) {
            return ['ok' => false, 'name' => $name, 'path' => $path, 'content' => '', 'size' => 0, 'message' => '文件不存在'];
        }

        // 安全红线：必须在插件根目录内
        $realBase = realpath($base);
        if ($realBase === false || strpos($resolved, $realBase) !== 0) {
            return ['ok' => false, 'name' => $name, 'path' => $path, 'content' => '', 'size' => 0, 'message' => '路径越界'];
        }

        $size = filesize($resolved);
        if ($size > 512 * 1024) {
            return ['ok' => false, 'name' => $name, 'path' => $path, 'content' => '', 'size' => $size, 'message' => '文件超过 500KB 限制'];
        }

        return [
            'ok'      => true,
            'name'    => $name,
            'path'    => $path,
            'content' => file_get_contents($resolved),
            'size'    => $size,
        ];
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
