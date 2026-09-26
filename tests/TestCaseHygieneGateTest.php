<?php

declare(strict_types=1);

namespace app\tests;

use PHPUnit\Framework\TestCase;

/**
 * 静态门禁：继承框架测试基类（`Kode\Framework\Testing\TestCase`）的用例，
 * 覆盖 setUp/tearDown 时必须调用父类实现。
 *
 * 基类把「已启动的应用」缓存在 `private static $app`，并在 `tearDown()` 里清空；
 * `bootApp()` 见到缓存就直接返回。所以只要有一个子类把 tearDown 整个盖掉，
 * 缓存就带着一个「核心单例已被后一个类重置掉」的陈应用传给下一个测试类——
 * 表现为随机的「服务容器尚未启动」，且**单跑那个类永远复现不了**（本仓真踩过）。
 *
 * 这类串扰靠 review 抓不住，只能靠门：token 级扫方法体，注释里的 "parent::tearDown" 不算。
 */
final class TestCaseHygieneGateTest extends TestCase
{
    public function test_framework_test_cases_always_call_the_parent_hook(): void
    {
        $offenders = [];

        foreach (glob(dirname(__DIR__) . '/tests/*Test.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            if (!str_contains($src, 'Kode\Framework\Testing\TestCase')) {
                continue; // 只吃框架基类：纯 PHPUnit 用例没有进程级缓存要清
            }
            foreach ($this->hookBodies($src) as $hook => $body) {
                if (!str_contains($body, 'parent::' . $hook)) {
                    $offenders[] = basename($file) . '::' . $hook . '() 未调用 parent::' . $hook . '()';
                }
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * 取出 setUp/tearDown 的方法体（大括号配对，注释与字符串里的括号不计）。
     *
     * @return array<string, string> 钩子名 => 方法体源码
     */
    private function hookBodies(string $src): array
    {
        $out = [];
        $tokens = token_get_all($src);
        $total = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $name = null;
            for ($j = $i + 1; $j < $total; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $name = $tokens[$j][1];
                }
                break;
            }
            if ($name !== 'setUp' && $name !== 'tearDown') {
                continue;
            }
            // 找到方法体的开括号
            $depth = 0;
            $started = false;
            $body = '';
            for (; $i < $total; $i++) {
                $t = $tokens[$i];
                // 双引号串里的复杂插值要单独算：`"config:{$key}"` 的开括号是 T_CURLY_OPEN
                // （文本是 `{$`，不等于 `'{'`），收括号却是单字符 `}`。少认这一半就等于
                // 方法体在插值处深度归零、当场截断——后半截 parent::tearDown() 被吃掉，
                // 门会把合规的 tearDown 报成「未调用父类」。
                $isOpen = $t === '{'
                    || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
                if ($isOpen) {
                    $depth++;
                    $started = true;
                } elseif ($t === '}') {
                    $depth--;
                }
                if ($started) {
                    $body .= is_array($t) ? (string) $t[1] : (string) $t;
                }
                if ($started && $depth === 0) {
                    break;
                }
            }
            $out[$name] = $body;
        }

        return $out;
    }

    /**
     * 自检：抽取器必须能穿过字符串插值里的花括号。
     *
     * 上一轮真实踩到过——新写的 tearDown 里有 `HotCache::forget("config:{$key}")`，
     * 门把它报成「未调用 parent::tearDown()」。没有这一条，修好的配对逻辑随时会退回原样，
     * 而下一次误报只会让人把插值改成拼接（把门的缺陷转嫁成代码风格）。
     */
    public function test_extractor_survives_string_interpolation_braces(): void
    {
        $src = <<<'PHP'
<?php
final class Probe extends \Kode\Framework\Testing\TestCase
{
    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            Cache::forget("config:{$key}");
        }

        parent::tearDown();
    }
}
PHP;

        $bodies = $this->hookBodies($src);

        self::assertArrayHasKey('tearDown', $bodies, '方法没被抽到，等于门在空转');
        self::assertStringContainsString('parent::tearDown', $bodies['tearDown']);

        // 负向对照：真漏了父类调用的那份，必须仍然抽得到方法体、且不含 parent 调用。
        $missing = str_replace("\n        parent::tearDown();\n", "\n", $src);
        $missingBodies = $this->hookBodies($missing);
        self::assertArrayHasKey('tearDown', $missingBodies);
        self::assertStringNotContainsString('parent::', $missingBodies['tearDown']);
    }
}
