<?php

declare(strict_types=1);

namespace app\tests;

use app\support\PluginFileExplorer;
use app\support\PluginMarket;
use Kode\Framework\Plugin\PluginDiscoverer;
use Kode\Framework\Testing\TestCase;

/**
 * 静态门禁：`plugins/` 的目录形状必须让「这个插件有哪些文件」一句话能说清。
 *
 * 为什么盯这个看起来最不像 bug 的地方：插件文件浏览器、备份导出/恢复、代码统计、
 * 市场打包全都按同一条枚举面算——「主类文件 + `plugins/<name>/` 整棵树」。
 * 代码一旦放在这条线之外（根级兄弟类、或者一个和 name 不同名的目录），
 * 备份就**少抓文件**，而恢复侧压根不知道少了什么：
 * 「半套文件 + 状态已启用」= 下次启动直接 fatal（AGENTS §4 点名的坏法）。
 *
 * 实测过的两种跑偏：
 *   - `plugins/DemoService.php`：demo 主类的兄弟类躺在根级，list('demo') 只回主类；
 *   - `plugins/Builder/`、`plugins/GiteeRelease/`、`plugins/WorkflowBridge/`：
 *     三个目录都由 FrontendPlugin（name=frontend）装载，名字却对不上，
 *     整份 frontend 备份只剩一个主类文件（1 个文件 vs 实际 6 个）。
 *
 * 形状守住了，list() 就不需要「猜」谁的目录属于谁；这里再把真实插件树跑一遍
 * 「列出来的每一条都恢复得回去」，锁住读侧上限与写侧白名单不再各写一套。
 */
final class PluginLayoutGateTest extends TestCase
{
    /**
     * 已知例外：根级摆着、但永远不会被发现的插件主类。
     *
     * `GiteeReleasePlugin::name()` 返回 `gitee-release`，含连字符，过不了
     * PluginDiscoverer::NAME_PATTERN（小写字母/数字/下划线）——它既不会被框架加载，
     * 也不在 config/plugins.php 声明，路由实际由 FrontendPlugin 注册。
     * 这份文件是死代码，删除它属于产品决定（要么改成合法名独立成插件，要么并进 frontend），
     * 所以先点名记账：它一旦消失，下面的断言会要求把它从这份清单里删掉。
     *
     * @var list<string>
     */
    private const KNOWN_UNDISCOVERABLE_MAINS = ['GiteeReleasePlugin.php'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    public function test_plugin_root_only_holds_main_classes_and_named_dirs(): void
    {
        $base = PluginDiscoverer::baseDir();
        self::assertDirectoryExists($base, '插件根解析失败，下面的断言会是空的');

        $discovered = PluginMarket::discover();
        self::assertNotSame([], $discovered, '发现器一个插件都没认出来：本门禁将形同虚设');

        $knownMains = [];
        foreach ($discovered as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            if (is_string($file)) {
                $knownMains[basename($file)] = true;
            }
        }

        $strays = [];
        foreach (scandir($base) ?: [] as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
                continue;
            }
            $abs = $base . '/' . $item;
            if (is_dir($abs) && !is_link($abs)) {
                // 目录名 = name() 返回值：这是「这个插件的文件」的唯一识别方式
                if (!isset($discovered[$item])) {
                    $strays[] = $item . '/（没有叫这个名字的插件，list() 永远不会扫到它）';
                }
                continue;
            }
            if (!isset($knownMains[$item]) && !in_array($item, self::KNOWN_UNDISCOVERABLE_MAINS, true)) {
                $strays[] = $item . '（根级只放各插件自己的主类文件）';
            }
        }

        self::assertSame(
            [],
            $strays,
            "plugins/ 出现了枚举面之外的文件。请把它挪进 plugins/<name>/（目录名 = name() 返回值）：\n  "
            . implode("\n  ", $strays)
        );

        // 例外清单必须和现实一样长：文件被删了或改名，就得回来更新这里
        foreach (self::KNOWN_UNDISCOVERABLE_MAINS as $known) {
            self::assertFileExists(
                $base . '/' . $known,
                $known . ' 已经不在 plugins/ 根级了，请把 KNOWN_UNDISCOVERABLE_MAINS 里的这条例外删掉'
            );
        }
    }

    public function test_every_listed_file_of_a_real_plugin_is_restorable(): void
    {
        foreach (PluginMarket::discover() as $name => $class) {
            $listed = PluginFileExplorer::list($name);
            self::assertTrue($listed['ok'], $name . '：' . (string) ($listed['message'] ?? ''));

            // 1. 枚举面 = 磁盘上这个插件的全部文件（主类 + `<name>/` 整棵树）
            $expected = $this->filesUnder($name, $class);
            $actual = array_column($listed['files'], 'path');
            sort($expected);
            sort($actual);
            self::assertSame(
                $expected,
                $actual,
                $name . '：list() 与磁盘对不上，少掉的那些文件备份里根本没有（恢复=半套）'
            );
            self::assertNotSame([], $actual, $name . '：枚举面为空，本用例是空的');

            // 2. 列出来的每一条都要能恢复回去：读侧上限与写侧白名单必须是同一套口径
            foreach ($listed['files'] as $entry) {
                $abs = PluginDiscoverer::baseDir() . '/' . $entry['path'];
                $refusal = PluginFileExplorer::check(
                    $name,
                    (string) $entry['path'],
                    (string) file_get_contents($abs),
                    true
                );
                self::assertNull(
                    $refusal,
                    $name . ' 的 ' . $entry['path'] . ' 列得出、备份得走，却恢复不回来：' . (string) $refusal
                );
            }
        }
    }

    /**
     * 挪文件会连带挪 `__DIR__`：目录深度算错时不会报错，只会把文件写到别处。
     *
     * LicenseService 的密钥目录默认回落在项目根 `storage/keys/lowcode-license`，
     * 由 `dirname(__DIR__, N)` 推出来。这一级错了没人会立刻发现（下次签发才在别处建目录），
     * 所以直接断言解析结果落在项目根内。
     */
    public function test_license_key_dir_fallback_still_resolves_into_the_project(): void
    {
        if ((string) config('uni3.license_key_dir', '') !== '') {
            self::markTestSkipped('本机自定义了密钥目录，默认回落路径不在本用例范围内');
        }

        $private = (new \Kode\Plugins\frontend\LicenseService())->privateKeyPath();
        $expect = dirname(__DIR__) . '/storage/keys/lowcode-license/license.private.pem';
        self::assertSame($expect, $private, '密钥目录的目录深度对不上项目根：移动插件文件时改错了 dirname()');
    }

    /**
     * 这个插件在磁盘上真实拥有的文件（相对插件根）。
     *
     * @return list<string>
     */
    private function filesUnder(string $name, string $class): array
    {
        $base = PluginDiscoverer::baseDir();
        $out = [];

        $main = (new \ReflectionClass($class))->getFileName();
        if (is_string($main) && is_file($main)) {
            $out[] = substr($main, strlen($base) + 1);
        }

        $dir = $base . '/' . $name;
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile()) {
                    $out[] = substr($file->getPathname(), strlen($base) + 1);
                }
            }
        }

        return $out;
    }
}
