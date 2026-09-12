<?php

declare(strict_types=1);

namespace app\tests;

use app\support\AppPackager;
use PHPUnit\Framework\TestCase;

/**
 * 系统级打包引擎（AppPackager）测试。
 *
 * 覆盖八件事：
 *  1. 配置解析与进程内 override——默认值齐全、覆盖是增量、override([]) 归位；
 *  2. 文件选择 collect()——白名单起点、四类排除规则（子树/basename/顶层/扩展名）各自生效、排序稳定；
 *  3. 前置门禁 precheck()/binaryPlatformIssues()——只报环境事实、二进制门禁是 phar 门禁的超集；
 *  4. 引导桩 stub()/renderedStub()——别名占位可被正确代入，桩内必备结构齐全；
 *  5. 自动加载重写 rewriteAutoloadFiles()——按实际文件集剔除缺失条目（声明⟺实现零漂移落到
 *     autoload 上），三份映射一起重写，输出为包内可解析的 __DIR__ 常量表达式，static 无关块原样保留；
 *  6. custom-ini 头 iniHeader() 与二进制拼接 toBin()——magic + 网络序长度 + 正文，产物体积可复算；
 *  7. 产物校验 verify() 与报告 report()/humanBytes()——真实产物 / 伪造文件 / 二进制档三分支判定；
 *  8. stage() 契约——空白名单不复制任何文件、不改动项目源。
 *
 * 隔离性：全部临时数据落在 sys_get_temp_dir() 下、tearDown 递归删除；
 * override() 是进程级静态状态，每个用例后强制归位，避免污染同进程其它测试。
 *
 * 明确不覆盖的部分：pack()/stage() 的完整构建路径（需 phar.readonly=0 且产出 25MB 产物，
 * 属集成级验证，已由命令行真机验证），downloadSfx()（外部网络 IO）。
 */
final class AppPackagerTest extends TestCase
{
    /** @var list<string> 本测试创建的临时目录（tearDown 递归删除） */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        \Kode\Framework\Application::make(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::rrmdir($dir);
        }
        $this->tempDirs = [];
        // override() 是进程级静态状态，必须归位。
        AppPackager::override([]);
        parent::tearDown();
    }

    // ── 1. 配置解析 ────────────────────────────────────────────────

    public function test_config_exposes_all_documented_keys(): void
    {
        $cfg = AppPackager::config();

        foreach ([
            'build_dir', 'phar_filename', 'bin_filename', 'signature_algorithm', 'openssl_private_key',
            'memory_limit_mb', 'php_version', 'micro_sfx_base',
            'include_dirs', 'include_files', 'exclude_dirs', 'exclude_files', 'exclude_top_files',
            'exclude_ext', 'must_include', 'runtime_dirs', 'custom_ini',
        ] as $key) {
            $this->assertArrayHasKey($key, $cfg, "配置缺键：{$key}");
        }

        $this->assertSame('build', $cfg['build_dir']);
        $this->assertSame('kode.phar', $cfg['phar_filename']);
        $this->assertSame('kode.bin', $cfg['bin_filename']);
        $this->assertSame(\Phar::SHA256, $cfg['signature_algorithm']);
        $this->assertContains('app', $cfg['include_dirs']);
        $this->assertContains('kode', $cfg['include_files']);
        // 安全红线：.env 与 storage/ 必须永不进包。
        $this->assertNotContains('storage', $cfg['include_dirs']);
        $this->assertNotContains('storage', $cfg['include_files']);
        $this->assertNotContains('.env', $cfg['include_files']);
    }

    public function test_config_php_version_defaults_to_current_php(): void
    {
        $this->assertSame(substr(PHP_VERSION, 0, 3), AppPackager::config()['php_version']);

        AppPackager::override(['php_version' => '8.2']);
        $this->assertSame('8.2', AppPackager::config()['php_version']);
    }

    public function test_override_is_incremental_and_clearable(): void
    {
        AppPackager::override(['build_dir' => 'release', 'memory_limit_mb' => 1024]);

        $cfg = AppPackager::config();
        $this->assertSame('release', $cfg['build_dir']);
        $this->assertSame(1024, $cfg['memory_limit_mb']);
        // 未覆盖的键必须保持原值——覆盖是增量，不是重置。
        $this->assertSame('kode.phar', $cfg['phar_filename']);

        AppPackager::override([]);
        $this->assertSame('build', AppPackager::config()['build_dir']);
    }

    public function test_build_dir_resolves_against_project_root(): void
    {
        $this->assertSame(AppPackager::projectRoot() . '/build', AppPackager::buildDir());

        AppPackager::override(['build_dir' => 'dist']);
        $this->assertSame(AppPackager::projectRoot() . '/dist', AppPackager::buildDir());
    }

    // ── 2. 文件选择 collect() ────────────────────────────────────────

    public function test_collect_returns_sorted_relative_paths(): void
    {
        AppPackager::override(['include_dirs' => ['config'], 'include_files' => []]);

        $paths = AppPackager::collect();

        $this->assertContains('config/app.php', $paths);
        $this->assertContains('config/packaging.php', $paths);
        $this->assertSame($paths, AppPackager::collect(), 'collect() 必须可重复（排序稳定）');

        foreach ($paths as $rel) {
            $this->assertStringStartsWith('config/', $rel, "只应包含 include_dirs 内的路径：{$rel}");
            $this->assertFalse(str_starts_with($rel, '/'), "路径必须是相对路径：{$rel}");
        }
    }

    public function test_collect_include_files_only_from_whitelist(): void
    {
        AppPackager::override(['include_dirs' => [], 'include_files' => ['kode', 'composer.json', 'no-such-file']]);

        $paths = AppPackager::collect();

        $this->assertContains('kode', $paths);
        $this->assertContains('composer.json', $paths);
        $this->assertNotContains('no-such-file', $paths, '白名单里不存在的文件必须被静默跳过');
    }

    public function test_collect_exclude_dirs_prunes_whole_subtree(): void
    {
        AppPackager::override(['include_dirs' => ['app'], 'include_files' => [], 'exclude_dirs' => ['app/console']]);

        $paths = AppPackager::collect();

        $this->assertContains('app/support/AppPackager.php', $paths);
        $this->assertNotContains('app/console/PackPharCommand.php', $paths);
        foreach ($paths as $rel) {
            $this->assertFalse(str_starts_with($rel, 'app/console/'), "排除的子树不得残留：{$rel}");
        }
    }

    public function test_collect_exclude_files_matches_basename_at_any_depth(): void
    {
        AppPackager::override(['include_dirs' => ['app'], 'include_files' => [], 'exclude_files' => ['AppPackager.php']]);

        $paths = AppPackager::collect();

        $this->assertNotContains('app/support/AppPackager.php', $paths);
        // 同目录其它文件不受影响——黑名单只按 basename 命中。
        $this->assertContains('app/support/SysConfig.php', $paths);
    }

    public function test_collect_exclude_top_files_blocks_root_file_only(): void
    {
        AppPackager::override(['include_dirs' => [], 'include_files' => ['kode', 'composer.json'], 'exclude_top_files' => ['composer.json']]);

        $this->assertSame(['kode'], AppPackager::collect());
    }

    public function test_collect_exclude_ext_filters_by_extension(): void
    {
        AppPackager::override(['include_dirs' => ['config'], 'include_files' => [], 'exclude_ext' => ['php']]);

        $this->assertSame([], AppPackager::collect(), '排除 .php 后 config 目录应无文件进包');
    }

    public function test_collect_default_config_keeps_every_declared_entry(): void
    {
        // 真实配置下的零漂移断言：声明进包的顶层文件与必备入口必须真的都在清单里。
        // 若有人往 exclude_ext 里加 'md' 这类值，README.md 会被静默丢弃，这里会当场拦住。
        $cfg = AppPackager::config();
        $paths = AppPackager::collect();

        foreach ($cfg['include_files'] as $name) {
            if (is_file(AppPackager::projectRoot() . '/' . $name)) {
                $this->assertContains($name, $paths, "声明进包的顶层文件不在清单里：{$name}");
            }
        }
        foreach ($cfg['must_include'] as $need) {
            $this->assertContains($need, $paths, "必备入口不在清单里：{$need}");
        }
        // 必备入口必须同时真的存在于磁盘。
        foreach ($cfg['must_include'] as $need) {
            $this->assertFileExists(AppPackager::projectRoot() . '/' . $need, "必备入口在磁盘上缺失：{$need}");
        }
    }

    // ── 3. 前置门禁 ──────────────────────────────────────────────────

    public function test_precheck_reports_phar_readonly_when_set(): void
    {
        $issues = AppPackager::precheck();
        $joined = $this->join($issues);

        $this->assertIsArray($issues);
        $hasReadonlyIssue = str_contains($joined, 'phar.readonly');

        // 门禁只反映 INI 事实：readonly=1 必报，readonly=0 必不报。
        $this->assertSame(
            ini_get('phar.readonly') !== '0',
            $hasReadonlyIssue,
            "precheck 的 phar.readonly 判定必须与 INI 实际值一致（当前 " . var_export(ini_get('phar.readonly'), true) . '）'
        );
    }

    public function test_precheck_requires_valid_openssl_key(): void
    {
        AppPackager::override(['signature_algorithm' => \Phar::OPENSSL]);

        $this->assertStringContainsString('openssl_private_key', $this->join(AppPackager::precheck()));
    }

    public function test_precheck_for_binary_is_superset_of_phar_gate(): void
    {
        $pharGate = AppPackager::precheck(false);
        $binGate = AppPackager::precheck(true);

        $this->assertGreaterThanOrEqual(count($pharGate), count($binGate));
        foreach ($pharGate as $issue) {
            $this->assertContains($issue, $binGate, "二进制门禁应包含 phar 门禁的同一项：{$issue}");
        }
        foreach (AppPackager::binaryPlatformIssues() as $issue) {
            $this->assertContains($issue, $binGate, "二进制门禁应包含平台约束：{$issue}");
        }
    }

    public function test_binary_platform_issues_match_runtime_facts(): void
    {
        $issues = AppPackager::binaryPlatformIssues();
        $joined = $this->join($issues);

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->assertStringContainsString('Linux', $joined);
            $this->assertStringContainsString(PHP_OS_FAMILY, $joined);
        }
        if (php_uname('m') !== 'x86_64') {
            $this->assertStringContainsString('x86_64', $joined);
            $this->assertStringContainsString(php_uname('m'), $joined);
        }
        // 条目数必须等于实际不满足的平台条件数——不能凭空放行或凭空拒绝。
        $this->assertSame(
            (PHP_OS_FAMILY !== 'Linux') + (php_uname('m') !== 'x86_64'),
            count($issues)
        );
    }

    public function test_pack_refuses_to_run_when_phar_readonly(): void
    {
        if (ini_get('phar.readonly') === '0') {
            $this->markTestSkipped('phar.readonly=0——本用例只覆盖只读拦截分支');
        }

        $result = AppPackager::pack(['build_dir' => $this->tempDir()]);

        $this->assertFalse($result['ok']);
        $this->assertNull($result['artifact']);
        $this->assertStringContainsString('phar.readonly', $this->join($result['errors']));
    }

    // ── 4. 引导桩 ────────────────────────────────────────────────────

    public function test_stub_keeps_alias_placeholder(): void
    {
        $stub = AppPackager::stub('kode.phar');

        $this->assertSame(2, substr_count($stub, '<<ALIAS>>'), '模板必须保留两处别名占位');
        $this->assertStringNotContainsString("Phar::mapPhar('kode.phar')", $stub);
        $this->assertStringContainsString('__HALT_COMPILER()', $stub);
        $this->assertStringStartsWith("#!/usr/bin/env php\n<?php\n", $stub);
    }

    public function test_rendered_stub_substitutes_alias_everywhere(): void
    {
        $rendered = AppPackager::renderedStub('kode.phar');

        $this->assertStringNotContainsString('<<ALIAS>>', $rendered);
        $this->assertStringContainsString("Phar::mapPhar('kode.phar')", $rendered);
        $this->assertStringContainsString("require 'phar://kode.phar/kode'", $rendered);
        $this->assertStringContainsString('__HALT_COMPILER()', $rendered);
    }

    public function test_rendered_stub_alias_is_plain_basename(): void
    {
        // 别名只取 basename——mapPhar 校验按文件名比对，带路径会在运行期报 implicit alias 冲突。
        $this->assertStringContainsString("Phar::mapPhar('app.phar')", AppPackager::renderedStub('app.phar'));
        $this->assertStringContainsString("require 'phar://app.phar/kode'", AppPackager::renderedStub('app.phar'));
    }

    // ── 5. 自动加载重写 ───────────────────────────────────────────────

    public function test_rewrite_autoload_drops_only_missing_entries(): void
    {
        $stage = $this->makeAutoloadFixture();

        $dropped = AppPackager::rewriteAutoloadFiles($stage);

        // 存在 → 保留；不存在 → 剔除。声明⟺实现零漂移落到自动加载器上。
        $droppedJoined = $this->join($dropped);
        $this->assertCount(2, $dropped);
        $this->assertStringContainsString('vendor/symfony/fc/Helper/deep_copy.php', $droppedJoined);
        $this->assertStringContainsString('app/Support/Gone.php', $droppedJoined);
        $this->assertStringNotContainsString('php_compat.php', $droppedJoined);
        $this->assertStringNotContainsString('Legacy.php', $droppedJoined);
        $this->assertStringNotContainsString('foo.php', $droppedJoined);

        $files = (array) include $stage . '/vendor/composer/autoload_files.php';
        $this->assertCount(4, $files, 'files 映射应只剩 4 条真实存在的条目');
        foreach ($files as $path) {
            $this->assertTrue(is_file($path), "autoload_files.php 里的路径必须真实可解析：{$path}");
        }

        $classmap = (array) include $stage . '/vendor/composer/autoload_classmap.php';
        $this->assertCount(1, $classmap);
        $this->assertArrayHasKey('Symfony\\Finder\\Finder', $classmap);
        $this->assertArrayNotHasKey('Symfony\\Fc\\Gone', $classmap);
        $this->assertTrue(is_file($classmap['Symfony\\Finder\\Finder']));
    }

    public function test_rewrite_autoload_remaps_project_absolute_paths(): void
    {
        $stage = $this->makeAutoloadFixture();
        AppPackager::rewriteAutoloadFiles($stage);

        $values = array_values((array) include $stage . '/vendor/composer/autoload_files.php');

        // composer 生成的是项目根绝对路径；重写后必须重映射回暂存目录，
        // 否则包内解析必然失效（暂存目录用完即删）。
        $this->assertStringNotContainsString(AppPackager::projectRoot(), $this->join($values));

        // 项目根那条在磁盘上并不存在（只有暂存目录里有 foo.php），
        // 所以能被保留下来就证明 remap 真的发生了。
        $resolved = array_filter(array_map('realpath', $values), static fn($p) => $p !== false);
        $this->assertContains(
            realpath($stage . '/vendor/foo.php'),
            $resolved,
            'remap 后的条目必须解析到暂存目录内的真实文件'
        );
        $this->assertFileExists($stage . '/vendor/foo.php');
    }

    public function test_rewrite_autoload_paths_are_constant_dir_expressions(): void
    {
        $stage = $this->makeAutoloadFixture();
        AppPackager::rewriteAutoloadFiles($stage);

        $src = (string) file_get_contents($stage . '/vendor/composer/autoload_files.php');
        $at = strpos($src, 'return array');
        $this->assertNotFalse($at, '重写产物必须含映射数组');
        $block = substr($src, $at);

        // 包内路径必须全是 __DIR__ 常量表达式——静态属性初始化不允许函数调用。
        $entries = 0;
        foreach (explode("\n", $block) as $line) {
            if (!preg_match("/^\s*'[^']*'\s*=>\s*(.+?),\s*$/", $line, $m)) {
                continue;
            }
            $entries++;
            $expr = trim($m[1]);
            if (str_contains($line, 'kode_pack_outside.php')) {
                continue;      // 包外路径刻意保留原值（见下一用例）
            }
            $this->assertStringStartsWith("__DIR__ . '", $expr, "应为 __DIR__ 常量表达式：{$line}");
            $this->assertStringNotContainsString('Phar::running', $expr);
            $this->assertStringNotContainsString('dirname(', $expr);
            $this->assertStringNotContainsString('realpath(', $expr);
            // 缺前导斜杠会得到 vendor/composer../symfony 这种粘连路径。
            $this->assertStringStartsWith("'/", substr($expr, strlen('__DIR__ . ')));
        }
        $this->assertSame(4, $entries);
        $this->assertStringNotContainsString('composer../', $src);
    }

    public function test_rewrite_autoload_keeps_out_of_package_path_verbatim(): void
    {
        $stage = $this->makeAutoloadFixture();
        AppPackager::rewriteAutoloadFiles($stage);

        $joined = $this->join(array_values((array) include $stage . '/vendor/composer/autoload_files.php'));

        // 包外路径（全局安装）不静默丢弃：保留原值，至少错误可见。
        $this->assertStringContainsString('kode_pack_outside.php', $joined);
    }

    public function test_rewrite_static_blocks_preserve_unrelated_blocks(): void
    {
        $stage = $this->makeAutoloadFixture();
        AppPackager::rewriteAutoloadFiles($stage);

        $src = (string) file_get_contents($stage . '/vendor/composer/autoload_static.php');

        // 无关块必须原样保留（psr4 映射用 __DIR__ 相对路径，无需重写）。
        $this->assertStringContainsString('public static $prefixLengthsPsr4 = array (', $src);
        $this->assertStringContainsString("'S' => array ('Symfony' => 7),", $src);
        $this->assertStringContainsString('public static $prefixDirsPsr4 = array (', $src);
        $this->assertStringContainsString('public static $missingClasses = array (', $src);
        $this->assertStringContainsString("'Symfony\\\\Finder\\\\Finder'", $src);
        // 被剔除的条目不得残留在 static 块里。
        $this->assertStringNotContainsString('deep_copy.php', $src);
        $this->assertStringNotContainsString('Gone.php', $src);
    }

    public function test_rewrite_static_is_loadable_with_rewritten_maps(): void
    {
        $stage = $this->makeAutoloadFixture();
        AppPackager::rewriteAutoloadFiles($stage);

        $static = $stage . '/vendor/composer/autoload_static.php';
        $this->assertFileExists($static);
        require $static;
        $this->assertTrue(class_exists('Composer\\autoload\\ComposerStaticInitPackTest'));

        $ref = new \ReflectionClass('Composer\\autoload\\ComposerStaticInitPackTest');

        $files = $ref->getProperty('files')->getDefaultValue();
        $this->assertCount(4, $files);
        foreach ($files as $path) {
            $this->assertTrue(is_file($path), "static \$files 条目必须可解析：{$path}");
        }

        $classMap = $ref->getProperty('classMap')->getDefaultValue();
        $this->assertSame(['Symfony\\Finder\\Finder'], array_keys($classMap));
        $this->assertTrue(is_file($classMap['Symfony\\Finder\\Finder']));

        // 无关块的值必须与写入前一致——重写只动 $files / $classMap 两个块。
        $this->assertSame(['S' => ['Symfony' => 7]], $ref->getProperty('prefixLengthsPsr4')->getDefaultValue());
        $this->assertSame(['Symfony' => [$stage . '/vendor/symfony']], $ref->getProperty('prefixDirsPsr4')->getDefaultValue());
    }

    public function test_rewrite_autoload_rejects_missing_composer_files(): void
    {
        $stage = $this->tempDir();
        $this->mkdir($stage . '/vendor/composer');
        file_put_contents($stage . '/vendor/composer/autoload_classmap.php', "<?php\n\nreturn array (\n);\n");

        try {
            AppPackager::rewriteAutoloadFiles($stage);
            $this->fail('缺少 autoload_files.php 必须抛异常，不能静默产出半残映射');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('autoload_files.php', $e->getMessage());
        }
    }

    public function test_rewrite_autoload_throws_on_undelimited_static_block(): void
    {
        $stage = $this->makeAutoloadFixture();
        // static 文件缺 $classMap 块——声明行找不到必须报错，不能产出无法加载的文件。
        file_put_contents(
            $stage . '/vendor/composer/autoload_static.php',
            "<?php\n\nnamespace Composer\\autoload;\n\nclass ComposerStaticInitBroken\n{\n"
            . "    public static \$files = array (\n        'a' => '/nope',\n    );\n}\n"
        );

        try {
            AppPackager::rewriteAutoloadFiles($stage);
            $this->fail('缺 $classMap 块必须抛异常');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('$classMap', $e->getMessage());
        }
    }

    public function test_rewrite_autoload_without_composer_dir_is_noop(): void
    {
        $this->assertSame([], AppPackager::rewriteAutoloadFiles($this->tempDir()));
    }

    // ── 6. 二进制档 ──────────────────────────────────────────────────

    public function test_ini_header_is_empty_for_blank_input(): void
    {
        $this->assertSame('', AppPackager::iniHeader(null));
        $this->assertSame('', AppPackager::iniHeader(''));
        $this->assertSame('', AppPackager::iniHeader("   \n\t"));
    }

    public function test_ini_header_is_magic_plus_big_endian_length_plus_body(): void
    {
        $magic = "\xfd\xf6\x69\xe6";
        $body = "memory_limit = -1\nmax_execution_time = 0";

        $header = AppPackager::iniHeader($body);

        $this->assertStringStartsWith($magic, $header);
        $this->assertSame(strlen($magic) + 4 + strlen($body), strlen($header));
        $this->assertSame(pack('N', strlen($body)), substr($header, strlen($magic), 4));
        $this->assertSame($body, substr($header, strlen($magic) + 4));
    }

    public function test_ini_header_normalizes_crlf(): void
    {
        $header = AppPackager::iniHeader("a=1\r\nb=2");

        // magic(4) + 长度(4) 之后才是正文。
        $body = substr($header, 8);
        $this->assertSame("a=1\nb=2", $body);
        $this->assertStringNotContainsString("\r", $body);
    }

    public function test_to_bin_concats_ini_sfx_and_phar_in_order(): void
    {
        $cfgDir = $this->tempDir();
        AppPackager::override(['build_dir' => $cfgDir, 'custom_ini' => "memory_limit = -1"]);

        $phar = $this->tempDir() . '/kode.phar';
        file_put_contents($phar, str_repeat('P', 1000));
        $sfx = $this->tempDir() . '/php8.3.micro.sfx';
        file_put_contents($sfx, str_repeat('S', 3000));

        // 显式给 sfx 即离线模式：绕过平台门禁（本机 arm64 macOS 无法产出真实二进制）。
        $result = AppPackager::toBin($phar, $sfx, '8.3');

        $iniBytes = 4 + 4 + strlen("memory_limit = -1");
        $this->assertTrue($result['ok'], $this->join($result['errors']));
        $this->assertSame($cfgDir . '/kode.bin', $result['artifact']);
        $this->assertFileExists($result['artifact']);
        $this->assertSame(4000 + $iniBytes, $result['bytes']);
        $this->assertSame(1000, $result['phar_bytes']);
        $this->assertSame(3000, $result['sfx_bytes']);
        $this->assertSame($iniBytes, $result['ini_bytes']);
        $this->assertSame('8.3', $result['php']);
        $this->assertSame(hash_file('sha256', $result['artifact']), $result['sha256']);

        // 拼接顺序：ini 头 → micro.sfx → phar。
        $bytes = (string) file_get_contents($result['artifact']);
        $this->assertSame(str_repeat('S', 3000), substr($bytes, $iniBytes, 3000));
        $this->assertSame(str_repeat('P', 1000), substr($bytes, -1000));
    }

    public function test_to_bin_without_custom_ini_has_no_header(): void
    {
        $cfgDir = $this->tempDir();
        AppPackager::override(['build_dir' => $cfgDir, 'custom_ini' => null]);

        $phar = $this->tempDir() . '/kode.phar';
        file_put_contents($phar, 'PHAR');
        $sfx = $this->tempDir() . '/sfx';
        file_put_contents($sfx, 'SFX');

        $result = AppPackager::toBin($phar, $sfx);

        $this->assertTrue($result['ok'], $this->join($result['errors']));
        $this->assertSame(0, $result['ini_bytes']);
        $this->assertSame(7, $result['bytes']);
    }

    public function test_to_bin_reports_missing_inputs(): void
    {
        $missingPhar = AppPackager::toBin('/no/such/kode.phar', $this->tempDir() . '/sfx');
        $this->assertFalse($missingPhar['ok']);
        $this->assertNull($missingPhar['artifact']);
        $this->assertStringContainsString('PHAR 不存在', $this->join($missingPhar['errors']));

        $sfx = $this->tempDir() . '/sfx';
        file_put_contents($sfx, 'S');
        $phar = $this->tempDir() . '/kode.phar';
        file_put_contents($phar, 'P');
        $missingSfx = AppPackager::toBin($phar, '/no/such/sfx');
        $this->assertFalse($missingSfx['ok']);
        $this->assertStringContainsString('micro.sfx 不存在', $this->join($missingSfx['errors']));
    }

    // ── 7. 校验与报告 ────────────────────────────────────────────────

    public function test_verify_rejects_missing_file(): void
    {
        $result = AppPackager::verify('/no/such/artifact.phar');

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['checks']);
        $this->assertStringContainsString('文件不存在', $this->join($result['errors']));
    }

    public function test_verify_rejects_forged_phar(): void
    {
        $forged = $this->tempDir() . '/forged.phar';
        file_put_contents($forged, "<?php\n\necho 'not a phar';\n");

        $result = AppPackager::verify($forged);

        $this->assertFalse($result['ok']);
        $this->assertSame('phar', $result['checks']['tier']);
        $this->assertTrue($result['checks']['exists']);
        $this->assertSame((string) filesize($forged), $result['checks']['bytes']);
        $this->assertStringContainsString('不是有效 PHAR', $this->join($result['errors']));
    }

    public function test_verify_accepts_real_phar_artifact(): void
    {
        $artifact = AppPackager::buildDir() . '/kode.phar';
        if (!is_file($artifact)) {
            $this->markTestSkipped('build/kode.phar 不存在（先执行 pack:phar 构建）');
        }

        $result = AppPackager::verify($artifact);

        $this->assertTrue($result['ok'], '真实产物必须通过校验：' . $this->join($result['errors']));
        $this->assertSame('phar', $result['checks']['tier']);
        $this->assertSame('kode.phar', $result['checks']['alias']);
        $this->assertSame('kode.phar', $result['checks']['internal_alias']);
        $this->assertTrue($result['checks']['has_halt_compiler']);
        $this->assertTrue($result['checks']['stub_maps_alias']);
        $this->assertSame(hash_file('sha256', $artifact), $result['checks']['sha256']);
        $this->assertGreaterThan(1000, (int) $result['checks']['files'], '全量产物应为数千个文件');

        foreach (['kode', 'vendor/autoload.php', 'vendor/kode/framework/kode', 'public/index.php', 'config/app.php'] as $need) {
            $this->assertTrue($result['checks']["has:{$need}"], "产物缺必备入口：{$need}");
        }
    }

    public function test_verify_treats_bin_filename_as_binary_tier(): void
    {
        AppPackager::override(['bin_filename' => 'kode.bin']);
        $dir = $this->tempDir();

        $withIni = $dir . '/kode.ini.bin';
        file_put_contents($withIni, "\xfd\xf6\x69\xe6" . pack('N', 3) . 'abc' . 'tail');
        $this->renameForBinTier($withIni, $dir);
        $result = AppPackager::verify($dir . '/kode.bin');
        $this->assertTrue($result['ok'], $this->join($result['errors']));
        $this->assertSame('binary', $result['checks']['tier']);
        $this->assertTrue($result['checks']['has_ini_header']);
        $this->assertTrue($result['checks']['executable_head']);

        file_put_contents($dir . '/kode.bin', "\x7fELF" . str_repeat("\0", 40));
        $result = AppPackager::verify($dir . '/kode.bin');
        $this->assertTrue($result['ok'], '纯 ELF 二进制同样应判为可执行体：' . $this->join($result['errors']));
        $this->assertFalse($result['checks']['has_ini_header']);
        $this->assertTrue($result['checks']['executable_head']);

        file_put_contents($dir . '/kode.bin', 'this is not an executable at all');
        $result = AppPackager::verify($dir . '/kode.bin');
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['checks']['executable_head']);
        $this->assertStringContainsString('既无 custom-ini 魔数', $this->join($result['errors']));
    }

    public function test_verify_binary_tier_skips_phar_semantics(): void
    {
        // 二进制档不是 phar 容器：绝不能要求 __HALT_COMPILER、mapPhar 别名或 must_include。
        AppPackager::override(['bin_filename' => 'kode.bin', 'must_include' => ['kode', 'vendor/autoload.php']]);
        $bin = $this->tempDir() . '/kode.bin';
        file_put_contents($bin, "\xfd\xf6\x69\xe6" . pack('N', 0) . str_repeat('X', 512));

        $result = AppPackager::verify($bin);

        $this->assertTrue($result['ok'], $this->join($result['errors']));
        $this->assertArrayNotHasKey('has_halt_compiler', $result['checks']);
        $this->assertArrayNotHasKey('stub_maps_alias', $result['checks']);
        // must_include 里声明的入口完全没被检查——这正是二进制档应有的行为。
        $this->assertArrayNotHasKey('has:kode', $result['checks']);
        $this->assertArrayNotHasKey('has:vendor/autoload.php', $result['checks']);
        $this->assertArrayHasKey('executable_head', $result['checks']);
    }

    public function test_report_renders_failure(): void
    {
        $text = AppPackager::report(['ok' => false, 'errors' => ['phar.readonly=1', '第二项']]);

        $this->assertStringContainsString('打包失败', $text);
        $this->assertStringContainsString('  - phar.readonly=1', $text);
        $this->assertStringContainsString('  - 第二项', $text);
    }

    public function test_report_renders_phar_success(): void
    {
        $text = AppPackager::report([
            'ok' => true, 'errors' => [], 'artifact' => '/tmp/build/kode.phar',
            'bytes' => 26295980, 'sha256' => 'abc123', 'files' => 4972,
            'signature' => \Phar::SHA256, 'dropped_files' => [], 'memory_limit' => '512M',
        ]);

        $this->assertStringContainsString('打包成功', $text);
        $this->assertStringContainsString('/tmp/build/kode.phar', $text);
        $this->assertStringContainsString('25.08 MB', $text);
        $this->assertStringContainsString('4972', $text);
        $this->assertStringNotContainsString('micro.sfx', $text, 'phar 档报告不应出现二进制构成');
    }

    public function test_report_renders_binary_success(): void
    {
        $text = AppPackager::report([
            'ok' => true, 'errors' => [], 'artifact' => '/tmp/build/kode.bin',
            'bytes' => 30000000, 'sha256' => 'def456',
            'phar_bytes' => 26295980, 'sfx_bytes' => 3000000, 'ini_bytes' => 60, 'php' => '8.3',
        ]);

        $this->assertStringContainsString('/tmp/build/kode.bin', $text);
        $this->assertStringContainsString('micro.sfx', $text);
        $this->assertStringContainsString('+ ini 60B', $text);
        $this->assertStringContainsString('8.3', $text);
    }

    public function test_human_bytes_rounds_to_two_decimals(): void
    {
        foreach ([
            0 => '0 B',
            1 => '1 B',
            512 => '512 B',
            1023 => '1023 B',
            1024 => '1 KB',
            1536 => '1.5 KB',
            1048576 => '1 MB',
            26295980 => '25.08 MB',
            5368709120 => '5 GB',
        ] as $bytes => $expected) {
            $this->assertSame($expected, AppPackager::humanBytes($bytes), "humanBytes({$bytes})");
        }
    }

    // ── 8. stage() 契约 ──────────────────────────────────────────────

    public function test_stage_copies_only_selected_files(): void
    {
        AppPackager::override(['include_dirs' => [], 'include_files' => [], 'exclude_dirs' => []]);
        $root = $this->tempDir();

        [$stage, $paths, $dropped] = AppPackager::stage($root);

        try {
            $this->assertSame([], $paths, '空白名单不应复制任何文件');
            $this->assertSame([], $dropped);
            $this->assertDirectoryExists($stage);
            $this->assertSame([], array_diff(scandir($stage), ['.', '..']), '暂存目录必须为空');
        } finally {
            self::rrmdir($stage);
        }
    }

    public function test_stage_returns_three_element_contract(): void
    {
        AppPackager::override([
            'include_dirs' => ['config'],
            'include_files' => ['kode'],
            'exclude_dirs' => [],
        ]);
        $root = $this->tempDir();

        [$stage, $paths, $dropped] = AppPackager::stage($root);

        try {
            $this->assertIsArray($paths);
            $this->assertIsArray($dropped);
            $this->assertContains('config/app.php', $paths);
            $this->assertContains('kode', $paths);
            // 复制必须真的发生，且内容一致。
            $this->assertFileEquals(AppPackager::projectRoot() . '/kode', $stage . '/kode');
            $this->assertFileEquals(AppPackager::projectRoot() . '/config/app.php', $stage . '/config/app.php');
            // 未选中的目录不得被复制。
            $this->assertDirectoryDoesNotExist($stage . '/tests');
            $this->assertDirectoryDoesNotExist($stage . '/storage');
        } finally {
            self::rrmdir($stage);
        }
    }

    // ── 辅助 ─────────────────────────────────────────────────────────

    /**
     * 临时目录（tearDown 统一清理）。
     *
     * 必须返回已解析（realpath）路径：composer 生成的自动加载映射里都是绝对且已解析的路径，
     * macOS 下 sys_get_temp_dir() 返回未解析的 /var/...，而 realpath 是 /private/var/...——
     * 两种形态前缀不匹配会让 pathExpr/remapToStage 的匹配静默失效（AppPackager 注释里踩过的坑）。
     */
    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/kode_pack_test_' . bin2hex(random_bytes(4));
        if (!@mkdir($dir, 0755, true)) {
            $this->fail("无法创建临时目录：{$dir}");
        }
        $this->tempDirs[] = $dir;
        $resolved = realpath($dir);

        return $resolved === false ? $dir : $resolved;
    }

    private function mkdir(string $dir): void
    {
        if (!@mkdir($dir, 0755, true)) {
            $this->fail("无法创建目录：{$dir}");
        }
    }

    private function join(array $items): string
    {
        return implode('; ', array_map('strval', $items));
    }

    /** 把夹具文件改名为二进制档名（verify 按 basename 分档）。 */
    private function renameForBinTier(string $from, string $dir): void
    {
        @rename($from, $dir . '/kode.bin');
    }

    /**
     * 构造最小 autoload 夹具：
     *   存在：vendor/symfony/fc/Helper/php_compat.php、vendor/symfony/finder/Finder.php、
     *         vendor/foo.php（remap 目标）、app/Support/Legacy.php、包外 kode_pack_outside.php
     *   缺失：vendor/symfony/fc/Helper/deep_copy.php、app/Support/Gone.php
     * 三张映射里各混入缺失条目，验证「按实际文件集剔除」。
     *
     * @return string 暂存目录
     */
    private function makeAutoloadFixture(): string
    {
        $stage = $this->tempDir();
        $composer = $stage . '/vendor/composer';
        $this->mkdir($composer);
        $this->mkdir($stage . '/vendor/symfony/fc/Helper');
        $this->mkdir($stage . '/vendor/symfony/finder');
        $this->mkdir($stage . '/app/Support');

        file_put_contents($stage . '/vendor/symfony/fc/Helper/php_compat.php', '<?php');
        file_put_contents($stage . '/vendor/symfony/finder/Finder.php', '<?php');
        file_put_contents($stage . '/vendor/foo.php', '<?php');
        file_put_contents($stage . '/app/Support/Legacy.php', '<?php');
        $outside = sys_get_temp_dir() . '/kode_pack_outside.php';
        file_put_contents($outside, '<?php');

        // composer 风格：files 映射里既有暂存内路径，也有一条项目根绝对路径（验证 remap）。
        file_put_contents($composer . '/autoload_files.php', "<?php\n\n"
            . "return array (\n"
            . "    'php_compat' => '{$stage}/vendor/symfony/fc/Helper/php_compat.php',\n"
            . "    'deep_copy' => '{$stage}/vendor/symfony/fc/Helper/deep_copy.php',\n"
            . "    'outside' => '{$outside}',\n"
            . "    'from_root' => '" . AppPackager::projectRoot() . "/vendor/foo.php',\n"
            . "    'legacy' => '{$stage}/app/Support/Legacy.php',\n"
            . ");\n");

        file_put_contents($composer . '/autoload_classmap.php', "<?php\n\n"
            . "return array (\n"
            . "    'Symfony\\\\Finder\\\\Finder' => '{$stage}/vendor/symfony/finder/Finder.php',\n"
            . "    'Symfony\\\\Fc\\\\Gone' => '{$stage}/app/Support/Gone.php',\n"
            . ");\n");

        file_put_contents($composer . '/autoload_static.php', "<?php\n\n"
            . "namespace Composer\\autoload;\n\n"
            . "class ComposerStaticInitPackTest\n"
            . "{\n"
            . "    public static \$files = array (\n"
            . "        'php_compat' => '{$stage}/vendor/symfony/fc/Helper/php_compat.php',\n"
            . "        'deep_copy' => '{$stage}/vendor/symfony/fc/Helper/deep_copy.php',\n"
            . "    );\n"
            . "    public static \$prefixLengthsPsr4 = array (\n"
            . "        'S' => array ('Symfony' => 7),\n"
            . "    );\n"
            . "    public static \$prefixDirsPsr4 = array (\n"
            . "        'Symfony' => array (0 => '{$stage}/vendor/symfony'),\n"
            . "    );\n"
            . "    public static \$classMap = array (\n"
            . "        'Symfony\\\\Finder\\\\Finder' => '{$stage}/vendor/symfony/finder/Finder.php',\n"
            . "        'Symfony\\\\Fc\\\\Gone' => '{$stage}/app/Support/Gone.php',\n"
            . "    );\n"
            . "    public static \$missingClasses = array (\n"
            . ");\n"
            . "}\n");

        return $stage;
    }

    /** 递归删除目录（测试私有，@ 抑制不存在/权限噪声）。 */
    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $ri = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($ri, \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $path = $f->getPathname();
            if ($f->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
