<?php

declare(strict_types=1);

namespace app\support;

/**
 * 插件代码搜索：跨插件文件全文搜索关键词。
 *
 * 搜索范围：插件主类文件 + 子目录内所有 PHP/JSON/MD/TXT 文件。
 * 匹配方式：大小写不敏感的子串匹配（array_key_exists 模式）。
 * 返回：命中文件列表 + 上下文片段（±3 行）。
 *
 * 响应：{ ok, keyword, files: [{ path, line, context, filename }], total, message }
 */
final class PluginCodeSearcher
{
    private const MAX_RESULTS = 200;
    private const CONTEXT_LINES = 3;
    private const MAX_FILE_SIZE = 512 * 1024; // 512KB

    /**
     * 搜索关键词。
     *
     * @param string $keyword  搜索关键词
     * @param string|null $pluginName 可选，限定插件名（null = 全部插件）
     * @return array{ok: bool, keyword: string, files: array, total: int, message: string}
     */
    public static function search(string $keyword, ?string $pluginName = null): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['ok' => false, 'keyword' => $keyword, 'files' => [], 'total' => 0, 'message' => '关键词必填'];
        }

        if (strlen($keyword) > 128) {
            return ['ok' => false, 'keyword' => $keyword, 'files' => [], 'total' => 0, 'message' => '关键词过长（最多 128 字符）'];
        }

        $base = PluginMarket::baseDir();
        $discover = PluginMarket::discover();
        $results = [];
        $totalFiles = 0;

        // 确定搜索范围
        $targets = $pluginName !== null
            ? ($discover[$pluginName] !== null ? [$pluginName] : [])
            : array_keys($discover);

        if ($targets === []) {
            return ['ok' => false, 'keyword' => $keyword, 'files' => [], 'total' => 0, 'message' => '无匹配插件'];
        }

        foreach ($targets as $name) {
            if (count($results) >= self::MAX_RESULTS) {
                break;
            }

            // 主类文件
            $class = $discover[$name];
            try {
                $mainFile = (new \ReflectionClass($class))->getFileName();
                if ($mainFile !== false && is_file($mainFile)) {
                    $relative = self::relativePath($mainFile, $base);
                    $found = self::searchFile($mainFile, $keyword, $relative, $base);
                    $results = array_merge($results, $found);
                    $totalFiles++;
                }
            } catch (\Throwable) {
                // 跳过无法反射的类
            }

            // 子目录文件
            $subDir = $base . '/' . $name;
            if (is_dir($subDir)) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($subDir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    /** @var \SplFileInfo $file */
                    if (!$file->isFile()) {
                        continue;
                    }
                    $ext = strtolower($file->getExtension());
                    if (!in_array($ext, ['php', 'json', 'md', 'txt', 'yaml', 'yml'], true)) {
                        continue;
                    }
                    if ($file->getSize() > self::MAX_FILE_SIZE) {
                        continue;
                    }
                    $relative = self::relativePath($file->getPathname(), $base);
                    $found = self::searchFile($file->getPathname(), $keyword, $relative, $base);
                    $results = array_merge($results, $found);
                    $totalFiles++;

                    if (count($results) >= self::MAX_RESULTS) {
                        break 2;
                    }
                }
            }
        }

        return [
            'ok'      => true,
            'keyword' => $keyword,
            'files'   => $results,
            'total'   => count($results),
            'message' => sprintf('搜索完成：%d 个匹配，扫描 %d 个文件', count($results), $totalFiles),
        ];
    }

    private static function searchFile(string $filepath, string $keyword, string $relativePath, string $base): array
    {
        $resolved = realpath($filepath);
        $realBase = realpath($base);
        if ($resolved === false || $realBase === false || strpos($resolved, $realBase) !== 0) {
            return [];
        }

        $content = file_get_contents($filepath);
        if ($content === false || $content === '') {
            return [];
        }

        $lines = explode("\n", $content);
        $lowerKeyword = mb_strtolower($keyword);
        $matches = [];

        for ($i = 0; $i < count($lines); $i++) {
            if (mb_strpos(mb_strtolower($lines[$i]), $lowerKeyword) === false) {
                continue;
            }

            // 提取上下文
            $start = max(0, $i - self::CONTEXT_LINES);
            $end = min(count($lines), $i + self::CONTEXT_LINES + 1);
            $context = '';
            for ($j = $start; $j < $end; $j++) {
                $prefix = $j === $i ? '>> ' : '   ';
                $context .= $prefix . ($j + 1) . ': ' . $lines[$j] . "\n";
            }

            $matches[] = [
                'path'     => $relativePath,
                'line'     => $i + 1,
                'context'  => rtrim($context),
                'filename' => pathinfo($relativePath, PATHINFO_FILENAME),
            ];
        }

        return $matches;
    }

    private static function relativePath(string $filepath, string $base): string
    {
        return substr($filepath, strlen($base) + 1);
    }
}
