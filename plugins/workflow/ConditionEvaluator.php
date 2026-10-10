<?php

declare(strict_types=1);

namespace Kode\Plugins\workflow;

/**
 * 工作流 condition 步骤的表达式求值：布尔/比较运算的微语言解释器。
 *
 * 取代原先的 eval() 兜底。旧实现的「安全条件求值」两条分支最终都落在
 * `eval("return (bool) ({$expression});")` 上，而字符白名单允许
 * 字母、数字、下划线、引号、括号与运算符——`file_put_contents('/tmp/x','y')`
 * 与 `1 == 1 || system('id')` 全都能原样穿过白名单进 eval。
 * 表达式来自工作流定义（POST /api/workflow/definitions，仅需 workflow:edit），
 * 于是「编排一条业务流」的权限被放大成「以 Web 进程身份执行任意 PHP」。
 *
 * 本类只认这点语法：字面量（字符串/数字/true/false/null）、
 * 比较（== === != !== < > <= >=）、逻辑（&& || !）、括号分组，
 * 别的任何东西——函数调用、变量、属性访问、分号——都在词法阶段报错，
 * 求值返回 false（与旧实现「解析失败即不匹配」的口径一致）。
 *
 * 语法规则：
 *   or     := and ( '||' and )*
 *   and    := not ( '&&' not )*
 *   not    := '!' not | comparison
 *   cmp    := primary ( compOp primary )?
 *   primary := '(' or ')' | literal
 */
final class ConditionEvaluator
{
    /** @var list<array{0: string, 1: mixed}> 词法记号：[类型, 值] */
    private array $tokens = [];

    private int $pos = 0;

    /** 比较运算符，长者优先（避免 `===` 被切成 `==` + `=`） */
    private const COMPARE_OPS = ['===', '!==', '==', '!=', '<=', '>=', '<', '>'];

    /**
     * 求值一条条件表达式；语法不合法时返回 false。
     */
    public static function evaluate(string $expression): bool
    {
        $self = new self();

        try {
            $self->tokens = $self->tokenize($expression);
            $value = $self->parseOr();

            if ($self->pos !== count($self->tokens)) {
                return false; // 有记号没被消费：表达式结构不完整
            }

            return (bool) $value;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    private function tokenize(string $input): array
    {
        $tokens = [];
        $len = strlen($input);
        $i = 0;
        $number = '/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?/';

        while ($i < $len) {
            $ch = $input[$i];

            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $i++;
                continue;
            }

            if ($ch === '(' || $ch === ')') {
                $tokens[] = [$ch, null];
                $i++;
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                [$value, $i] = $this->readString($input, $i, $len, $ch);
                $tokens[] = ['lit', $value];
                continue;
            }

            $op = $this->matchOperator($input, $i, $len);
            if ($op !== null) {
                $tokens[] = ['op', $op];
                $i += strlen($op);
                continue;
            }

            // 减号只在「运算符/开括号之后的数字」位置才算负数字面量；
            // 语法里没有算术，`5-3` 会在读完 5 之后停在 '-' 上报错。
            $startsNumber = ($ch >= '0' && $ch <= '9')
                || (($ch === '-' || $ch === '+') && $i + 1 < $len
                    && $this->lastTokenWasOperator($tokens)
                    && ($input[$i + 1] >= '0' && $input[$i + 1] <= '9' || $input[$i + 1] === '.'));

            if ($startsNumber && preg_match($number, substr($input, $i), $m) === 1) {
                $raw = $m[0];
                $i += strlen($raw);
                $tokens[] = ['lit', preg_match('/^[+-]?\d+$/', $raw) === 1 ? (int) $raw : (float) $raw];
                continue;
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', substr($input, $i), $m) === 1) {
                $word = $m[0];
                $i += strlen($word);

                $tokens[] = match (true) {
                    $word === 'true' => ['lit', true],
                    $word === 'false' => ['lit', false],
                    $word === 'null' => ['lit', null],
                    default => throw new \RuntimeException("不支持的标识: {$word}"),
                };
                continue;
            }

            throw new \RuntimeException("不支持的字符: {$ch}");
        }

        return $tokens;
    }

    /** 当前记号是否处在「期待一个操作数」的位置 */
    private function lastTokenWasOperator(array $tokens): bool
    {
        $last = end($tokens);

        return $last === false || $last[0] === 'op' || $last[0] === '(';
    }

    /**
     * 读取引号包裹的字符串字面量，返回 [值, 结束位置]。
     *
     * @return array{0: string, 1: int}
     */
    private function readString(string $input, int $start, int $len, string $quote): array
    {
        $value = '';
        $i = $start + 1;

        while ($i < $len) {
            $ch = $input[$i];

            if ($ch === '\\' && $i + 1 < $len) {
                $next = $input[$i + 1];
                $value .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    default => $next,
                };
                $i += 2;
                continue;
            }

            if ($ch === $quote) {
                return [$value, $i + 1];
            }

            $value .= $ch;
            $i++;
        }

        throw new \RuntimeException('字符串未闭合');
    }

    /**
     * 在位置 $i 尝试匹配运算符（含取反的 `!`）。
     */
    private function matchOperator(string $input, int $i, int $len): ?string
    {
        foreach (array_merge(self::COMPARE_OPS, ['&&', '||', '!']) as $op) {
            if (substr($input, $i, strlen($op)) === $op) {
                return $op;
            }
        }

        return null;
    }

    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function peekOp(string $op): bool
    {
        $token = $this->peek();

        return $token !== null && $token[0] === 'op' && $token[1] === $op;
    }

    private function consume(): void
    {
        $this->pos++;
    }

    private function parseOr(): mixed
    {
        $left = $this->parseAnd();

        while ($this->peekOp('||')) {
            $this->consume();
            $right = $this->parseAnd();
            $left = (bool) $left || (bool) $right;
        }

        return $left;
    }

    private function parseAnd(): mixed
    {
        $left = $this->parseNot();

        while ($this->peekOp('&&')) {
            $this->consume();
            $right = $this->parseNot();
            $left = (bool) $left && (bool) $right;
        }

        return $left;
    }

    private function parseNot(): mixed
    {
        if ($this->peekOp('!')) {
            $this->consume();

            return !(bool) $this->parseNot();
        }

        return $this->parseComparison();
    }

    private function parseComparison(): mixed
    {
        $left = $this->parsePrimary();

        $token = $this->peek();
        if ($token !== null && $token[0] === 'op' && in_array($token[1], self::COMPARE_OPS, true)) {
            $this->consume();

            return $this->compare($left, (string) $token[1], $this->parsePrimary());
        }

        return $left;
    }

    private function parsePrimary(): mixed
    {
        $token = $this->peek();
        if ($token === null) {
            throw new \RuntimeException('表达式意外结束');
        }

        if ($token[0] === '(') {
            $this->consume();
            $value = $this->parseOr();

            $close = $this->peek();
            if ($close === null || $close[0] !== ')') {
                throw new \RuntimeException('缺少右括号');
            }
            $this->consume();

            return $value;
        }

        if ($token[0] === 'lit') {
            $this->consume();

            return $token[1];
        }

        throw new \RuntimeException('表达式结构非法');
    }

    /**
     * 标量比较：沿用 PHP 的运算符语义（旧实现里 eval 用的就是它们），
     * 只是操作数被限制成字面量，不再是可执行代码。
     */
    private function compare(mixed $left, string $op, mixed $right): bool
    {
        return match ($op) {
            '==' => $left == $right,
            '===' => $left === $right,
            '!=' => $left != $right,
            '!==' => $left !== $right,
            '<' => $left < $right,
            '>' => $left > $right,
            '<=' => $left <= $right,
            '>=' => $left >= $right,
            default => throw new \RuntimeException("未知运算符: {$op}"),
        };
    }
}
