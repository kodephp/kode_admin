<?php

declare(strict_types=1);

namespace app\tests;

use app\support\PluginMarket;
use app\support\PluginPackager;
use app\tests\Support\LocalFetchServer;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;

/**
 * 插件远程安装（供应链）闸门。
 *
 * 修的三类缺陷都在「落盘」这一步的边界上：
 *  1. zip-slip：包内 `../evil.php` 曾由 `extractTo()` 原样解出，写到 plugins/ 之外；
 *  2. 半装即启用：逐条 `continue` 吞掉违规条目后返回「成功」，状态被翻成 enabled，
 *     装出一堆缺文件的插件；现在违规一律抛出 + 回滚；
 *  3. 校验顺序：sha256 / 清单（偷名、框架下限）必须在落盘之前判完，
 *     落盘之后再拒就回不去。
 *
 * 断言口径：`plugins/` 目录逐文件快照比对（拒绝时必须零变化），
 * 以及 `kode_plugins` 表在失败路径上不得出现该插件的行。
 */
final class PluginSupplyChainTest extends TestCase
{
    private ?LocalFetchServer $server = null;

    private string $docRoot = '';

    /** @var array<string, string|false> */
    private array $envBackup = [];

    /** @var array<string, int> plugins/ 快照（路径 => 大小+mtime） */
    private array $pluginTree = [];

    /** @var list<string> 本例造出来的插件名，tearDown 按名清理 */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        // bootApp 才有真实 DB 连接（纯 autoload 下 Db:: 连不上，「失败不得登记」这条就无从断言）
        $this->bootApp(dirname(__DIR__));
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'true');
        $this->pluginTree = $this->tree(PluginMarket::baseDir());
    }

    protected function tearDown(): void
    {
        // 只清本轮造出来的包：按唯一插件名删，绝不动别人的插件文件
        foreach ($this->made as $name) {
            $this->removePackage($name);
        }
        $this->made = [];
        if ($this->server !== null) {
            $this->server->stop();
            $this->server = null;
        }
        if ($this->docRoot !== '') {
            $this->removeTree($this->docRoot);
            $this->docRoot = '';
        }
        foreach ($this->envBackup as $key => $value) {
            $this->putEnv($key, $value);
        }
        $this->envBackup = [];
        // 必须回父类：基类 tearDown 负责清 `self::$app` 缓存。漏掉它会让后一个
        // independentApp 测试类拿到「核心单例已被它自己重置、缓存却还留着」的陈应用，
        // 于是它的 resolve() 直接抛「服务容器尚未启动」（全量跑时才暴露，单跑永远绿）。
        parent::tearDown();
    }

    /* ==================== packageEntry()：纯策略 ==================== */

    public function test_package_entry_policy_table(): void
    {
        // 收：根级 *Plugin.php 与 <name>/ 下的 .php
        self::assertTrue(PluginPackager::packageEntry('FooPlugin.php', 'foo'));
        self::assertTrue(PluginPackager::packageEntry('foo/Sub.php', 'foo'));
        self::assertTrue(PluginPackager::packageEntry('foo/a/b/C.php', 'foo'));
        // 跳：清单只用于校验，不入库
        self::assertFalse(PluginPackager::packageEntry('plugin.json', 'foo'));

        foreach ([
            // 规则有序：先判扩展名，再判点开头。`.env` 落在「非 .php」这一档也是拒，
            // 想看到「隐藏」这条理由得用点开头的 .php（`.env.php`、`.htaccess.php`）
            'notes.txt' => '非 .php',
            'foo/.env' => '非 .php',
            'foo/sh.so' => '非 .php',
            '.hidden.php' => '隐藏',
            'foo/.env.php' => '隐藏',
            'other/x.php' => '位置非法',
            'bar/x.php' => '位置非法',
            // 点开头这条顺带把 `..` 段也拦了；SafeArchive::normalize 再独立判一次越界（两层不共享实现）
            'x/y/../z.php' => '隐藏',
            '../outside.php' => '隐藏',
        ] as $rel => $why) {
            try {
                PluginPackager::packageEntry($rel, 'foo');
                self::fail('应当拒绝 ' . $rel . '（' . $why . '）');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($why, $e->getMessage(), $rel . ' 的拒绝原因不对');
            }
        }
    }

    public function test_package_entry_refuses_php_lookalike_extensions(): void
    {
        // 白名单口径：只有 .php 能落地，`.phtml`/`.phar` 这类「解析器还会执行的尾巴」直接拒
        foreach (['Foo.phtml', 'Foo.phar', 'foo/x.php5', 'foo/sh.php.jpg'] as $rel) {
            $this->throwsWith('非 .php', fn () => PluginPackager::packageEntry($rel, 'foo'));
        }
    }

    /* ==================== installRemote()：真下载、真包 ==================== */

    public function test_valid_package_installs_and_cleans_up(): void
    {
        if (!$this->hasDb()) {
            self::markTestSkipped('DB 不可用，跳过安装成功路径');
        }
        $name = $this->uniqueName();
        $zip = $this->package($name, [
            $this->mainClass($name) => "<?php\nclass X {}\n",
            $name . '/Sub.php' => "<?php\nclass Sub {}\n",
        ]);

        $r = PluginPackager::installRemote($this->serve($zip), hash_file('sha256', $zip), $name);

        self::assertTrue($r['ok'], (string) ($r['msg'] ?? ''));
        self::assertSame(2, $r['files']);
        self::assertSame('1.2.3', $r['version']);
        $base = PluginMarket::baseDir();
        self::assertFileExists($base . '/' . ucfirst($name) . 'Plugin.php');
        self::assertFileExists($base . '/' . $name . '/Sub.php');
        self::assertFileDoesNotExist($base . '/plugin.json', '清单不该入库');
        self::assertSame('enabled', PluginMarket::statusOf($name));

        $this->removePackage($name);
    }

    public function test_sha256_mismatch_refuses_before_landing(): void
    {
        $name = $this->uniqueName();
        $zip = $this->package($name, [$this->mainClass($name) => "<?php\nclass X {}\n"]);

        $r = PluginPackager::installRemote($this->serve($zip), str_repeat('0', 64), $name);

        $this->assertRefused($r, $name, '校验失败');
    }

    public function test_manifest_name_spoofing_refuses_before_landing(): void
    {
        // 「请求 zz_x、包里写 zz_y」：偷名会让登记与实体错位（装的是 A，启用的是 B）
        $name = $this->uniqueName();
        $zip = $this->package($name, [$this->mainClass($name) => "<?php\nclass X {}\n"], ['name' => 'somebody_else']);

        $r = PluginPackager::installRemote($this->serve($zip), $this->sha($zip), $name);

        $this->assertRefused($r, $name, '偷名');
    }

    public function test_missing_manifest_refuses(): void
    {
        $name = $this->uniqueName();
        $zip = $this->package($name, [$this->mainClass($name) => "<?php\nclass X {}\n"], withManifest: false);

        $r = PluginPackager::installRemote($this->serve($zip), $this->sha($zip), $name);

        $this->assertRefused($r, $name, 'plugin.json');
    }

    public function test_incompatible_framework_constraint_refuses(): void
    {
        // 包里声明 `>=99.0` 却装到 1.x 上：声明了兼容性就必须真的校验
        $name = $this->uniqueName();
        $zip = $this->package($name, [$this->mainClass($name) => "<?php\nclass X {}\n"], ['kode' => '>=99.0']);

        $r = PluginPackager::installRemote($this->serve($zip), $this->sha($zip), $name);

        $this->assertRefused($r, $name, '不兼容');
    }

    public function test_zip_slip_refuses_and_lands_nothing(): void
    {
        $name = $this->uniqueName();
        $root = dirname(PluginMarket::baseDir());
        $outside = $root . '/' . $name . '_escaped.php';
        @unlink($outside);

        $zip = $this->package($name, [
            $this->mainClass($name) => "<?php\nclass X {}\n",
            '../' . $name . '_escaped.php' => "<?php echo 'pwned';\n",
        ]);

        $r = PluginPackager::installRemote($this->serve($zip), $this->sha($zip), $name);

        // 过去这条是「成功」：违规条目被 continue 吞掉，主类照样落盘、状态翻成 enabled
        $this->assertRefused($r, $name, '越界');
        self::assertFileDoesNotExist($outside, '包外路径绝不许被写出');
        self::assertFileDoesNotExist(PluginMarket::baseDir() . '/' . $this->mainClass($name));
    }

    public function test_non_php_member_refuses_instead_of_being_skipped(): void
    {
        // 「只收 .php」不该等于「别的默默扔掉然后报成功」——那正是半装即启用
        $name = $this->uniqueName();
        $zip = $this->package($name, [
            $this->mainClass($name) => "<?php\nclass X {}\n",
            $name . '/README.txt' => 'hi',
        ]);

        $r = PluginPackager::installRemote($this->serve($zip), $this->sha($zip), $name);

        $this->assertRefused($r, $name, '非 .php');
    }

    public function test_hidden_member_refuses(): void
    {
        $name = $this->uniqueName();
        $zip = $this->package($name, [
            $this->mainClass($name) => "<?php\nclass X {}\n",
            $name . '/.config.php' => "<?php return ['secret'];\n",
        ]);

        $r = PluginPackager::installRemote($this->serve($zip), $this->sha($zip), $name);

        $this->assertRefused($r, $name, '隐藏');
        self::assertFileDoesNotExist(PluginMarket::baseDir() . '/' . $name . '/.config.php');
    }

    public function test_package_requesting_a_hostile_main_class_still_lands_inside_plugins(): void
    {
        // 主类名由包自定（`Zz<rand>Plugin.php`），但落点必须在 plugins/ 内、且不得越界
        $name = $this->uniqueName();
        $zip = $this->package($name, ['../../../../tmp/evil' . $name . '.php' => "<?php echo 1;\n"]);

        $r = PluginPackager::installRemote($this->serve($zip), $this->sha($zip), $name);

        $this->assertRefused($r, $name, '越界');
        self::assertFileDoesNotExist('/tmp/evil' . $name . '.php');
    }

    public function test_rejects_illegal_name_and_sha_before_touching_the_network(): void
    {
        $r = PluginPackager::installRemote('http://127.0.0.1:9/x.zip', str_repeat('a', 64), 'Bad-Name');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('插件名非法', (string) $r['msg']);

        $r = PluginPackager::installRemote('http://127.0.0.1:9/x.zip', 'deadbeef', 'badname');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('sha256 非法', (string) $r['msg']);

        // 地址不过闸就不该发起连接（内网默认拒）
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'false');
        $r = PluginPackager::installRemote('http://169.254.169.254/latest/meta-data/', str_repeat('a', 64), 'badname');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('内网', (string) $r['msg']);
    }

    public function test_manifest_only_package_is_not_reported_as_installed(): void
    {
        // 包里只有清单：零文件也敢报「安装成功」，就是把空壳翻成 enabled
        $name = $this->uniqueName();
        $zip = $this->package($name, []);

        $r = PluginPackager::installRemote($this->serve($zip), $this->sha($zip), $name);

        $this->assertRefused($r, $name, '无有效文件');
        self::assertNotSame('enabled', PluginMarket::statusOf($name), '空壳包不得被翻成启用');
    }

    public function test_existing_files_are_not_clobbered(): void
    {
        // 已装过同名插件时不得被远程包偷梁换柱（升级走 upgrade 流程）
        $name = $this->uniqueName();
        $base = PluginMarket::baseDir();
        $main = $base . '/' . $this->mainClass($name);
        $dir = dirname($main);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($main, "<?php /* mine */\n");

        try {
            $zip = $this->package($name, [$this->mainClass($name) => "<?php echo 'replaced';\n"]);
            $r = PluginPackager::installRemote($this->serve($zip), $this->sha($zip), $name);

            self::assertFalse($r['ok'], '覆盖已有文件必须拒绝');
            self::assertSame("<?php /* mine */\n", file_get_contents($main));
        } finally {
            @unlink($main);
            @rmdir($dir);
        }
    }

    /* ==================== 工具 ==================== */

    /** 失败路径的统一断言：原因对、plugins/ 零变化、表里没行。 */
    private function assertRefused(array $r, string $name, string $why): void
    {
        self::assertFalse($r['ok'], '应当拒绝：' . $why);
        self::assertStringContainsString($why, (string) $r['msg'], '拒绝原因不对：' . (string) ($r['msg'] ?? ''));
        self::assertSame(
            $this->pluginTree,
            $this->tree(PluginMarket::baseDir()),
            '拒绝路径不得改动 plugins/ 一个字节（回滚没生效？）'
        );
        if ($this->hasDb()) {
            self::assertCount(
                0,
                Db::select('SELECT name FROM kode_plugins WHERE name = ?', [$name]),
                '安装失败却登记了状态——就是「半装即启用」'
            );
        }
    }

    private function throwsWith(string $why, callable $fn): void
    {
        try {
            $fn();
            self::fail('应当抛出：' . $why);
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($why, $e->getMessage());
        }
    }

    private function uniqueName(): string
    {
        $name = 'zz' . substr(md5(uniqid('', true)), 0, 8);
        $this->made[] = $name;

        return $name;
    }

    private function mainClass(string $name): string
    {
        return ucfirst($name) . 'Plugin.php';
    }

    private function sha(string $file): string
    {
        return (string) hash_file('sha256', $file);
    }

    /**
     * 造一个市场包（zip）：条目由调用方给，清单默认自述一致。
     *
     * @param array<string, string>  $entries  包内路径 => 内容
     * @param array<string, mixed>   $manifest 清单覆盖项
     */
    private function package(string $name, array $entries, array $manifest = [], bool $withManifest = true): string
    {
        $path = $this->server()->put($name . '.zip', '');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        foreach ($entries as $rel => $content) {
            $zip->addFromString($rel, $content);
        }
        if ($withManifest) {
            $zip->addFromString('plugin.json', (string) json_encode(array_merge([
                'name' => $name,
                'version' => '1.2.3',
                'kode' => '>=1.0',
            ], $manifest), JSON_UNESCAPED_UNICODE));
        }
        $zip->close();
        clearstatcache();

        return $path;
    }

    private function serve(string $path): string
    {
        return $this->server()->url('/' . basename($path));
    }

    private function server(): LocalFetchServer
    {
        if ($this->server === null) {
            $root = sys_get_temp_dir() . '/kode-supply-root-' . bin2hex(random_bytes(6));
            mkdir($root, 0775, true);
            $candidate = new LocalFetchServer($root);
            try {
                $candidate->start();
                $this->server = $candidate;
                $this->docRoot = $root;
            } catch (\Throwable $e) {
                $candidate->stop();
                $this->removeTree($root);
                self::markTestSkipped('本地 php -S 起不来：' . $e->getMessage());
            }
        }

        return $this->server;
    }

    /** @return array<string, int> 目录树快照（路径 => 大小+mtime，够判「动过没有」） */
    private function tree(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $out[$file->getPathname()] = $file->isDir() ? 0 : (int) ($file->getSize() + $file->getMTime());
        }
        ksort($out);

        return $out;
    }

    private function removePackage(string $name): void
    {
        $base = PluginMarket::baseDir();
        @unlink($base . '/' . $this->mainClass($name));
        $this->removeTree($base . '/' . $name);
        try {
            PluginMarket::forgetStatusCache($name);
            PluginMarket::flush();
        } catch (\Throwable) {
        }
        if ($this->hasDb()) {
            try {
                // 两张表一起清：install / 远程安装的成功路径会经 PluginVersionHistory::changeVersion()
                // 往 kode_plugin_versions 写账本行，而本类此前只删 kode_plugins ——
                // 于是「插件已卸、账本还记着它的版本历史」，实测开发库堆了 62 条这种 zz* 僵尸行。
                // rollback()/previousVersion() 全建在这张表上，僵尸行会让它们算出「从没装过的插件」的候选。
                Db::update('DELETE FROM kode_plugin_versions WHERE name = ?', [$name]);
                Db::update('DELETE FROM kode_plugins WHERE name = ?', [$name]);
            } catch (\Throwable) {
            }
        }
    }

    private function hasDb(): bool
    {
        try {
            return Db::select('SELECT 1 AS ok') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->envBackup)) {
            $this->envBackup[$key] = getenv($key);
        }
        $this->putEnv($key, $value);
    }

    private function putEnv(string $key, string|false $value): void
    {
        if ($value === false) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
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
