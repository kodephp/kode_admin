<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Testing\TestCase;
use Kode\Plugins\frontend\ConditionEvaluator;
use Kode\Plugins\frontend\WorkflowBridgeController;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 工作流 condition 步骤求值回归：表达式是数据，不是可执行代码。
 *
 * 旧实现名叫 safeEvaluateCondition，实际两条分支都以
 * `eval("return (bool) ({...});")` 收尾，而字符白名单保留了字母、下划线、
 * 引号、括号与运算符——正好是写一次函数调用所需的全部字符。实测：
 *   file_put_contents('/tmp/x','y')  → 真的落盘
 *   'a' == 'a' && phpinfo()          → 真的把 phpinfo 输出进了响应链路
 *   system('id')                     → 真的执行
 * 一条定义存在 POST /api/workflow/definitions（workflow:edit 权限）里，
 * 就能以 Web 进程身份执行任意 PHP：权限从「编排业务流」放大成「任意代码执行」。
 *
 * 现在条件语言由 ConditionEvaluator 解释：词法阶段只承认字面量、比较与逻辑运算符。
 * 本测试锁两件事：任意代码不再被执行；合法表达式的判定结果与迁移前逐条一致。
 */
final class WorkflowConditionEvaluatorTest extends TestCase
{
    protected bool $independentApp = true;

    private const PROOF = '/tmp/kode_condition_eval_pwned';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        @unlink(self::PROOF);
    }

    protected function tearDown(): void
    {
        @unlink(self::PROOF);

        parent::tearDown();
    }

    /**
     * 走真实链路：变量替换 → 求值，返回 executeCondition 的结果数组。
     *
     * @param array<string, mixed> $context
     */
    private function condition(string $expression, array $context = []): array
    {
        $method = new \ReflectionMethod(WorkflowBridgeController::class, 'executeCondition');
        $method->setAccessible(true);

        return $method->invoke(new WorkflowBridgeController(), ['condition' => $expression], $context);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function legitExpressions(): array
    {
        return [
            '字符串相等' => ["'daily_checkin' == 'daily_checkin'", true],
            '字符串不等' => ["'daily_checkin' == 'weekly'", false],
            '双引号字面量' => ['"nightly" == "nightly"', true],
            // 线上定义的真实形状：变量替换出单引号字面量，作者手写双引号
            '混合引号（线上 checkin_submit）' => ["'daily_checkin' == \"daily_checkin\"", true],
            '数字比较' => ['5 >= 6', false],
            '不等于' => ['5 != 3', true],
            '与' => ['5 > 3 && 2 < 4', true],
            '或' => ["1 == 2 || 'a' == 'a'", true],
            '取反' => ["!('a' == 'b')", true],
            '括号分组' => ['1 == 1 && (2 == 2 || 3 == 4)', true],
            '字面量真' => ['true', true],
            '字面量假' => ['false', false],
            'null 相等' => ['null == null', true],
            '松散相等保留' => ["'0' == 0", true],
            '全等保留' => ["'0' === 0", false],
            '负数' => ['-1 < 0', true],
            '小数' => ['5.5 > 5.4', true],
            '含逗号字符串' => ["'a,b' == 'a,b'", true],
            '优先级 or 低于 and' => ["false && true || 'x' == 'x'", true],
        ];
    }

    #[DataProvider('legitExpressions')]
    public function test_legit_expressions_keep_their_verdict(string $expression, bool $expected): void
    {
        self::assertSame($expected, ConditionEvaluator::evaluate($expression), $expression);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function codeStrings(): array
    {
        return [
            '直接落盘' => ["file_put_contents('" . self::PROOF . "','x')"],
            '短路或之后落盘' => ["1 == 1 || file_put_contents('" . self::PROOF . "','x')"],
            '系统命令' => ["system('id')"],
            'phpinfo' => ["'a' == 'a' && phpinfo()"],
            '取当前目录' => ['getcwd()'],
            '变量拼接函数名' => ["\$f = 'sys'.'tem'; \$f('id')"],
            '静态调用' => ['\Kode\Plugins\frontend\ConditionEvaluator::evaluate("true")'],
            '赋值' => ['matched = true'],
            '数组下标' => ["['a'][0] == 'a'"],
            '属性访问' => ['$this->owner()'],
            '分号收尾' => ["'a' == 'a';"],
            '反引号执行' => ['`id`'],
        ];
    }

    #[DataProvider('codeStrings')]
    public function test_code_is_never_executed(string $expression): void
    {
        self::assertFalse(ConditionEvaluator::evaluate($expression), '代码表达式不得通过：' . $expression);
        self::assertFalse($this->condition($expression)['matched'], '经完整链路同样不得匹配：' . $expression);
        self::assertFileDoesNotExist(self::PROOF, '求值过程不得产生副作用');
    }

    public function test_syntax_garbage_is_treated_as_no_match(): void
    {
        foreach (['', '   ', "'未闭合", '1 ==', '&& true', '(1 == 1', '1 = 1', "'a' == 'b' =='c'"] as $bad) {
            self::assertFalse(ConditionEvaluator::evaluate($bad), '语法不完整应判不匹配: [' . $bad . ']');
        }
    }

    public function test_variables_are_substituted_as_literals(): void
    {
        $context = ['params' => ['action' => 'daily_checkin', 'count' => '3']];

        $hit = $this->condition("{{params.action}} == 'daily_checkin'", $context);
        self::assertTrue($hit['matched']);
        self::assertSame("'daily_checkin' == 'daily_checkin'", $hit['expression']);

        $numeric = $this->condition('{{params.count}} > 2', $context);
        self::assertTrue($numeric['matched'], '数字变量不加引号，比较应成立');

        $miss = $this->condition("{{params.action}} == 'weekly'", $context);
        self::assertFalse($miss['matched']);

        // 缺失变量解析为 ''，与旧实现一致（不抛异常）
        $missing = $this->condition("{{params.nope}} == ''", $context);
        self::assertTrue($missing['matched']);
    }

    /**
     * 线上唯一一条已存定义（checkin_submit）的条件形状：作者手写双引号，
     * 变量替换出单引号，两者必须还能判等——迁移求值器不能把业务流判反。
     */
    public function test_production_definition_shape_still_matches(): void
    {
        $hit = $this->condition(
            '{{params.action}} == "daily_checkin"',
            ['params' => ['action' => 'daily_checkin']]
        );

        self::assertTrue($hit['matched'], $hit['expression']);
    }

    /**
     * 变量值里的引号/反斜杠必须作为字面量落地：既不能改变判定，也不能逃出引号变成代码。
     */
    public function test_hostile_variable_value_stays_data(): void
    {
        $context = ['params' => ['action' => "x' || file_put_contents('" . self::PROOF . "','y') || '"]];

        $result = $this->condition("{{params.action}} == 'x'", $context);
        self::assertFalse($result['matched'], '注入串不得让不相等的值判为相等');
        self::assertFileDoesNotExist(self::PROOF, '变量值不得被执行');

        // 含反斜杠与单引号的正常值应原样往返：替换后的字面量解码回来必须等于原值
        $value = "don\\'e"; // 六个字符：d o n \ ' e
        $escapedInPhpLiteral = '"don\\\\\\\'e"'; // 条件里写成双引号字面量，转义后同样是 d o n \ ' e
        $wrongUnescaping = '"don\\\'e"';          // 少一个反斜杠 → 解出 d o n ' e，与原值不等

        $literal = ['params' => ['action' => $value]];
        self::assertSame(6, strlen($value));
        self::assertTrue($this->condition('{{params.action}} == ' . $escapedInPhpLiteral, $literal)['matched']);
        self::assertFalse($this->condition('{{params.action}} == ' . $wrongUnescaping, $literal)['matched']);
    }
}
