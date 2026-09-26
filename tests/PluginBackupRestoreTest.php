<?php

declare(strict_types=1);

namespace app\tests;

use app\support\PluginBackupService;
use app\support\PluginFileExplorer;
use app\support\PluginMarket;
use Kode\Database\Db\Db;
use Kode\Framework\Plugin\PluginDiscoverer;
use Kode\Framework\Testing\TestCase;

/**
 * 插件备份 / 恢复的落盘闸门。
 *
 * 老实现在「落盘」这一步上同时撒了三个谎：
 *  1. `write()` 要求目标文件**已存在**（编辑器语义），恢复走的也是它 ——
 *     被删掉的文件永远写不回去，恢复却照报「1 个成功」；
 *  2. `import()` 把 `write()` 的返回值丢在一边（注释写着「写入失败不阻断」），
 *     于是「文件一个没落 + 状态翻成 enabled」= 下次启动直接 fatal；
 *  3. 归属只靠 `realpath()` 折 `../` 后的越界判定，name=A 的一份 JSON 能写到 B 的文件上。
 *
 * 现在的口径：恢复走 `allowCreate`（只能在插件自己的目录内建），所有条目**先判后写**，
 * 有一条不落盘就整个插件算失败、且不动它的配置与状态；导出把读不动的条目点名报出。
 *
 * 断言口径：临时插件根目录（`PluginDiscoverer::useBaseDir()`）逐文件快照 +
 * `kode_plugins` 表在失败路径上不得出现该插件的行。
 */
final class PluginBackupRestoreTest extends TestCase
{
    private string $base = '';

    private string $outside = '';

    private string $plugin = '';

    private string $mainFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        // bootApp 才有真实 DB 连接（「失败不得翻状态」这条要读 kode_plugins 才能断言）
        $this->bootApp(dirname(__DIR__));

        // 用 realpath 过的临时根：否则 macOS 下 /var → /private/var 会让 baseDir 与
        // realpath(baseDir) 两个字符串不相等，测试自己先跟归属判定打架
        $tmp = (string) realpath(sys_get_temp_dir());
        $this->base = $tmp . '/kode-restore-root-' . bin2hex(random_bytes(6));
        $this->outside = $tmp . '/kode-restore-outside-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0775, true);
        mkdir($this->outside, 0775, true);

        $this->plugin = 'zz' . substr(md5(uniqid('', true)), 0, 10);
        $class = 'Kode\\Plugins\\' . ucfirst($this->plugin) . 'Plugin';
        $this->mainFile = ucfirst($this->plugin) . 'Plugin.php';
        $main = $this->base . '/' . $this->mainFile;
        $this->putAt($main, $this->fixtureSource($class, $this->plugin));
        // 插件根不在 plugins/，PSR-4 找不到这个类；先 require 再由 scan() 的 class_exists 认下
        require_once $main;
        mkdir($this->base . '/' . $this->plugin, 0775, true);

        PluginDiscoverer::useBaseDir($this->base);
        self::assertSame(
            $class,
            PluginMarket::discover()[$this->plugin] ?? null,
            '测试钩子失效：临时插件根没被发现，后面的断言全是空的'
        );
    }

    protected function tearDown(): void
    {
        PluginDiscoverer::useBaseDir(null);
        if ($this->plugin !== '') {
            try {
                PluginMarket::forgetStatusCache($this->plugin);
            } catch (\Throwable) {
            }
            if ($this->hasDb()) {
                try {
                    // 恢复成功路径会经 PluginVersionHistory::changeVersion() 写账本行；
                    // 只删 kode_plugins 会把「插件已删、版本历史还在」留在开发库里
                    // （口径同 PluginSupplyChainTest::removePackage）。
                    Db::update('DELETE FROM kode_plugin_versions WHERE name = ?', [$this->plugin]);
                    Db::update('DELETE FROM kode_plugins WHERE name = ?', [$this->plugin]);
                } catch (\Throwable) {
                }
            }
        }
        $this->removeTree($this->base);
        $this->removeTree($this->outside);
        $this->base = '';
        $this->outside = '';
        $this->plugin = '';
        $this->mainFile = '';
        // 必须回父类：基类 tearDown 负责清应用缓存（见 PluginSupplyChainTest 同处注释）
        parent::tearDown();
    }

    /* ==================== export()：不完整就得说明 ==================== */

    public function test_export_names_the_entries_it_could_not_read(): void
    {
        $this->seed($this->plugin . '/a.php', '<?php // a');
        $this->seed($this->plugin . '/docs/readme.md', "# hi\n");
        // 超过 read() 的单文件上限：老写法静默跳过，消息只说「已导出 N 个文件」
        $this->seed($this->plugin . '/big.md', str_repeat('x', 1025 * 1024));

        $r = PluginBackupService::export($this->plugin);
        self::assertTrue($r['ok'], $r['message']);

        $payload = json_decode($r['json'], true);
        self::assertSame(
            [$this->mainFile, $this->plugin . '/a.php', $this->plugin . '/docs/readme.md'],
            array_column((array) $payload['plugins'][0]['files'], 'path'),
            '导出的文件面应当正好是「主类 + 插件目录内读得动的」'
        );
        // 小票必须落在备份本体里：光有返回值，那份 JSON 存下来后再没人知道它缺文件
        self::assertFalse($payload['plugins'][0]['complete']);
        self::assertCount(1, $payload['plugins'][0]['missing']);
        self::assertCount(1, $r['missing']);
        self::assertStringContainsString($this->plugin . '/big.md', $r['missing'][0]);
        self::assertStringContainsString('1MB', $r['missing'][0]);
        self::assertStringContainsString('未含 1 个读不动的条目', $r['message']);

        // 恢复侧：缺文件的备份一条都不落，也不翻状态
        $before = $this->tree();
        $back = PluginBackupService::import($r['json']);
        self::assertFalse($back['ok'], '自述不完整的备份不得算恢复成功');
        self::assertSame([], $back['restored']);
        self::assertStringContainsString('备份不完整', $back['failed'][$this->plugin][0]);
        self::assertSame($before, $this->tree(), '不完整的备份不得改动插件根一个字节');
    }

    public function test_export_refuses_unknown_plugins(): void
    {
        $r = PluginBackupService::export('zz_not_there');
        self::assertFalse($r['ok']);
        self::assertSame('插件不存在', $r['message']);
    }

    /* ==================== write() / check()：归属与新建 ==================== */

    public function test_editor_semantics_refuse_to_create_files(): void
    {
        $r = PluginFileExplorer::write($this->plugin, $this->plugin . '/new.php', '<?php // new');
        self::assertFalse($r['ok'], '编辑器路径不得凭空建文件（只有恢复可以）');
        self::assertSame('文件不存在', $r['message']);
        self::assertFileDoesNotExist($this->base . '/' . $this->plugin . '/new.php');
        // check() 与 write() 必须同口径，否则 import 的「先判后写」是假的
        self::assertSame($r['message'], PluginFileExplorer::check($this->plugin, $this->plugin . '/new.php', '<?php // new'));
    }

    public function test_restore_may_create_missing_paths_inside_its_own_plugin(): void
    {
        $rel = $this->plugin . '/deep/nested/x.php';
        $r = PluginFileExplorer::write($this->plugin, $rel, '', true);
        self::assertTrue($r['ok'], $r['message']);

        $abs = $this->base . '/' . $rel;
        self::assertFileExists($abs);
        // 空内容是合法产物（空文件），不是「没有内容」
        self::assertSame('', (string) file_get_contents($abs));
        self::assertSame(0644, fileperms($abs) & 0777, '新建文件模式要写死，不跟 umask 赌');
        foreach (['deep', 'deep/nested'] as $dir) {
            self::assertSame(0, fileperms($this->base . '/' . $this->plugin . '/' . $dir) & 0002, '目录不得全局可写');
        }
    }

    public function test_write_ownership_and_shape_policy(): void
    {
        $foreignMain = 'ZzForeignPlugin.php';
        $this->seed($foreignMain, '<?php // 别人的主类');

        /** @var array<string, string> $cases 路径 => 期望的拒绝原因 */
        $cases = [
            'other/x.php' => '不属于这个插件',
            $this->plugin . "/../other/x.php" => '`..`',
            '../x.php' => '`..`',
            './' . $this->plugin . '/x.php' => '空段',
            $this->plugin . '//x.php' => '空段',
            '/etc/x.php' => '路径非法',
            'C:\\x\\y.php' => '路径非法',
            $foreignMain => '根级只允许这个插件自己的主类文件',
            $this->plugin . '/sh.so' => '不支持的文件类型',
            $this->plugin . '/x.php.jpg' => '不支持的文件类型',
        ];
        $before = $this->tree();
        foreach ($cases as $rel => $why) {
            foreach ([false, true] as $allowCreate) {
                $msg = PluginFileExplorer::check($this->plugin, $rel, '<?php // 注入', $allowCreate);
                self::assertNotNull($msg, $rel . ' 应当被拒（' . $why . '）');
                self::assertStringContainsString($why, (string) $msg, $rel . ' 的拒绝原因不对');
            }
        }
        // 超长内容单独判（要造 > 单文件上限）
        self::assertStringContainsString(
            '内容超过 1MB 限制',
            (string) PluginFileExplorer::check($this->plugin, $this->plugin . '/big.md', str_repeat('x', 1025 * 1024), true)
        );
        // 非法插件名：大写开头根本进不了 NAME_PATTERN，也不该被拼进路径
        self::assertStringContainsString(
            '非法插件名',
            (string) PluginFileExplorer::check('BadName', 'x.php', '<?php', true)
        );

        self::assertSame($before, $this->tree(), '拒绝路径不得改动插件根一个字节');
    }

    public function test_write_refuses_symlinks_in_a_restored_tree(): void
    {
        // 恢复读的是用户递进来的 JSON：链接指向哪儿都有可能，一律不写
        $secret = $this->outside . '/secret.txt';
        $this->putAt($secret, 'TOP-SECRET');
        symlink($secret, $this->base . '/' . $this->plugin . '/link.php');

        $r = PluginFileExplorer::write($this->plugin, $this->plugin . '/link.php', '<?php // 覆盖', true);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('符号链接', $r['message']);
        self::assertSame('TOP-SECRET', file_get_contents($secret), '顺着链接写出去就是越界');

        mkdir($this->outside . '/victim', 0775, true);
        symlink($this->outside . '/victim', $this->base . '/' . $this->plugin . '/vdir');
        $r = PluginFileExplorer::write($this->plugin, $this->plugin . '/vdir/x.php', '<?php // 落地', true);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('符号链接', $r['message']);
        self::assertSame([], $this->namesIn($this->outside . '/victim'), '目录是链接时不得往里建文件');
    }

    /* ==================== read()：读侧与写侧同一条归属线 ==================== */

    public function test_read_refuses_files_belonging_to_another_plugin(): void
    {
        $foreignDir = 'zzforeign';
        $this->seed($foreignDir . '/secret.php', '<?php // 别的插件的源码，含凭证');
        $this->seed('ZzForeignPlugin.php', '<?php // 别人的主类');
        $own = $this->plugin . '/a.php';
        $this->seed($own, '<?php // 自己的');

        /** @var array<string, string> $cases 路径 => 期望的拒绝原因 */
        $cases = [
            $foreignDir . '/secret.php' => '该路径不属于这个插件',
            'ZzForeignPlugin.php' => '根级只允许这个插件自己的主类文件',
            $this->plugin . "/../{$foreignDir}/secret.php" => '`..`',
            './' . $this->plugin . '/a.php' => '空段',
            '/etc/passwd' => '路径非法',
            'C:\\plugins\\x.php' => '路径非法',
            $this->plugin . '/nope.php' => '文件不存在',
        ];
        foreach ($cases as $rel => $why) {
            $r = PluginFileExplorer::read($this->plugin, $rel);
            self::assertFalse($r['ok'], $rel . ' 应当读不到');
            self::assertStringContainsString($why, (string) $r['message'], $rel . ' 的拒绝原因不对');
            self::assertSame('', $r['content'], '拒绝时不得把内容带进响应：' . $rel);
        }

        // 老实现只判「在插件根内」，name=本插件 + path=别人的文件 就能把别的插件源码读走
        self::assertTrue(PluginFileExplorer::read($this->plugin, $own)['ok']);
        self::assertTrue(PluginFileExplorer::read($this->plugin, $this->mainFile)['ok'], '主类文件照常读');
        // 同一个路径，换回它真正的主人去读就该放行：挡住的是归属，不是文件本身
        $asOwner = PluginFileExplorer::read($foreignDir, $foreignDir . '/secret.php');
        self::assertTrue($asOwner['ok'], (string) ($asOwner['message'] ?? 'ok'));
        self::assertStringContainsString('凭证', (string) $asOwner['content']);
    }

    public function test_read_refuses_a_symlink_pointing_at_another_plugin(): void
    {
        // 链接落在自己家里，指出去的是别人的文件：字符串归属过得了，真实落点还得再判一次
        $this->seed('zzother/s.php', '<?php // 别人家');
        symlink($this->base . '/zzother/s.php', $this->base . '/' . $this->plugin . '/l.php');

        $r = PluginFileExplorer::read($this->plugin, $this->plugin . '/l.php');
        self::assertFalse($r['ok'], '顺着链接读出去就是越界');
        self::assertStringContainsString('不属于这个插件', $r['message']);
        self::assertStringNotContainsString('别人家', $r['content']);
    }

    public function test_exported_entries_are_all_restorable(): void
    {
        // 导出面与恢复面必须是同一批路径：list 出来的每一条，check() 都要放行，
        // 否则这份 JSON 恢复出来就是「半套文件 + 状态已启用」
        $this->seed($this->plugin . '/a.php', '<?php // a');
        $this->seed($this->plugin . '/docs/readme.md', "# hi\n");

        $listed = PluginFileExplorer::list($this->plugin);
        self::assertTrue($listed['ok']);
        $paths = array_column($listed['files'], 'path');
        self::assertSame(
            [$this->mainFile, $this->plugin . '/a.php', $this->plugin . '/docs/readme.md'],
            $paths,
            '枚举面 = 主类 + 插件目录整棵树（排序后）'
        );

        $exported = PluginBackupService::export($this->plugin);
        self::assertTrue($exported['ok'], $exported['message']);
        self::assertSame([], $exported['missing'], '一个都读不动就说明样本不对');
        $payload = (array) json_decode($exported['json'], true);
        $pluginEntry = (array) $payload['plugins'][0];
        self::assertTrue($pluginEntry['complete'] ?? false, '完整备份要自述 complete:true');
        $inBackup = array_column((array) $pluginEntry['files'], 'path');
        sort($inBackup);
        $sorted = $paths;
        sort($sorted);
        self::assertSame($sorted, $inBackup, '导出必须把 list 到的每一条都装进去');

        foreach ((array) $pluginEntry['files'] as $f) {
            self::assertNull(
                PluginFileExplorer::check($this->plugin, (string) $f['path'], (string) $f['content'], true),
                $f['path'] . ' 导得出去却恢复不回来：两个上限/白名单又各写一套了'
            );
        }
    }

    /* ==================== import()：有一条不落盘就整个失败 ==================== */

    public function test_import_lands_no_bytes_when_any_entry_is_refused(): void
    {
        $overwrite = '<?php' . "\n" . 'namespace Kode\\Plugins;' . "\n" . '// 主类被改写';
        $this->seed($this->mainFile, $overwrite . '// 原内容');
        $original = file_get_contents($this->base . '/' . $this->mainFile);
        $before = $this->tree();

        $r = PluginBackupService::import($this->backupJson([
            ['path' => $this->plugin . '/good.php', 'content' => '<?php // good'],
            ['path' => $this->mainFile, 'content' => $overwrite],
            ['path' => 'other/x.php', 'content' => '<?php // 越界'],
        ], PluginMarket::ENABLED, '2.0.0'));

        self::assertFalse($r['ok'], '有条目没落盘就不能算恢复成功');
        self::assertSame([], $r['restored']);
        self::assertSame(['other/x.php：该路径不属于这个插件'], $r['failed'][$this->plugin]);
        self::assertStringContainsString('1 个插件未落盘', $r['message']);
        self::assertFileDoesNotExist($this->base . '/' . $this->plugin . '/good.php', '先判后写：坏条目在后时，好条目也不许先落盘');
        self::assertSame($original, file_get_contents($this->base . '/' . $this->mainFile), '半套写入 = 又一个「装了但坏了」');
        self::assertSame($before, $this->tree());
        if ($this->hasDb()) {
            self::assertNull(PluginMarket::statusOf($this->plugin), '恢复失败却把状态翻成 enabled，下次启动直接 fatal');
        }
    }

    public function test_import_lands_every_file_then_flips_state(): void
    {
        $this->requireDb();

        $r = PluginBackupService::import($this->backupJson([
            ['path' => $this->plugin . '/a.php', 'content' => '<?php // a'],
            ['path' => $this->plugin . '/deep/b.md', 'content' => ''],
        ], PluginMarket::PAUSED, '3.2.1'));

        self::assertTrue($r['ok'], $r['message']);
        self::assertSame([$this->plugin], $r['restored']);
        self::assertSame('<?php // a', file_get_contents($this->base . '/' . $this->plugin . '/a.php'));
        self::assertSame('', file_get_contents($this->base . '/' . $this->plugin . '/deep/b.md'), '空文件也要真的建出来');
        self::assertSame(PluginMarket::PAUSED, PluginMarket::statusOf($this->plugin));
        $row = Db::select('SELECT version FROM kode_plugins WHERE name = ?', [$this->plugin]);
        self::assertSame('3.2.1', (string) ($row[0]['version'] ?? ''), '版本自述面要跟着恢复');
    }

    public function test_import_reports_each_broken_entry_instead_of_guessing(): void
    {
        $before = $this->tree();
        $r = PluginBackupService::import($this->backupJson([
            ['path' => '', 'content' => 'x'],
            ['content' => '没有 path 也算坏'],
            ['path' => $this->plugin . '/c.php'],
        ]));

        self::assertFalse($r['ok']);
        self::assertSame(
            ['(缺 path)', '(缺 path)', $this->plugin . '/c.php：条目缺少 content'],
            $r['failed'][$this->plugin]
        );
        self::assertSame($before, $this->tree());

        // 「一个文件都没有」不能当成「无事发生、恢复成功」
        $empty = PluginBackupService::import($this->backupJson([]));
        self::assertFalse($empty['ok']);
        self::assertSame(['备份里一个文件都没有'], $empty['failed'][$this->plugin]);
    }

    public function test_import_refuses_an_unknown_format_version(): void
    {
        $json = (string) json_encode([
            'version' => 2,
            'plugins' => [['name' => $this->plugin, 'files' => [['path' => $this->plugin . '/v2.php', 'content' => 'x']]]],
        ]);
        $before = $this->tree();

        $r = PluginBackupService::import($json);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('不支持的备份格式版本 2', $r['message']);
        self::assertSame($before, $this->tree(), '结构对不上就别按 v1 半恢复一遍');
    }

    public function test_import_refuses_junk_input(): void
    {
        foreach (['不是 JSON', '[]', '{}'] as $json) {
            $r = PluginBackupService::import($json);
            self::assertFalse($r['ok'], $json);
        }
        self::assertStringContainsString('JSON 解析失败', PluginBackupService::import('不是 JSON')['message']);
        self::assertStringContainsString('备份格式无效', PluginBackupService::import('[]')['message']);
    }

    public function test_illegal_names_are_skipped_not_written(): void
    {
        $before = $this->tree();
        $json = (string) json_encode([
            'version' => 1,
            'plugins' => [
                ['name' => 'Bad-Name', 'files' => [['path' => 'x.php', 'content' => 'y']]],
                ['name' => '', 'files' => []],
            ],
        ]);

        $r = PluginBackupService::import($json);
        self::assertFalse($r['ok']);
        self::assertSame(['Bad-Name', '(无名条目)'], $r['skipped']);
        self::assertSame($before, $this->tree());
        self::assertStringContainsString('0 个成功', $r['message']);
    }

    public function test_export_then_import_is_a_no_op(): void
    {
        $this->requireDb();
        $this->seed($this->plugin . '/a.php', '<?php // a');
        $this->seed($this->plugin . '/docs/readme.md', "# hi\n");
        $before = $this->contents();

        $exported = PluginBackupService::export($this->plugin);
        self::assertTrue($exported['ok'], $exported['message']);
        self::assertCount(3, $before, '样本得真有主类 + 两个子目录文件，否则这组断言是空的');
        $r = PluginBackupService::import($exported['json']);
        self::assertTrue($r['ok'], $r['message']);
        self::assertSame([$this->plugin], $r['restored']);

        // 内容一字不差：恢复不是「重写一遍近似的东西」
        self::assertSame($before, $this->contents(), '同一份备份恢复自己，不该改动任何字节');
    }

    /* ==================== 静态门禁：能力面不许随手扩 ==================== */

    public function test_only_restore_is_allowed_to_create_files(): void
    {
        $gate = false;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__) . '/app', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (!preg_match_all('/PluginFileExplorer::write\(([^;]*)\);/s', $src, $m)) {
                continue;
            }
            foreach ($m[1] as $args) {
                if (str_contains($args, ', true')) {
                    self::assertSame(
                        'PluginBackupService.php',
                        $file->getFilename(),
                        '凭空建文件的能力只属于恢复链路；再开一个入口就是又一个「JSON 想写哪写哪」'
                    );
                    $gate = true;
                }
            }
        }
        self::assertTrue($gate, '恢复已经不走 write(..., true) 了？先确认 check() 那道预判还在不在');

        $src = (string) file_get_contents(dirname(__DIR__) . '/app/support/PluginBackupService.php');
        self::assertStringContainsString('PluginFileExplorer::check(', $src, '恢复必须先判后写');
    }

    /* ==================== 工具 ==================== */

    private function fixtureSource(string $class, string $name): string
    {
        $ns = substr($class, 0, (int) strrpos($class, '\\'));

        return <<<PHP
            <?php

            namespace {$ns};

            use Kode\\Framework\\Plugin\\PluginInterface;
            use Kode\\Framework\\Plugin\\PluginManager;

            class {$this->baseClass()} implements PluginInterface
            {
                public function name(): string
                {
                    return '{$name}';
                }

                public function register(PluginManager \$manager): void
                {
                }

                public function boot(PluginManager \$manager): void
                {
                }
            }
            PHP;
    }

    private function baseClass(): string
    {
        return ucfirst($this->plugin) . 'Plugin';
    }

    /**
     * @param list<array<string, mixed>> $files
     */
    private function backupJson(array $files, string $status = PluginMarket::ENABLED, string $version = '1.0.0'): string
    {
        return (string) json_encode([
            'version' => 1,
            'plugins' => [[
                'name' => $this->plugin,
                'status' => $status,
                'version' => $version,
                'config' => [],
                'files' => $files,
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 插件根快照（路径 => 大小+mtime）：够判「动过没有」 */
    private function tree(): array
    {
        clearstatcache();
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $out[$file->getPathname()] = $file->isDir() ? 0 : (int) ($file->getSize() + $file->getMTime());
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> 目录内条目名（排除 . / ..） */
    private function namesIn(string $dir): array
    {
        return array_values(array_diff((array) scandir($dir), ['.', '..']));
    }

    /** 内容快照（相对路径 => md5）：恢复自己时 mtime 会变，字节不能变 */
    private function contents(): array
    {
        clearstatcache();
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $out[substr($file->getPathname(), strlen($this->base) + 1)] = (string) md5_file($file->getPathname());
            }
        }
        ksort($out);

        return $out;
    }

    private function seed(string $rel, string $content): void
    {
        $this->putAt($this->base . '/' . $rel, $content);
    }

    private function putAt(string $abs, string $content): void
    {
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($abs, $content);
        clearstatcache();
    }

    private function requireDb(): void
    {
        if (!$this->hasDb()) {
            self::markTestSkipped('需要可写的 kode_plugins 表');
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
