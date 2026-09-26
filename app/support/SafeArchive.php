<?php

declare(strict_types=1);

namespace app\support;

/**
 * 外部包的解包闸门（zip / tar / tar.gz）：逐条目校验，越界即整包中止并回滚已落盘文件。
 *
 * 为什么不用现成的：
 *  - `ZipArchive::extractTo()` 不校验条目路径，`../../x.php` 直接写到目标目录外（zip-slip）；
 *  - `PharData` 对 bsdtar/GNU tar 迭代恒为 0 条目（实测），「解出来是空的」比解错更难发现；
 *  - 逐条目 `continue` 跳过违规 = 静默半装：调用方拿到 count>0，还会把状态翻成「已启用」。
 *    所以这里违规一律抛错，「跳过」只留给调用方的业务过滤（$accept 返回 false）。
 *
 * tar 侧只认普通文件与目录：符号/硬链接、字符/块设备、FIFO 一律判违规
 * （链接条目落进 web 根之后，任何一次「顺着路径写」都会写到目录外）。
 */
final class SafeArchive
{
    /** 解包总预算（压缩炸弹：20MB 的包能炸出 GB 级）。 */
    public const MAX_TOTAL = 100 * 1024 * 1024;

    /** 单个条目上限。 */
    public const MAX_ENTRY = 50 * 1024 * 1024;

    /**
     * @param callable(string): bool|null $accept     条目收不收（业务过滤：如只要 .php / 拒 .php）
     * @param bool                        $noOverwrite 落点已有同名文件时是否拒绝（市场包必须拒绝）
     * @param int|null                    $maxEntry    单条目上限，null = self::MAX_ENTRY（测试用小额）
     * @param int|null                    $maxTotal    总预算，null = self::MAX_TOTAL
     *
     * @return int 落盘文件数
     *
     * @throws \RuntimeException 条目违规 / 超预算 / 落盘失败；抛出时本次已写文件全部回滚
     */
    public static function extract(
        string $archive,
        string $targetDir,
        ?callable $accept = null,
        bool $noOverwrite = true,
        ?int $maxEntry = null,
        ?int $maxTotal = null
    ): int {
        $root = realpath($targetDir);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('解包目标目录不存在');
        }
        $caps = [$maxEntry ?? self::MAX_ENTRY, $maxTotal ?? self::MAX_TOTAL];

        $lower = strtolower($archive);
        $written = [];
        $madeDirs = [];

        try {
            if (str_ends_with($lower, '.zip')) {
                return self::extractZip($archive, $root, $accept, $noOverwrite, $caps, $written, $madeDirs);
            }
            if (str_ends_with($lower, '.tar') || str_ends_with($lower, '.tar.gz') || str_ends_with($lower, '.tgz')) {
                return self::extractTar($archive, $root, $accept, $noOverwrite, $caps, $written, $madeDirs);
            }

            throw new \RuntimeException('不支持的包格式：仅 zip / tar / tar.gz');
        } catch (\Throwable $e) {
            foreach ($written as $file) {
                @unlink($file);
            }
            foreach (array_reverse($madeDirs) as $dir) {
                @rmdir($dir);
            }

            throw $e;
        }
    }

    /**
     * 条目名 → 安全的相对路径。绝对路径、`..`、盘符、NUL、空段都算违规。
     * 反斜杠按目录分隔归一（Windows 打的包），归一后再判。
     */
    public static function normalize(string $entry): string
    {
        $name = str_replace('\\', '/', trim($entry));
        if ($name === '' || strlen($name) > 512 || str_contains($name, "\0")) {
            throw new \RuntimeException('包内条目名非法');
        }
        $name = ltrim($name, '/');
        if (preg_match('#^[A-Za-z]:#', $name) === 1) {
            throw new \RuntimeException('包内条目越界：' . $entry);
        }
        $name = rtrim(preg_replace('#/+#', '/', $name) ?? $name, '/');
        if ($name === '' || $name === '.') {
            throw new \RuntimeException('包内条目名为空');
        }
        foreach (explode('/', $name) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                throw new \RuntimeException('包内条目越界：' . $entry);
            }
        }

        return $name;
    }

    /**
     * 拼接落点并逐级拒绝符号链接：目标目录里预先存在的软链（哪怕是悬空的）
     * 都会把「目录内写入」变成「目录外写入」。
     */
    private static function dest(string $root, string $rel): string
    {
        $walk = $root;
        foreach (explode('/', $rel) as $seg) {
            $walk .= '/' . $seg;
            if (is_link($walk)) {
                throw new \RuntimeException('落点存在符号链接：' . $rel);
            }
        }

        return $root . '/' . $rel;
    }

    /**
     * @param array{0:int,1:int} $caps  [单条目上限, 总预算]
     * @param list<string> $written
     * @param list<string> $madeDirs
     */
    private static function extractZip(string $archive, string $root, ?callable $accept, bool $noOverwrite, array $caps, array &$written, array &$madeDirs): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new \RuntimeException('包打不开');
        }

        $budget = 0;
        $count = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new \RuntimeException('包索引读取失败');
                }
                $raw = (string) $stat['name'];
                $size = (int) ($stat['size'] ?? 0);

                if (str_ends_with($raw, '/')) {
                    continue; // 目录条目：内容恒空，需要时由文件写入顺手建
                }
                $rel = self::normalize($raw);

                $budget += $size;
                if ($size > $caps[0] || $budget > $caps[1]) {
                    throw new \RuntimeException('包解压超出体积预算（疑似压缩炸弹）');
                }
                if ($accept !== null && !$accept($rel)) {
                    continue;
                }

                $dest = self::dest($root, $rel);
                if ($noOverwrite && is_file($dest)) {
                    throw new \RuntimeException('包内条目与已有文件同名：' . $rel);
                }
                self::ensureDir(dirname($dest), $root, $madeDirs);
                // 流式搬运：getFromIndex 会把 50MB 条目一次性读进内存；
                // 按索引取流（getStreamIndex）也避开了重名条目「按名取流取错内容」的歧义。
                $stream = $zip->getStreamIndex($i);
                if ($stream === false) {
                    throw new \RuntimeException('包内条目读取失败：' . $rel);
                }
                $out = @fopen($dest, 'wb');
                if ($out === false) {
                    fclose($stream);

                    throw new \RuntimeException('落盘失败：' . $rel);
                }
                $copied = stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
                if ($copied === false || $copied !== $size) {
                    @unlink($dest);

                    throw new \RuntimeException('包内条目内容不完整：' . $rel);
                }
                $written[] = $dest;
                $count++;
            }
        } finally {
            $zip->close();
        }

        return $count;
    }

    /**
     * 流式读 tar：ustar 前缀、GNU 长名（L）、pax 扩展头（x/X）都按规范还原条目名。
     *
     * @param array{0:int,1:int} $caps  [单条目上限, 总预算]
     * @param list<string> $written
     * @param list<string> $madeDirs
     */
    private static function extractTar(string $archive, string $root, ?callable $accept, bool $noOverwrite, array $caps, array &$written, array &$madeDirs): int
    {
        $lower = strtolower($archive);
        $source = str_ends_with($lower, '.gz') || str_ends_with($lower, '.tgz')
            ? 'compress.zlib://' . $archive
            : $archive;
        $fh = @fopen($source, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('包打不开');
        }

        $budget = 0;
        $count = 0;
        $pendingName = null;
        try {
            while (true) {
                $header = self::readExact($fh, 512, true);
                if ($header === '' || trim($header, "\0") === '') {
                    break; // 结束块
                }

                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                // magic 在 257 起 6 字节：POSIX 是 "ustar\0"，GNU 是 "ustar "，
                // 只认其中一种的话另一类的长路径包会被当成「名字正好 100 字符」解错。
                if (str_starts_with(substr($header, 257, 6), 'ustar') && $prefix !== '') {
                    $name = $prefix . '/' . $name;
                }
                if (is_string($pendingName)) {
                    $name = $pendingName;
                    $pendingName = null;
                }

                $size = self::octal(substr($header, 124, 12));
                $type = substr($header, 156, 1);
                $payloadBlocks = (int) ceil($size / 512);

                // GNU 长名 / pax 扩展头：条目本身不落盘，只贡献下一条目的名字
                if ($type === 'L' || $type === 'K' || $type === 'x' || $type === 'X' || $type === 'g' || $type === 'G') {
                    $payload = $size > 0 ? self::readExact($fh, $size) : '';
                    self::skip($fh, $payloadBlocks * 512 - $size);
                    if ($type === 'L') {
                        $pendingName = rtrim($payload, "\0");
                    } elseif ($type === 'x' || $type === 'X') {
                        // pax 记录是 `<十进制长度> <key>=<值>`：长度前缀不剥掉，
                        // 「139 path=…」永远匹配不上 `path=`，条目会退回用被截断的
                        // 100 字节原名落盘——文件名错了，内容却完全正常。
                        if (preg_match('/(?:^|\n)[ \t]*\d+[ \t]+path=([^\n]*)/', $payload, $m) === 1) {
                            $pendingName = $m[1];
                        }
                    }
                    continue;
                }

                if ($type === '5') {
                    self::skip($fh, $payloadBlocks * 512);
                    continue;
                }
                if ($type !== '0' && $type !== "\0" && $type !== '') {
                    // 1 硬链 / 2 符号链 / 3 字符设备 / 4 块设备 / 6 FIFO / D 白话…
                    throw new \RuntimeException('包内含非普通文件条目：' . ($name === '' ? '?' : $name));
                }

                $rel = self::normalize($name);
                $budget += $size;
                if ($size > $caps[0] || $budget > $caps[1]) {
                    throw new \RuntimeException('包解压超出体积预算（疑似压缩炸弹）');
                }

                $dest = self::dest($root, $rel);
                $keep = $accept === null || $accept($rel);
                if ($keep) {
                    if ($noOverwrite && is_file($dest)) {
                        throw new \RuntimeException('包内条目与已有文件同名：' . $rel);
                    }
                    self::ensureDir(dirname($dest), $root, $madeDirs);
                    $out = @fopen($dest, 'wb');
                    if ($out === false) {
                        throw new \RuntimeException('落盘失败：' . $rel);
                    }
                } else {
                    $out = null;
                }

                if ($size > 0) {
                    self::pipe($fh, $out, $size);
                }
                self::skip($fh, $payloadBlocks * 512 - $size);
                if ($out !== null) {
                    fclose($out);
                    $written[] = $dest;
                    $count++;
                }
            }
        } finally {
            fclose($fh);
        }

        return $count;
    }

    /** 读满 $bytes；$tolerateEnd 为真时遇到 EOF 返回已读部分（首个头块才用）。 */
    private static function readExact($fh, int $bytes, bool $tolerateEnd = false): string
    {
        $buf = '';
        while (strlen($buf) < $bytes) {
            $chunk = fread($fh, $bytes - strlen($buf));
            if ($chunk === false || $chunk === '') {
                if ($tolerateEnd) {
                    return $buf;
                }
                throw new \RuntimeException('包内容不完整');
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    private static function skip($fh, int $bytes): void
    {
        while ($bytes > 0) {
            $chunk = fread($fh, min(65536, $bytes));
            if ($chunk === false || $chunk === '') {
                return;
            }
            $bytes -= strlen($chunk);
        }
    }

    /** 流式搬运条目内容（$out 为 null 时丢弃）。 */
    private static function pipe($fh, $out, int $size): void
    {
        while ($size > 0) {
            $chunk = fread($fh, min(8192, $size));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('包内容不完整');
            }
            if ($out !== null && fwrite($out, $chunk) === false) {
                throw new \RuntimeException('落盘失败');
            }
            $size -= strlen($chunk);
        }
    }

    /** tar 的长度字段：纯八进制（空格/NUL 填充）；base-256 大字段一律拒。 */
    private static function octal(string $field): int
    {
        $trim = trim($field, " \0");
        if ($trim === '') {
            return 0;
        }
        if (preg_match('/^[0-7]{1,11}$/', $trim) !== 1) {
            throw new \RuntimeException('包内条目长度字段非法');
        }

        return (int) octdec($trim);
    }

    /**
     * @param list<string> $madeDirs
     */
    private static function ensureDir(string $dir, string $root, array &$madeDirs): void
    {
        if (is_dir($dir)) {
            return;
        }
        $missing = [];
        $probe = $dir;
        while (strlen($probe) > strlen($root) && !is_dir($probe)) {
            $missing[] = $probe;
            $probe = dirname($probe);
        }
        if ($missing === []) {
            return;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('解包目录创建失败');
        }
        @chmod($dir, 0775);
        // 逐级记录：mkdir 的递归会连父目录一起建，回滚要按最深→最浅删干净，
        // 只记叶子的话，失败后会在 plugins/ 下留一串空目录。
        foreach (array_reverse($missing) as $made) {
            $madeDirs[] = $made;
        }
    }
}
