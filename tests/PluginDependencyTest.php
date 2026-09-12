<?php

declare(strict_types=1);

namespace app\tests;

use app\support\PluginContract;
use app\support\PluginDependency;
use app\support\PluginMarket;
use Kode\Framework\Plugin\PluginInterface;
use PHPUnit\Framework\TestCase;

/**
 * 插件依赖引擎测试（requires() 的消费端闭环）。
 *
 * 覆盖六件事：
 *  1. 版本约束语义——>=、^、~、精确、* 五种约束的边界判定；
 *  2. requires() 归一——字符串与 array{name,version?} 双格式 + 非法元素静默丢弃；
 *  3. 依赖图——传递闭包、反向依赖者、环检测（用临时插件文件 + flush 真实验证）；
 *  4. validate() 门禁——缺失/未启用/版本不满足三种拦截；
 *  5. auditRequires() 契约审计——非法格式记为漂移；
 *  6. 版本语法 SoT——isValidVersion() / isValidConstraint() 全语法矩阵，
 *     以及「satisfies() 静默失败必须被 isValidConstraint() 抓住」的对账用例。
 *
 * 隔离性：第 3、4 组用临时插件文件（plugins/_DepProbe*.php），tearDown 删除文件
 * 并 flush 两个引擎的静态缓存，不留脏状态。
 */
final class PluginDependencyTest extends TestCase
{
    /** @var list<string> 本测试创建的临时插件文件路径 */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        \Kode\Framework\Application::make(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
        PluginMarket::flush();
        PluginDependency::flush();
    }

    /**
     * 写一个临时插件文件到 plugins/，返回插件名。
     *
     * $pluginName 决定 name() 返回值（如 dep_probe_a），
     * 文件名固定为 {$pluginName}Plugin.php，类名同名以匹配 PSR-4 自动加载。
     */
    private function writeTempPlugin(string $pluginName, string $requiresBody): string
    {
        $className = $pluginName . 'Plugin';
        $path = dirname(__DIR__) . '/plugins/' . $className . '.php';
        $code = <<<PHP
<?php
declare(strict_types=1);

namespace Kode\\Plugins;

use Kode\\Framework\\Plugin\\PluginInterface;
use Kode\\Framework\\Plugin\\PluginManager;

final class {$className} implements PluginInterface
{
    public function name(): string { return '{$pluginName}'; }
    public function register(PluginManager \$manager): void {}
    public function boot(PluginManager \$manager): void {}
    public function version(): string { return '1.0.0'; }
    public function requires(): array { return {$requiresBody}; }
}
PHP;
        file_put_contents($path, $code);
        $this->tempFiles[] = $path;

        return $pluginName;
    }

    // ─── 1. 版本约束语义 ───────────────────────────────────────────

    public function test_satisfies_gte(): void
    {
        self::assertTrue(PluginDependency::satisfies('1.2.3', '>=1.0'));
        self::assertTrue(PluginDependency::satisfies('1.0.0', '>=1.0'));
        self::assertFalse(PluginDependency::satisfies('0.9.9', '>=1.0'));
        self::assertTrue(PluginDependency::satisfies('1.2.3', '>= 1.0.0'), '带空格');
    }

    public function test_satisfies_caret(): void
    {
        // ^1.0 → >=1.0, <2.0
        self::assertTrue(PluginDependency::satisfies('1.0.0', '^1.0'));
        self::assertTrue(PluginDependency::satisfies('1.9.9', '^1.0'));
        self::assertFalse(PluginDependency::satisfies('2.0.0', '^1.0'));
        self::assertFalse(PluginDependency::satisfies('0.9.9', '^1.0'));
        // ^0.2 → >=0.2, <0.3（0.x 时次版本固定）
        self::assertTrue(PluginDependency::satisfies('0.2.5', '^0.2'));
        self::assertFalse(PluginDependency::satisfies('0.3.0', '^0.2'));
        self::assertFalse(PluginDependency::satisfies('0.1.9', '^0.2'));
    }

    public function test_satisfies_tilde(): void
    {
        // ~1.2 → >=1.2, <1.3
        self::assertTrue(PluginDependency::satisfies('1.2.0', '~1.2'));
        self::assertTrue(PluginDependency::satisfies('1.2.9', '~1.2'));
        self::assertFalse(PluginDependency::satisfies('1.3.0', '~1.2'));
        self::assertFalse(PluginDependency::satisfies('1.1.9', '~1.2'));
    }

    public function test_satisfies_exact_and_wildcard(): void
    {
        self::assertTrue(PluginDependency::satisfies('1.2.3', '1.2.3'));
        self::assertFalse(PluginDependency::satisfies('1.2.4', '1.2.3'));
        self::assertTrue(PluginDependency::satisfies('9.9.9', '*'));
        self::assertTrue(PluginDependency::satisfies('9.9.9', null));
        self::assertTrue(PluginDependency::satisfies('9.9.9', ''));
        // v 前缀容忍
        self::assertTrue(PluginDependency::satisfies('v1.2.3', '1.2.3'));
    }

    // ─── 2. requires() 归一 ────────────────────────────────────────

    public function test_requirements_normalizes_string_and_array_forms(): void
    {
        $plugin = new class implements PluginInterface {
            public function name(): string { return 'probe'; }
            public function register(\Kode\Framework\Plugin\PluginManager $m): void {}
            public function boot(\Kode\Framework\Plugin\PluginManager $m): void {}
            public function requires(): array
            {
                return [
                    'storage_driver',
                    ['name' => 'notices', 'version' => '^1.0'],
                    '  demo  ', // 前后空格应被 trim
                ];
            }
        };

        $reqs = PluginDependency::requirements($plugin);
        self::assertCount(3, $reqs);
        self::assertSame(['name' => 'storage_driver', 'version' => null], $reqs[0]);
        self::assertSame(['name' => 'notices', 'version' => '^1.0'], $reqs[1]);
        self::assertSame(['name' => 'demo', 'version' => null], $reqs[2]);
    }

    public function test_requirements_drops_invalid_elements(): void
    {
        $plugin = new class implements PluginInterface {
            public function name(): string { return 'probe'; }
            public function register(\Kode\Framework\Plugin\PluginManager $m): void {}
            public function boot(\Kode\Framework\Plugin\PluginManager $m): void {}
            public function requires(): array
            {
                return [
                    '',            // 空串 → 丢弃
                    42,            // 非字符串非数组 → 丢弃
                    ['version' => '^1.0'], // 缺 name → 丢弃
                    ['name' => ''],       // 空 name → 丢弃
                    'Bad-Name',   // 非法名（大写+连字符）→ 丢弃
                    'a',          // 太短（1 字符）→ 丢弃
                ];
            }
        };

        self::assertSame([], PluginDependency::requirements($plugin));
    }

    public function test_requirements_no_method_returns_empty(): void
    {
        $plugin = new class implements PluginInterface {
            public function name(): string { return 'probe'; }
            public function register(\Kode\Framework\Plugin\PluginManager $m): void {}
            public function boot(\Kode\Framework\Plugin\PluginManager $m): void {}
        };
        self::assertSame([], PluginDependency::requirements($plugin));
    }

    // ─── 3. 依赖图：闭包 / 反向 / 环 ──────────────────────────────

    public function test_transitive_closure_and_dependents(): void
    {
        // dep_probe_a → dep_probe_b → dep_probe_c
        $this->writeTempPlugin('dep_probe_a', "['dep_probe_b']");
        $this->writeTempPlugin('dep_probe_b', "['dep_probe_c']");
        $this->writeTempPlugin('dep_probe_c', '[]');
        PluginMarket::flush();
        PluginDependency::flush();

        // 传递闭包：a → [b, c]
        self::assertSame(['dep_probe_b', 'dep_probe_c'], PluginDependency::closure('dep_probe_a'));
        self::assertSame([], PluginDependency::closure('dep_probe_c'));

        // 反向：c 的依赖者 = [b, a]（transitive）
        $dependentsOfC = PluginDependency::dependents('dep_probe_c');
        self::assertContains('dep_probe_b', $dependentsOfC);
        self::assertContains('dep_probe_a', $dependentsOfC);
        self::assertNotContains('dep_probe_c', $dependentsOfC, '不应包含自身');

        // b 的依赖者 = [a]
        self::assertSame(['dep_probe_a'], PluginDependency::dependents('dep_probe_b'));

        // 无依赖者的末端节点
        self::assertSame([], PluginDependency::dependents('dep_probe_a'));
    }

    public function test_cycle_detection(): void
    {
        // a → b → c → a（环）
        $this->writeTempPlugin('dep_cycle_a', "['dep_cycle_b']");
        $this->writeTempPlugin('dep_cycle_b', "['dep_cycle_c']");
        $this->writeTempPlugin('dep_cycle_c', "['dep_cycle_a']");
        PluginMarket::flush();
        PluginDependency::flush();

        // closure 不应无限递归（环检测生效）
        $closure = PluginDependency::closure('dep_cycle_a');
        self::assertNotEmpty($closure);
        // 每个节点最多出现一次
        self::assertSame(count($closure), count(array_unique($closure)));

        // hasCycle 应检测到环
        self::assertTrue(PluginDependency::hasCycle('dep_cycle_a'));
        self::assertTrue(PluginDependency::hasCycle('dep_cycle_b'));
        self::assertTrue(PluginDependency::hasCycle('dep_cycle_c'));

        // 无环的节点
        $this->writeTempPlugin('dep_nocycle', '[]');
        PluginMarket::flush();
        PluginDependency::flush();
        self::assertFalse(PluginDependency::hasCycle('dep_nocycle'));
    }

    // ─── 4. validate() 门禁 ───────────────────────────────────────

    public function test_validate_passes_when_no_dependencies(): void
    {
        $r = PluginDependency::validate('demo');
        self::assertTrue($r['ok']);
        self::assertSame([], $r['missing']);
        self::assertSame([], $r['notEnabled']);
        self::assertSame([], $r['versionMismatch']);
    }

    public function test_validate_reports_missing_dependency(): void
    {
        // a 依赖一个不存在的插件 ghost_plugin
        $this->writeTempPlugin('dep_missing', "['ghost_plugin']");
        PluginMarket::flush();
        PluginDependency::flush();

        $r = PluginDependency::validate('dep_missing');
        self::assertFalse($r['ok']);
        self::assertSame(['ghost_plugin'], $r['missing']);
        self::assertSame([], $r['notEnabled']);
    }

    public function test_validate_reports_version_mismatch(): void
    {
        // a 依赖 b，b 要求 ^2.0，但 b 的版本是 1.0.0
        $this->writeTempPlugin('dep_ver_a', "[['name'=>'dep_ver_b', 'version'=>'^2.0']]");
        $this->writeTempPlugin('dep_ver_b', '[]');
        PluginMarket::flush();
        PluginDependency::flush();

        // 先把 b 登记为 enabled（version 1.0.0）
        PluginMarket::setStatus('dep_ver_b', PluginMarket::ENABLED);
        PluginMarket::setVersion('dep_ver_b', '1.0.0');

        $r = PluginDependency::validate('dep_ver_a');
        self::assertFalse($r['ok']);
        self::assertSame([], $r['missing']);
        self::assertSame([], $r['notEnabled']);
        self::assertNotEmpty($r['versionMismatch']);
        self::assertStringContainsString('dep_ver_b', $r['versionMismatch'][0]);
        self::assertStringContainsString('^2.0', $r['versionMismatch'][0]);
    }

    public function test_validate_reports_not_enabled(): void
    {
        $this->writeTempPlugin('dep_pause_a', "['dep_pause_b']");
        $this->writeTempPlugin('dep_pause_b', '[]');
        PluginMarket::flush();
        PluginDependency::flush();

        // b 被暂停
        PluginMarket::setStatus('dep_pause_b', PluginMarket::PAUSED);

        $r = PluginDependency::validate('dep_pause_a');
        self::assertFalse($r['ok']);
        self::assertSame([], $r['missing']);
        self::assertNotEmpty($r['notEnabled']);
        self::assertStringContainsString('dep_pause_b', $r['notEnabled'][0]);
    }

    // ─── 5. auditRequires() 契约审计 ─────────────────────────────

    public function test_audit_requires_catches_invalid_formats(): void
    {
        $plugin = new class implements PluginInterface {
            public function name(): string { return 'probe'; }
            public function register(\Kode\Framework\Plugin\PluginManager $m): void {}
            public function boot(\Kode\Framework\Plugin\PluginManager $m): void {}
            public function requires(): array
            {
                return [
                    '',                 // [0] 空串 → 漂移
                    42,                 // [1] 非字符串非数组 → 漂移
                    ['version' => '^1'], // [2] 缺 name → 漂移
                    ['name' => 'Bad-Name'], // [3] 非法名 → 漂移
                ];
            }
        };

        $drift = PluginContract::auditRequires($plugin);
        self::assertCount(4, $drift, implode('；', $drift));
        $joined = implode("\n", $drift);
        self::assertStringContainsString('[0]', $joined);
        self::assertStringContainsString('[1]', $joined);
        self::assertStringContainsString('[2]', $joined);
        self::assertStringContainsString('Bad-Name', $joined);
    }

    public function test_audit_requires_clean_for_valid_declarations(): void
    {
        $plugin = new class implements PluginInterface {
            public function name(): string { return 'probe'; }
            public function register(\Kode\Framework\Plugin\PluginManager $m): void {}
            public function boot(\Kode\Framework\Plugin\PluginManager $m): void {}
            public function requires(): array
            {
                return [
                    'storage_driver',
                    ['name' => 'notices', 'version' => '^1.0'],
                ];
            }
        };

        self::assertSame([], PluginContract::auditRequires($plugin));
    }

    public function test_audit_requires_empty_and_no_method(): void
    {
        $empty = new class implements PluginInterface {
            public function name(): string { return 'probe'; }
            public function register(\Kode\Framework\Plugin\PluginManager $m): void {}
            public function boot(\Kode\Framework\Plugin\PluginManager $m): void {}
            public function requires(): array { return []; }
        };
        self::assertSame([], PluginContract::auditRequires($empty));

        $noMethod = new class implements PluginInterface {
            public function name(): string { return 'probe'; }
            public function register(\Kode\Framework\Plugin\PluginManager $m): void {}
            public function boot(\Kode\Framework\Plugin\PluginManager $m): void {}
        };
        self::assertSame([], PluginContract::auditRequires($noMethod));
    }

    public function test_real_plugins_audit_requires_clean(): void
    {
        $classes = PluginMarket::discover();
        foreach ($classes as $class) {
            $plugin = new $class();
            $drift = PluginContract::auditRequires($plugin);
            self::assertSame([], $drift, $class . '：' . implode('；', $drift));
        }
    }

    // ─── 6. 版本语法 SoT：isValidVersion / isValidConstraint ────────

    public static function versionGrammarProvider(): array
    {
        return [
            '三段式'       => ['1.0.0', true],
            'v 前缀'       => ['v1.2.3', true],
            '单段'         => ['1', true],
            '两段'         => ['1.2', true],
            '四段'         => ['1.2.3.4', true],
            '预发布'       => ['1.0.0-beta.1', true],
            '构建元数据'   => ['1.0.0+build.5', true],
            '预发布+构建'  => ['v2.0.0-rc.1+meta', true],
            '空串'         => ['', true],
            '纯空白'       => ['   ', true],
            '纯文本'       => ['abc', false],
            '通配 x'       => ['1.0.x', false],
            '频道标记'     => ['@stable', false],
            '通配星号'     => ['1.0.*', false],
            '缺版本号'     => ['1..2', false],
            '五段超限'     => ['1.0.0.0.0', false],
            'v 后无数字'   => ['v.v', false],
            '点号重复'     => ['1.0..2', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('versionGrammarProvider')]
    public function test_is_valid_version_grammar(string $version, bool $expected): void
    {
        self::assertSame($expected, PluginDependency::isValidVersion($version),
            'isValidVersion(' . json_encode($version) . ')');
    }

    public static function constraintGrammarProvider(): array
    {
        return [
            'null'             => [null, true],
            '空串'             => ['', true],
            '通配星号'         => ['*', true],
            '通配小写 x'       => ['x', true],
            '通配大写 X'       => ['X', true],
            '大于等于'         => ['>=1.0', true],
            '小于等于'         => ['<=2.0.0', true],
            '大于'             => ['>1.0', true],
            '小于'             => ['<2.0', true],
            '双等'             => ['==1.2.3', true],
            '不等'             => ['!=1.2.3', true],
            '单等'             => ['=1.0', true],
            'caret'            => ['^1.0', true],
            'tilde'            => ['~1.2', true],
            '精确匹配'         => ['1.2.3', true],
            'v 前缀精确'       => ['v1.2.3', true],
            '比较后带空格'     => ['>= 1.0', true],
            'caret 后带空格'   => ['^ 1.0', true],
            '区间逗号写法'     => ['>=1.0, <2.0', false],
            '通配 x'           => ['1.0.x', false],
            '频道标记'         => ['@stable', false],
            '通配星号'         => ['1.0.*', false],
            '箭头运算符'       => ['=>1.0', false],
            '双大于'           => ['>>1.0', false],
            '孤立等号'         => ['=', false],
            '孤立 caret'       => ['^', false],
            '孤立 tilde'       => ['~', false],
            '非版本右值'       => ['>=abc', false],
            '任意文本'         => ['not a version', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('constraintGrammarProvider')]
    public function test_is_valid_constraint_grammar(string|null $constraint, bool $expected): void
    {
        self::assertSame($expected, PluginDependency::isValidConstraint($constraint),
            'isValidConstraint(' . json_encode($constraint) . ')');
    }

    /**
     * 词表与判定必须同源：constraintSyntax() 列出的每个形态都真的能通过
     * isValidConstraint()——否则审计报错信息会让插件作者照抄一份非法写法。
     */
    public function test_constraint_syntax_forms_are_all_parseable(): void
    {
        $forms = ['*', '>=1.0', '<=1.0', '>1.0', '<1.0', '==1.0', '!=1.0', '^1.0', '~1.2', '1.2.3'];
        $joined = implode(' | ', PluginDependency::constraintSyntax());

        foreach ($forms as $form) {
            self::assertTrue(PluginDependency::isValidConstraint($form), "词表示例 {$form} 必须合法");
            self::assertStringContainsString($form, $joined, "constraintSyntax() 必须列出 {$form}");
        }
        self::assertCount(5, PluginDependency::constraintSyntax(), '词表形态数与 satisfies() 分支数对应');
    }

    /**
     * 引擎的静默失败证据（不修 satisfies()、由审计兜住的真实 bug）。
     *
     * satisfies() 的区间分支只捕获运算符后的第一段，逗号后半段被丢弃；
     * 无法解析的表达式则落精确匹配兜底。两类静默错误都会让「声明的语义」
     * 与「真实执行结果」不一致，且运行期无任何报错——这正是阶段 4.5 必须
     * 判漂移的原因。
     *
     * 此用例同时固定住引擎现状：若有人「顺手改好」satisfies()，本断言会失败，
     * 提示必须重新审视审计语义（fail-closed 还是解析后 AND）是否仍成立。
     */
    public function test_satisfies_silent_failures_are_gated_by_is_valid_constraint(): void
    {
        // [version, constraint, satisfies() 实际返回]
        $cases = [
            ['2.5.0', '>=1.0, <2.0', true],   // <2.0 上界被逗号丢弃 → 误通过
            ['2.0.0', '1.0.x', false],         // 落精确匹配 → 误拒
            ['1.0.0', '@stable', false],
            ['3.1.0', '1.0.*', false],
        ];

        foreach ($cases as [$version, $constraint, $engineResult]) {
            self::assertSame($engineResult, PluginDependency::satisfies($version, $constraint),
                "satisfies({$version}, {$constraint}) 引擎行为不得漂移");
            self::assertFalse(PluginDependency::isValidConstraint($constraint),
                "审计必须把「{$constraint}」判为非法，否则门禁形同虚设");
        }
    }
}
