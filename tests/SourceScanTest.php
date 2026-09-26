<?php

declare(strict_types=1);

namespace app\tests;

use PHPUnit\Framework\TestCase;
use app\tests\Support\SourceScan;

/**
 * `SourceScan::methodBodies()` 的切片正确性。
 *
 * 它是「按方法名取体」的那把尺子，而所有「旧写法不得复活」的门禁都靠它区分
 * 「这条语句在哪个方法里」。第一版实现把 `PREG_OFFSET_CAPTURE` 的**文本**当成了偏移
 * （`(int) 'function repair('` === 0），于是每个方法的体都从文件开头附近开始 ——
 * 结果是「repair() 里没有 unlink(」这种断言会**因为取错了体而通过**：
 * 拿到的其实是 health() 的前 200 字节，而真正那句 `@unlink($pidFile)` 在体外面。
 *
 * 这类失效不会报错，只会让门禁静默变成「永远绿」。所以这里用一份合成源码，
 * 让每个方法的体带上只有它自己才有的记号，逐条验「取到的必须是自己的那段」。
 */
final class SourceScanTest extends TestCase
{
    private const SRC = <<<'PHP'
    <?php

    class Sample
    {
        public function alpha(): void
        {
            $markerAlpha = 1;
            if ($markerAlpha) {
                $nested = 'brace } inside string';
            }
        }

        public function beta(): void
        {
            $markerBeta = unlink('/tmp/zz-nope');
        }

        protected function gamma(int $x): int
        {
            return $x + 1;
        }

        abstract public function delta(): void;
    }
    PHP;

    public function test_each_body_is_the_region_of_its_own_method(): void
    {
        $bodies = SourceScan::methodBodies(self::SRC);

        self::assertSame(['alpha', 'beta', 'gamma'], array_keys($bodies),
            '方法清单不对（abstract 无体的必须跳过）：' . json_encode(array_keys($bodies)));

        self::assertStringContainsString('$markerAlpha', $bodies['alpha']);
        self::assertStringNotContainsString('$markerBeta', $bodies['alpha'],
            'alpha() 的体里出现了 beta() 的语句 —— 切片取到了别人的区域');

        self::assertStringContainsString('unlink(', $bodies['beta'],
            'beta() 的体没取到 unlink：门禁会因此把「方法里没有删文件」判成成立');
        self::assertStringNotContainsString('$markerAlpha', $bodies['beta']);
    }

    /** 嵌套花括号必须配对到方法自己的收尾，而不是最里面那层。 */
    public function test_a_body_spans_its_nested_braces(): void
    {
        $bodies = SourceScan::methodBodies(self::SRC);

        self::assertStringEndsWith('}', $bodies['alpha']);
        self::assertStringContainsString('brace } inside string', $bodies['alpha']);
    }

    /** 同名方法只留第一份（重写/条件定义时不覆盖已登记的那份）。 */
    public function test_duplicate_method_names_keep_the_first_slice(): void
    {
        $src = self::SRC . "\n<?php\nclass Other\n{\n    public function alpha(): void\n    {\n        \$second = true;\n    }\n}\n";
        $bodies = SourceScan::methodBodies($src);

        self::assertStringContainsString('$markerAlpha', (string) ($bodies['alpha'] ?? ''));
        self::assertStringNotContainsString('$second', (string) ($bodies['alpha'] ?? ''));
    }
}
