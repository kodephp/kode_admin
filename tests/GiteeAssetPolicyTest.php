<?php

declare(strict_types=1);

namespace app\tests;

use app\support\SafeArchive;
use Kode\Framework\Testing\TestCase;
use Kode\Plugins\frontend\GiteeReleaseController;

/**
 * GiteeRelease 安装链路上的五条纯策略闸门。
 *
 * 装的是「远端说了算」的字符串：release tag 当目录名、install_path 当落点、
 * 归档条目直接解进 web 根、repo 段拼进带 token 的 API 路径。所以每一条都必须能
 * 离线判真假——本文件不连网、不写 public/。
 *
 * 顺带钉住一个真实的接线缺陷：`SafeArchive::extract()` 第 3 参是 `?callable`，
 * 把控制器里的 private 静态方法当数组回调传出去会 TypeError（可见性按被调处判定），
 * 于是「每次安装必炸」。见 test_filter_callable_is_usable_by_safearchive。
 */
final class GiteeAssetPolicyTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/kode-gitee-policy-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
        parent::tearDown();
    }

    /* ==================== 静态资产白名单 ==================== */

    public function test_static_assets_pass_the_whitelist(): void
    {
        foreach ([
            'index.html', 'assets/app.js', 'assets/app.mjs', 'assets/style.css',
            'manifest.json', 'assets/app.js.map', 'robots.txt', 'favicon.ico',
            'img/a.png', 'img/b.jpeg', 'img/c.webp', 'img/d.avif', 'img/e.svg',
            'font/woff2.woff2', 'f.woff', 'g.ttf', 'h.otf', 'i.eot',
            'v.mp4', 'w.webm', 'x.wasm', 'y.xml', 'z.htm',
        ] as $rel) {
            self::assertTrue(GiteeReleaseController::assertStaticEntry($rel), '应当收：' . $rel);
        }
    }

    public function test_executable_and_hidden_entries_are_refused(): void
    {
        // 白名单的意义就在于此：黑名单挡不住「后端还认的别的后缀」
        foreach ([
            'shell.php', 'p.phtml', 'x.phar', 'y.php5', 'z.php7', 'a.pht',
            'index.shtml', 'cgi-bin/run.cgi', 'x.pl', 'x.py', 'x.rb', 'x.so',
            'x.dll', 'x.exe', 'x.sh', 'x.bash', 'x.asp', 'x.aspx', 'x.jsp',
            'sub/.env', '.htaccess', '.git/config', 'noext',
        ] as $rel) {
            try {
                GiteeReleaseController::assertStaticEntry($rel);
                self::fail('应当拒：' . $rel);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('条目', $e->getMessage());
            }
        }
    }

    public function test_svg_passes_but_xss_payload_is_still_a_plain_svg(): void
    {
        // svg 必须在白名单里（前端图标全靠它）；它的 XSS 口径属渲染侧，不在这条闸门
        self::assertTrue(GiteeReleaseController::assertStaticEntry('icon.svg'));
    }

    /* ==================== 回调接线（曾因 private 而 TypeError） ==================== */

    public function test_filter_callable_is_usable_by_safearchive(): void
    {
        $zipPath = $this->zip('ok.zip', [
            'index.html' => '<!doctype html><title>ok</title>',
            'assets/app.js' => 'console.log(1);',
        ]);
        $dest = $this->tmp . '/web';
        mkdir($dest, 0775, true);

        $count = SafeArchive::extract($zipPath, $dest, [GiteeReleaseController::class, 'assertStaticEntry'], false);

        self::assertSame(2, $count);
        self::assertFileExists($dest . '/index.html');
    }

    public function test_filter_callable_refuses_a_php_member(): void
    {
        $zipPath = $this->zip('bad.zip', [
            'index.html' => '<!doctype html>',
            'shell.php' => "<?php echo 'pwned';\n",
        ]);
        $dest = $this->tmp . '/web2';
        mkdir($dest, 0775, true);

        try {
            SafeArchive::extract($zipPath, $dest, [GiteeReleaseController::class, 'assertStaticEntry'], false);
            self::fail('含 .php 条目的包必须整包中止');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('静态资产之外', $e->getMessage());
        }
        // 中止即回滚：前一条 index.html 也不许留下
        self::assertFileDoesNotExist($dest . '/index.html');
        self::assertFileDoesNotExist($dest . '/shell.php');
    }

    /* ==================== 版本名（tag 可控） ==================== */

    public function test_version_names(): void
    {
        foreach (['1.2.3', 'v1.2.3', '1.2.3-rc.1', '2026.09.23', 'A1', str_repeat('x', 64)] as $ok) {
            self::assertTrue(GiteeReleaseController::safeVersion($ok), '应当收：' . $ok);
        }
        foreach ([
            '', '.', '.hidden', '../evil', '..', 'a/../../b', '/abs', 'v1.2.3/', 'v 1.2', 'v1?x=1',
            "v1\0.php", 'v1;rm -rf', '-v1', '_v1', str_repeat('x', 65), 'v1.2.3#frag', '%2e%2e',
        ] as $bad) {
            self::assertFalse(GiteeReleaseController::safeVersion($bad), '应当拒：' . $bad);
        }
    }

    /* ==================== repo（三段 gitee 请求里唯一由用户给的路径段） ==================== */

    public function test_repo_names(): void
    {
        foreach (['owner/repo', 'kode-php/kode_test', 'a/b.c-d_e', '9/x1'] as $ok) {
            self::assertTrue(GiteeReleaseController::safeRepo($ok), '应当收：' . $ok);
        }
        foreach ([
            // 老写法只挡形状：`owner/..` 能过正则，拼出 /api/v5/repos/owner/../releases，
            // 服务器归一后打到别的 API 路径上，而这一发带着用户的 PRIVATE-TOKEN
            '', 'owner', 'owner/', '/repo', 'owner/repo/extra', 'owner/..', 'a/../b', 'owner/...',
            'owner/re po', 'owner/re?x=1', 'owner/re#frag', 'owner//repo', "owner/re\0po",
            // 整条 URL 塞进来：`//host/` 或 `@` 换主机都不该过
            '//evil/x', 'owner/@evil', 'http://evil/a', 'a/b:80',
        ] as $bad) {
            self::assertFalse(GiteeReleaseController::safeRepo($bad), '应当拒：' . $bad);
        }
    }

    /* ==================== install_path（脏数据也能挡住读侧） ==================== */

    public function test_install_paths(): void
    {
        self::assertSame('assets/lowcode-shell', GiteeReleaseController::safeInstallPath('assets/lowcode-shell'));
        // 首尾斜杠归一、大小写保留（这个值同时是访问 URL 的一段）
        self::assertSame('assets/shell', GiteeReleaseController::safeInstallPath('/assets/shell/'));
        self::assertSame('assets/shell', GiteeReleaseController::safeInstallPath('assets/shell/'));
        self::assertSame('Assets/LowCode-Shell.v2', GiteeReleaseController::safeInstallPath('Assets/LowCode-Shell.v2'));
        // 绝对写法被剥成相对：落点恒在 public/ 之内，翻不出去
        self::assertSame('var/www/html', GiteeReleaseController::safeInstallPath('/var/www/html'));

        foreach ([
            '', '/', '//', '..', '../etc', 'assets/../../etc', 'assets/../shell',
            'C:/windows', 'assets/ shell', 'assets/a?b', 'assets/..',
            "assets/\0x", 'assets//shell', '.hidden/x', '-x', 'assets/sh*',
        ] as $bad) {
            self::assertNull(GiteeReleaseController::safeInstallPath($bad), '应当拒：' . $bad);
        }
    }

    /* ==================== 归档识别（不再有「单文件也装」） ==================== */

    public function test_archive_extension_recognition(): void
    {
        self::assertSame('.zip', GiteeReleaseController::archiveExtOf('app-v1.2.3.zip'));
        self::assertSame('.tar.gz', GiteeReleaseController::archiveExtOf('app.tar.gz'));
        self::assertSame('.tgz', GiteeReleaseController::archiveExtOf('app.TGZ'));
        self::assertSame('.tar', GiteeReleaseController::archiveExtOf('dist.tar'));
        // 裸文件不当「单文件」装：过去这条分支会把 .exe/未知字节直接放进 web 根
        foreach (['app.exe', 'app.bin', 'shell.php', 'README', '', 'app.zip.exe'] as $name) {
            self::assertNull(GiteeReleaseController::archiveExtOf($name), '不应视为归档：' . $name);
        }
    }

    /* ==================== 工具 ==================== */

    /** @param array<string, string> $entries */
    private function zip(string $name, array $entries): string
    {
        $path = $this->tmp . '/' . $name;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        foreach ($entries as $entry => $content) {
            self::assertTrue($zip->addFromString($entry, $content) !== false);
        }
        self::assertTrue($zip->close());

        return $path;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
