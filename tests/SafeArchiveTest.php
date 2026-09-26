<?php

declare(strict_types=1);

namespace app\tests;

use app\support\SafeArchive;
use Kode\Framework\Testing\TestCase;

/**
 * 解包闸门：样本包一律由**真实工具**产出（bsdtar / libzip），不是手搓字节。
 *
 * 为什么这么较真：`PharData` 对真实 tar 恒迭代 0 条目，而手搓样本恰好也会「看起来没问题」——
 * 只有自己造的样本才会配合自己的解析器撒谎。唯一必须手搓的 GNU 长名包，
 * 先用系统 `tar -tf` 验它确实是那份 GNU 包，再喂给被测代码（见 test_gnu_long_name）。
 *
 * 覆盖的问题类别：
 *  - zip-slip / tar-slip（`../` 条目写到目标目录外）；
 *  - 符号链接、硬链接、FIFO 条目（落进 web 根后任何「顺着路径写」都会出圈）；
 *  - 半装即成功（违规条目被 `continue` 跳过，调用方仍收到 count>0）→ 现在整包抛错 + 回滚；
 *  - 长文件名（pax `path=` / ustar prefix / GNU `L`）被截断成另一个文件；
 *  - 压缩炸弹（按声明体积拦，不等解出来）。
 */
final class SafeArchiveTest extends TestCase
{
    private string $tmp;

    private string $stage;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/kode-safe-' . bin2hex(random_bytes(6));
        $this->stage = $this->tmp . '/stage';
        $this->out = $this->tmp . '/out';
        mkdir($this->stage, 0775, true);
        mkdir($this->out, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
        parent::tearDown();
    }

    /* ==================== 真实 tar 包 ==================== */

    public function test_real_targz_tree_extracts_with_contents(): void
    {
        $this->write($this->stage . '/app/index.html', "<html>ok</html>\n");
        $this->write($this->stage . '/app/js/main.js', "console.log(1)\n");
        $archive = $this->tar('good.tar.gz', ['app']);

        $count = SafeArchive::extract($archive, $this->out);

        self::assertSame(2, $count);
        self::assertSame("<html>ok</html>\n", file_get_contents($this->out . '/app/index.html'));
        self::assertSame("console.log(1)\n", file_get_contents($this->out . '/app/js/main.js'));
    }

    public function test_traversal_member_in_real_tar_is_refused(): void
    {
        // bsdtar 会把 `../x` 原样存进包里（`tar -cf a.tar -C stage ../x` 实测），
        // 所以这条攻击不需要「特制包」，任何构建脚本手滑都能产出。
        $this->write($this->tmp . '/outside.txt', "secret\n");
        $archive = $this->tar('trav.tar.gz', ['../outside.txt']);

        $this->expectExceptionMessage('越界');
        try {
            SafeArchive::extract($archive, $this->out);
        } finally {
            self::assertSame('secret', trim((string) file_get_contents($this->tmp . '/outside.txt')), '目标目录外不得被改写');
        }
    }

    public function test_violating_entry_rolls_back_files_already_written(): void
    {
        // 「半装」是这条链路上最贵的问题：前几条正常落盘、后面才越界，
        // 调用方拿到 count=1 就会把插件登记成已启用。
        $this->write($this->stage . '/keep.txt', "first\n");
        $this->write($this->tmp . '/outside.txt', "secret\n");
        $archive = $this->tar('mixed.tar.gz', ['keep.txt', '../outside.txt']);

        try {
            SafeArchive::extract($archive, $this->out);
            self::fail('越界条目必须抛错');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('越界', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->out . '/keep.txt', '本次已落盘的文件必须回滚');
        self::assertSame([], array_values(array_filter(scandir($this->out) ?: [], static fn ($f) => $f[0] !== '.')), '目标目录该回到解包前的样子');
    }

    public function test_symlink_member_in_real_tar_is_refused(): void
    {
        $this->write($this->stage . '/a.txt', "hi\n");
        self::assertTrue(@symlink('/etc/passwd', $this->stage . '/link'), '本机不支持符号链接');
        $archive = $this->tar('sym.tar.gz', ['a.txt', 'link']);

        $this->expectExceptionMessage('非普通文件条目');
        SafeArchive::extract($archive, $this->out);
    }

    public function test_hardlink_member_in_real_tar_is_refused(): void
    {
        $this->write($this->stage . '/a.txt', "hi\n");
        self::assertTrue(@link($this->stage . '/a.txt', $this->stage . '/b.txt'), '本机不支持硬链接');
        $archive = $this->tar('hard.tar.gz', ['a.txt', 'b.txt']);

        $this->expectExceptionMessage('非普通文件条目');
        SafeArchive::extract($archive, $this->out);
    }

    public function test_fifo_member_is_refused(): void
    {
        exec('mkfifo ' . escapeshellarg($this->stage . '/pipe1') . ' 2>/dev/null', $out, $code);
        if ($code !== 0 || !file_exists($this->stage . '/pipe1')) {
            self::markTestSkipped('本机不支持 mkfifo');
        }
        $archive = $this->tar('fifo.tar', ['pipe1']);

        $this->expectExceptionMessage('非普通文件条目');
        SafeArchive::extract($archive, $this->out);
    }

    public function test_pax_long_name_survives_without_truncation(): void
    {
        // 130 字符名 > tar 头部的 100 字节字段：bsdtar 默认用 pax `path=` 扩展头。
        // 解析器不认扩展头时不会报错，只会解出一个「被截断的名字」——
        // 内容看着完全正常，路径却对不上，是最难发现的那类坏法。
        $long = str_repeat('x', 130) . '.css';
        $this->write($this->stage . '/' . $long, "a{}\n");
        $archive = $this->tar('pax.tar.gz', [$long]);

        self::assertSame(1, SafeArchive::extract($archive, $this->out));
        self::assertFileExists($this->out . '/' . $long);
        self::assertSame('a{}', trim((string) file_get_contents($this->out . '/' . $long)));
    }

    public function test_ustar_prefix_long_path_extracts_under_the_right_directory(): void
    {
        // ustar 用 name(100)+prefix(155) 拼长路径：只读 name 字段会把父目录整个丢掉，
        // 文件被解到目标根上，名字还只剩尾巴。`--format ustar` 强制走这条编码。
        $dir = str_repeat('d', 100);
        $this->write($this->stage . '/' . $dir . '/tail.txt', "tail\n");
        $archive = $this->tar('ustar_prefix.tar', [$dir], null, ['--format', 'ustar']);

        // 自证样本：pax/GNU 长名会把完整路径原样留在包里，ustar 则是 prefix+name 两段拼接。
        // 断言「完整路径不连续出现」才说明这条测试真的走在 prefix 分支上。
        $raw = (string) file_get_contents($archive);
        self::assertStringNotContainsString($dir . '/tail.txt', $raw, '样本没走 ustar prefix 编码，测不到目标分支');
        self::assertStringContainsString($dir, $raw);

        self::assertSame(1, SafeArchive::extract($archive, $this->out));
        self::assertFileExists($this->out . '/' . $dir . '/tail.txt');
    }

    public function test_gnu_long_name_extension_header_is_understood(): void
    {
        $long = str_repeat('g', 120) . '/' . str_repeat('L', 90) . '.js';
        $tar = $this->tmp . '/gnu_long.tar';
        file_put_contents($tar, $this->gnuLongTar($long, "// ok\n"));

        // 先自证样本：系统 tar 必须把这包读成同一个长名，否则测的是我的字节功底
        exec('tar -tf ' . escapeshellarg($tar) . ' 2>/dev/null', $lines, $code);
        self::assertSame(0, $code, '样本包本身非法');
        self::assertContains($long, $lines, '样本包必须真是 GNU 长名格式：' . implode('|', $lines));

        self::assertSame(1, SafeArchive::extract($tar, $this->out));
        self::assertFileExists($this->out . '/' . $long);
        self::assertSame("// ok\n", file_get_contents($this->out . '/' . $long));
    }

    /* ==================== zip 包（libzip 产出） ==================== */

    public function test_zip_tree_extracts_with_contents(): void
    {
        $zip = $this->zip('good.zip', [
            'index.html' => '<html>ok</html>',
            'assets/main.js' => 'console.log(1)',
            'assets/deep/x.css' => 'a{}',
        ]);

        self::assertSame(3, SafeArchive::extract($zip, $this->out));
        self::assertSame('console.log(1)', file_get_contents($this->out . '/assets/main.js'));
    }

    public function test_zip_slip_entry_is_refused_and_rolled_back(): void
    {
        $zip = $this->zip('slip.zip', [
            'good.txt' => "first\n",
            '../../evil.txt' => "pwn\n",
        ]);

        try {
            SafeArchive::extract($zip, $this->out);
            self::fail('zip-slip 条目必须抛错');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('越界', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->out . '/good.txt');
        self::assertFileDoesNotExist($this->tmp . '/evil.txt');
        self::assertFileDoesNotExist(dirname($this->tmp) . '/evil.txt');
    }

    public function test_absolute_entry_stays_inside_the_target(): void
    {
        $zip = $this->zip('abs.zip', ['/abs_escape.txt' => 'pwn']);

        self::assertSame(1, SafeArchive::extract($zip, $this->out));
        self::assertFileExists($this->out . '/abs_escape.txt');
        self::assertFileDoesNotExist('/abs_escape.txt');
    }

    public function test_backslash_entry_is_normalised_not_escaped(): void
    {
        // Windows 打的包用反斜杠：不能因为「不含 /」就放过，也不能原样当文件名
        $zip = $this->zip('win.zip', ['sub\\..\\..\\evil.txt' => 'pwn']);

        $this->expectExceptionMessage('越界');
        SafeArchive::extract($zip, $this->out);
    }

    public function test_entry_colliding_with_existing_file_is_refused_by_default(): void
    {
        // 市场包的「不覆盖」是防偷梁换柱：升级走 upgrade 流程，不许装到一半改兄弟文件
        $this->write($this->out . '/keep.php', "<?php // 现场已有\n");
        $zip = $this->zip('dup.zip', ['keep.php' => "<?php echo 'trojan';"]);

        $this->expectExceptionMessage('同名');
        SafeArchive::extract($zip, $this->out);
        self::assertStringContainsString('现场已有', (string) file_get_contents($this->out . '/keep.php'));
    }

    public function test_overwrite_is_allowed_when_the_caller_asks_for_it(): void
    {
        // GiteeRelease 那一侧同版本重装是幂等修复，目录本就归这个版本
        $this->write($this->out . '/index.html', "old\n");
        $zip = $this->zip('rw.zip', ['index.html' => 'new']);

        self::assertSame(1, SafeArchive::extract($zip, $this->out, null, false));
        self::assertSame('new', trim((string) file_get_contents($this->out . '/index.html')));
    }

    public function test_existing_symlink_in_the_landing_path_is_not_followed(): void
    {
        // 目标目录里预先存在软链（上一次安装留下的、或别人放的）时，
        // 「拼接后还在目录内」并不等于「写在目录内」
        $elsewhere = $this->tmp . '/elsewhere';
        mkdir($elsewhere, 0775, true);
        self::assertTrue(@symlink($elsewhere, $this->out . '/logs'), '本机不支持符号链接');
        $zip = $this->zip('through_link.zip', ['logs/x.txt' => 'pwn']);

        $this->expectExceptionMessage('符号链接');
        try {
            SafeArchive::extract($zip, $this->out);
        } finally {
            self::assertFileDoesNotExist($elsewhere . '/x.txt');
        }
    }

    /* ==================== 体积预算 ==================== */

    public function test_zip_bomb_is_judged_on_declared_size_before_inflating(): void
    {
        // 1MB 全零压进 zip 只有几 KB；只按压缩后大小判就等于允许对方灌满磁盘
        $zip = $this->zip('bomb.zip', ['big.bin' => str_repeat("\0", 1024 * 1024)]);
        self::assertLessThan(1024 * 1024, (int) filesize($zip), '样本应当是「小压缩包、大解压体」');

        $this->expectExceptionMessage('预算');
        SafeArchive::extract($zip, $this->out, null, true, 4096);
    }

    public function test_tar_bomb_is_judged_on_header_size(): void
    {
        $this->write($this->stage . '/big.bin', str_repeat("\0", 64 * 1024));
        $archive = $this->tar('big.tar.gz', ['big.bin']);

        $this->expectExceptionMessage('预算');
        SafeArchive::extract($archive, $this->out, null, true, 4096);
    }

    public function test_total_budget_spans_entries_not_just_one(): void
    {
        $zip = $this->zip('many.zip', ['a.txt' => str_repeat('a', 900), 'b.txt' => str_repeat('b', 900)]);

        $this->expectExceptionMessage('预算');
        SafeArchive::extract($zip, $this->out, null, true, 1000, 1500);
    }

    /* ==================== 过滤器与入口条件 ==================== */

    public function test_accept_returning_false_skips_quietly_while_violation_throws(): void
    {
        // 「收不收」是业务判断（市场包只收 .php），「越不越界」是安全问题。
        // 两者混进同一次 continue 就是当初的静默半装。
        $zip = $this->zip('mixed2.zip', ['a.php' => "<?php\n", 'notes.txt' => 'hi', 'sub/b.php' => "<?php\n"]);
        $accept = static fn (string $rel): bool => str_ends_with($rel, '.php');

        self::assertSame(2, SafeArchive::extract($zip, $this->out, $accept));
        self::assertFileExists($this->out . '/sub/b.php');
        self::assertFileDoesNotExist($this->out . '/notes.txt');
    }

    public function test_unsupported_format_is_refused(): void
    {
        $path = $this->tmp . '/mystery.7z';
        $this->write($path, 'not a zip');

        $this->expectExceptionMessage('不支持的包格式');
        SafeArchive::extract($path, $this->out);
    }

    public function test_missing_target_directory_is_refused(): void
    {
        $zip = $this->zip('x.zip', ['a.txt' => 'a']);

        $this->expectExceptionMessage('解包目标目录不存在');
        SafeArchive::extract($zip, $this->tmp . '/nope');
    }

    public function test_corrupt_archive_is_refused_not_reported_as_empty_success(): void
    {
        $path = $this->tmp . '/broken.zip';
        $this->write($path, "PK\x03\x04 this is not a real archive");

        $this->expectExceptionMessage('包打不开');
        SafeArchive::extract($path, $this->out);
    }

    /* ==================== normalize() ==================== */

    public function test_normalize_rejects_every_way_out(): void
    {
        foreach ([
            '../a.txt',
            'a/../../b.txt',
            './a/../b',
            '',
            '   ',
            '/' . str_repeat('x', 600),
            "a\0b.php",
            'C:/Windows/a.txt',
        ] as $entry) {
            try {
                SafeArchive::normalize($entry);
                self::fail('应当拒绝条目：' . str_replace("\0", '\0', $entry));
            } catch (\RuntimeException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_normalize_accepts_ordinary_and_windows_paths(): void
    {
        self::assertSame('a/b.php', SafeArchive::normalize('a/b.php'));
        self::assertSame('a/b.php', SafeArchive::normalize('a\\b.php'));
        self::assertSame('a/b.php', SafeArchive::normalize('/a/b.php'));
        self::assertSame('a/b.php', SafeArchive::normalize('a//b.php'));
        self::assertSame('a/b.php', SafeArchive::normalize('a/b.php/'));
        self::assertSame('.hidden', SafeArchive::normalize('.hidden'));
    }

    /* ==================== 工具 ==================== */

    private function write(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * 用系统 tar（bsdtar）造真实样本包；$paths 原样交给 tar，因此 `../x` 会按
     * 真实工具的行为存成越界条目，而不是被测试代码预先「洗」掉。
     *
    /**
     * @param list<string> $paths
     * @param list<string> $options
     */
    private function tar(string $archiveName, array $paths, ?string $chdir = null, array $options = []): string
    {
        $target = $this->tmp . '/' . $archiveName;
        $gz = str_ends_with($archiveName, '.gz') || str_ends_with($archiveName, '.tgz') ? 'z' : '';
        $cmd = 'tar -c' . $gz . 'f ' . escapeshellarg($target)
            . ' ' . implode(' ', array_map('escapeshellarg', $options))
            . ' -C ' . escapeshellarg($chdir ?? $this->stage)
            . ' ' . implode(' ', array_map('escapeshellarg', $paths)) . ' 2>/dev/null';
        exec($cmd, $out, $code);
        if ($code !== 0 || !is_file($target)) {
            self::markTestSkipped('需要可用的 tar 命令来造样本包');
        }

        return $target;
    }

    /**
     * @param array<string, string> $entries
     */
    private function zip(string $name, array $entries): string
    {
        $path = $this->tmp . '/' . $name;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        foreach ($entries as $entry => $content) {
            self::assertTrue($zip->addFromString($entry, $content) !== false);
        }
        self::assertTrue($zip->close());
        self::assertFileExists($path);

        return $path;
    }

    /**
     * 手搓一份 GNU 长名 tar（本机没有 gtar，bsdtar 默认走 pax，`L` 分支不会被覆盖）。
     * 合法性由系统 `tar -tf` 在断言里独立确认，不靠自说自话。
     */
    private function gnuLongTar(string $longName, string $content): string
    {
        // GNU 签名：magic 位写 "ustar  \0"（POSIX 是 "ustar\0"+"00"），
        // 长名放在名为 ./././@LongLink 的 L 条目里，随后条目的 name 只是前 100 字符的占位。
        $gnu = "ustar  \0";
        $nameLen = strlen($longName) + 1;
        $blocks = $this->tarHeader('./././@LongLink', $nameLen, 'L', $gnu)
            . str_pad($longName . "\0", (int) (ceil($nameLen / 512) * 512), "\0");
        $blocks .= $this->tarHeader(substr($longName, 0, 99), strlen($content), '0', $gnu)
            . str_pad($content, (int) (ceil(strlen($content) / 512) * 512), "\0");

        return $blocks . str_repeat("\0", 1024);
    }

    private function tarHeader(string $name, int $size, string $typeflag, string $magic): string
    {
        $h = str_repeat("\0", 512);
        $put = static function (int $off, string $val) use (&$h): void {
            $h = substr_replace($h, $val, $off, strlen($val));
        };

        $put(0, substr($name, 0, 100));
        $put(100, "0000644\0 0000000\0 0000000\0 ");            // mode / uid / gid
        $put(124, str_pad(decoct($size), 11, '0', STR_PAD_LEFT) . ' ');
        $put(136, str_pad(decoct(1000000), 11, '0', STR_PAD_LEFT) . ' ');
        $put(148, '        ');                                   // chksum 先按空格求和
        $put(156, $typeflag);
        $put(257, $magic);
        $put(265, 'root');
        $put(297, 'wheel');

        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($h[$i]);
        }
        $put(148, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ");

        return $h;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_link($child)) {
                @unlink($child);
            } elseif (is_dir($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
