<?php

declare(strict_types=1);

namespace app\tests;

use app\support\PluginScaffolder;
use PHPUnit\Framework\TestCase;

/**
 * 插件脚手架测试。
 *
 * 覆盖：命名规范化、非法名拒绝、已存在跳过 / force 覆盖、
 *       register=true 写入 config/plugins.php、文件内容结构。
 *
 * 隔离性：所有生成文件落在 sys_get_temp_dir() 下的临时目录，
 *         tearDown 整目录回收，不留脏文件。
 */
final class PluginScaffoldTest extends TestCase
{
    private string $tmpDir;
    private PluginScaffolder $scaffolder;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kode_scaffold_' . uniqid('', true);
        $this->assertDirectoryMkdir($this->tmpDir);
        $this->scaffolder = new PluginScaffolder($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tmpDir);
        $this->tmpDir = '';
    }

    // ─── 命名规范化 ─────────────────────────────────────

    public function test_studly_case(): void
    {
        $this->assertStudly('Blog', 'BlogPlugin');
        $this->assertStudly('blog', 'BlogPlugin');
        $this->assertStudly('BlogPlugin', 'BlogPlugin');
        $this->assertStudly('blog-plugin', 'BlogPlugin');
        $this->assertStudly('blog_plugin', 'BlogPlugin');
        $this->assertStudly('myBlog', 'MyBlogPlugin');
        $this->assertStudly('cms_article', 'CmsArticlePlugin');
        $this->assertStudly('CMS_ARTICLE', 'CmsArticlePlugin');
    }

    public function test_plugin_name_snake_case(): void
    {
        // BlogPlugin → 去后缀 → Blog → snake: blog
        $res = $this->scaffolder->scaffold('BlogPlugin');
        self::assertSame('blog', $res['name']);

        $res = $this->scaffolder->scaffold('CmsArticle');
        self::assertSame('cms_article', $res['name']);
    }

    // ─── 基本生成 ────────────────────────────────────────

    public function test_scaffold_creates_class_and_manifest(): void
    {
        $res = $this->scaffolder->scaffold('Blog');

        self::assertTrue($res['ok']);
        self::assertFalse($res['skipped']);
        self::assertFalse($res['registered']);
        self::assertSame('Kode\Plugins\BlogPlugin', $res['class']);

        $classPath = $this->tmpDir . '/plugins/BlogPlugin.php';
        $manifestPath = $this->tmpDir . '/plugins/BlogPlugin.plugin.json';
        $changelogPath = $this->tmpDir . '/plugins/BlogPlugin.CHANGELOG.md';

        self::assertFileExists($classPath);
        self::assertFileExists($manifestPath);
        self::assertFileExists($changelogPath);
        self::assertSame($changelogPath, $res['files']['changelog']);
    }

    public function test_class_content_structure(): void
    {
        $this->scaffolder->scaffold('Blog');
        $content = file_get_contents($this->tmpDir . '/plugins/BlogPlugin.php');

        self::assertStringContainsString('namespace Kode\Plugins;', $content);
        self::assertStringContainsString('final class BlogPlugin implements PluginInterface', $content);
        self::assertStringContainsString("return 'blog';", $content);
        self::assertStringContainsString('public function register(', $content);
        self::assertStringContainsString('public function boot(', $content);
        self::assertStringContainsString('public function requires()', $content);
        self::assertStringContainsString("return '1.0.0';", $content);

        // 可选能力方法作为注释示例存在
        self::assertStringContainsString('// public function tables()', $content);
        self::assertStringContainsString('// public function install()', $content);
        self::assertStringContainsString('// public function uninstall(', $content);
        self::assertStringContainsString('// public function upgrade(', $content);
        self::assertStringContainsString('// public function configSchema()', $content);
        self::assertStringContainsString('// public function permissions()', $content);
        self::assertStringContainsString('// public function drivers()', $content);
        self::assertStringContainsString('// public function menus()', $content);
        self::assertStringContainsString('// public function commands()', $content);
        self::assertStringContainsString('// public function events()', $content);
        self::assertStringContainsString('// public function crons()', $content);
        self::assertStringContainsString('// public function license()', $content);
        self::assertStringContainsString('// public function author()', $content);
    }

    public function test_manifest_valid_json(): void
    {
        $this->scaffolder->scaffold('Blog');
        $content = file_get_contents($this->tmpDir . '/plugins/BlogPlugin.plugin.json');
        $decoded = json_decode($content, true);

        self::assertIsArray($decoded);
        self::assertSame('blog', $decoded['name']);
        self::assertSame('1.0.0', $decoded['version']);
        self::assertSame('Kode\Plugins\BlogPlugin', $decoded['class']);
        self::assertSame('MIT', $decoded['license']);
        self::assertSame('BlogPlugin.CHANGELOG.md', $decoded['changelog']);
    }

    // ─── 非法名拒绝 ──────────────────────────────────────

    public function test_changelog_content(): void
    {
        $this->scaffolder->scaffold('Blog');
        $content = file_get_contents($this->tmpDir . '/plugins/BlogPlugin.CHANGELOG.md');

        self::assertStringContainsString('# BlogPlugin Changelog', $content);
        self::assertStringContainsString('Keep a Changelog', $content);
        self::assertStringContainsString('## [1.0.0]', $content);
        self::assertStringContainsString('初始插件骨架生成', $content);
    }

    // ─── 非法名拒绝 ──────────────────────────────────────

    public function test_empty_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->scaffolder->scaffold('');
    }

    public function test_single_char_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->scaffolder->scaffold('a');
    }

    public function test_special_chars_name_throws(): void
    {
        // 纯特殊字符 → studly 后为空 → snake 后为空，不匹配 [a-z0-9_]{2,32}
        $this->expectException(\InvalidArgumentException::class);
        $this->scaffolder->scaffold('@#$');
    }

    public function test_all_uppercase_normalizes_to_valid(): void
    {
        // ABC → AbcPlugin，name=a_b_c（snake 在连续大写间插下划线）
        $res = $this->scaffolder->scaffold('ABC');
        self::assertSame('Kode\Plugins\AbcPlugin', $res['class']);
        self::assertSame('a_b_c', $res['name']);
        self::assertStringEndsWith('AbcPlugin.php', $res['files']['class']);
    }

    public function test_chinese_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->scaffolder->scaffold('博客');
    }

    // ─── 跳过 / 覆盖 ────────────────────────────────────

    public function test_existing_file_skipped_without_force(): void
    {
        $this->scaffolder->scaffold('Blog');
        $res = $this->scaffolder->scaffold('Blog');

        self::assertTrue($res['skipped']);
        self::assertStringContainsString('已存在，跳过', $res['message']);
    }

    public function test_force_overwrites_existing(): void
    {
        $this->scaffolder->scaffold('Blog');
        file_put_contents($this->tmpDir . '/plugins/BlogPlugin.php', '<?php // tampered');

        $res = $this->scaffolder->scaffold('Blog', force: true);
        self::assertFalse($res['skipped']);

        $content = file_get_contents($this->tmpDir . '/plugins/BlogPlugin.php');
        self::assertStringContainsString('implements PluginInterface', $content);
    }

    // ─── config/plugins.php 登记 ──────────────────────────

    public function test_register_writes_to_config(): void
    {
        $this->makeConfigFile(['demo']);

        $res = $this->scaffolder->scaffold('Blog', register: true);

        self::assertTrue($res['registered']);
        $config = file_get_contents($this->tmpDir . '/config/plugins.php');
        self::assertStringContainsString('\\Kode\\Plugins\\BlogPlugin::class', $config);
        self::assertStringContainsString('DemoPlugin::class', $config);
    }

    public function test_register_false_does_not_write(): void
    {
        $this->makeConfigFile([]);

        $res = $this->scaffolder->scaffold('Blog', register: false);
        self::assertFalse($res['registered']);

        $config = file_get_contents($this->tmpDir . '/config/plugins.php');
        self::assertStringNotContainsString('BlogPlugin', $config);
    }

    public function test_register_idempotent_when_already_declared(): void
    {
        $this->makeConfigFile(['blog']);

        $res = $this->scaffolder->scaffold('Blog', register: true, force: true);
        self::assertTrue($res['registered']);

        $config = file_get_contents($this->tmpDir . '/config/plugins.php');
        $count = substr_count($config, 'BlogPlugin::class');
        self::assertSame(1, $count, '不应重复登记');
    }

    public function test_register_skipped_when_scaffold_skipped(): void
    {
        $this->scaffolder->scaffold('Blog');
        $res = $this->scaffolder->scaffold('Blog', register: true);

        self::assertTrue($res['skipped']);
        self::assertFalse($res['registered']);
    }

    // ─── 多行数组 / 空数组 ───────────────────────────────

    public function test_register_empty_plugins_array(): void
    {
        $this->assertDirectoryMkdir($this->tmpDir . '/config');
        file_put_contents($this->tmpDir . '/config/plugins.php', "<?php\nreturn [\n    'plugins' => [],\n];\n");

        $res = $this->scaffolder->scaffold('Blog', register: true);
        self::assertTrue($res['registered']);

        $config = file_get_contents($this->tmpDir . '/config/plugins.php');
        self::assertStringContainsString('BlogPlugin::class', $config);
    }

    public function test_register_multiline_plugins_array(): void
    {
        $this->assertDirectoryMkdir($this->tmpDir . '/config');
        file_put_contents($this->tmpDir . '/config/plugins.php', "<?php\nreturn [\n    'plugins' => [\n        \\Kode\\Plugins\\NoticePlugin::class,\n        \\Kode\\Plugins\\StorageDriverPlugin::class,\n    ],\n    'enabled' => true,\n];\n");

        $res = $this->scaffolder->scaffold('Blog', register: true);
        self::assertTrue($res['registered']);

        $config = file_get_contents($this->tmpDir . '/config/plugins.php');
        self::assertStringContainsString('BlogPlugin::class', $config);
        self::assertStringContainsString('NoticePlugin::class', $config);
        self::assertStringContainsString('StorageDriverPlugin::class', $config);
        self::assertStringContainsString("'enabled' => true", $config);
    }

    // ─── 辅助 ───────────────────────────────────────────

    private function assertStudly(string $input, string $expectedClass): void
    {
        $res = $this->scaffolder->scaffold($input, force: true);
        self::assertStringEndsWith($expectedClass . '.php', $res['files']['class']);
        self::assertSame("Kode\\Plugins\\{$expectedClass}", $res['class']);
    }

    private function makeConfigFile(array $plugins): void
    {
        $this->assertDirectoryMkdir($this->tmpDir . '/config');
        $entries = array_map(
            static fn (string $name): string => "        \\Kode\\Plugins\\" . ucfirst($name) . "Plugin::class,",
            $plugins,
        );
        $inner = $entries === []
            ? ''
            : "\n" . implode("\n", $entries) . "\n    ";
        file_put_contents($this->tmpDir . '/config/plugins.php', "<?php\nreturn [\n    'plugins' => [{$inner}],\n];\n");
    }

    private function assertDirectoryMkdir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }
}
