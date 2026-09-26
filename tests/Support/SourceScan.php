<?php

declare(strict_types=1);

namespace app\tests\Support;

/**
 * 静态门禁共用的源码切片器：**先剥注释，再按方法取体**。
 *
 * 为什么单独一份：本仓的「旧写法不得复活」类门禁全都要在同一件小事上做到精确 ——
 * 注释里那句「旧写法长什么样」（AGENTS.md 与各包 README 都要求写出来）不能当成实现命中，
 * 否则逼人删掉解释才能让测试变绿；而方法体切片又要跟着花括号配对走，
 * 用 `explode('public function')` 数括号在 docblock 里那句 `update()` 上就会切错。
 * 这两件事此前在 18 个测试文件里各手写了一份，每一份都是一个「自己看不见自己失效」的扫描器。
 *
 * 两个新入门禁（`PartialUpdateTest`、`MigrationRollbackStepsTest`）从这里取；
 * 其余存量文件按「改到它时顺手迁」收敛，不做一次性大改（它们的断言各有专属的正负对照，
 * 统一迁移等于同时动 18 个正在变红的东西）。
 */
final class SourceScan
{
    /**
     * 注释（`//`、`#`、`/* *\/`、docblock）替换成等长空白。
     *
     * 等长是关键：门禁要靠偏移比对「谁写在谁之前」，压缩长度就会把偏移全毁掉。
     * 换行原样保留，行号也就跟着保住。
     */
    public static function stripComments(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array((int) $t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= preg_replace('/[^\n]/', ' ', (string) $t[1]) ?? '';
                continue;
            }
            $out .= is_array($t) ? (string) $t[1] : (string) $t;
        }

        return $out;
    }

    /**
     * 按方法名正则取「public 方法体」，返回 `方法名 => 体（含首尾花括号）`。
     *
     * @param string $namePattern 形如 `/^(?:update|save|edit)\w*$/i`
     * @return array<string, string>
     */
    public static function publicMethodBodies(string $src, string $namePattern): array
    {
        $clean = self::stripComments($src);
        $out = [];
        foreach ((array) preg_split('/(?=public\s+function\s+)/', $clean) as $chunk) {
            if (preg_match('/^public\s+function\s+(\w+)\s*\(/i', $chunk, $m) !== 1) {
                continue;
            }
            if (preg_match($namePattern, $m[1]) !== 1) {
                continue;
            }
            $body = self::bracedFrom($chunk);
            if ($body !== '') {
                $out[$m[1]] = $body;
            }
        }

        return $out;
    }

    /**
     * 整类的方法体（不限可见性），键为方法名；同名多次出现时保留第一份。
     *
     * 偏移只从 `PREG_OFFSET_CAPTURE` 的**第二个元素**取。第一版写的是 `(int) $m[0][0]`，
     * 那是把匹配到的**文本** `'function repair('` 转成整数 = 0，于是每个方法的体都从
     * 文件开头附近开始：`assertStringNotContainsString('unlink(', bodies['repair'])`
     * 会因为「取到的其实是别人的体」而静默通过 —— 门禁变成永远绿，且没有任何报错。
     *
     * @return array<string, string>
     */
    public static function methodBodies(string $src): array
    {
        $clean = self::stripComments($src);
        $out = [];
        $offset = 0;
        while (preg_match('/function\s+(\w+)\s*\(/i', substr($clean, $offset), $m, PREG_OFFSET_CAPTURE) === 1) {
            $name = $m[1][0];
            $at = $offset + (int) $m[0][1]; // $m[0][1] = 这次匹配在 $clean 里的偏移
            $after = $at + strlen($m[0][0]); // 签名 `function name(` 之后
            $brace = strpos($clean, '{', $after);
            $semi = strpos($clean, ';', $after);
            // 抽象方法/接口方法没有体：下一个 `{` 属于别人，必须确认它在 `;` 之前。
            if ($brace !== false && ($semi === false || $brace < $semi)) {
                $body = self::bracedFrom(substr($clean, (int) $brace));
                if ($body !== '' && !isset($out[$name])) {
                    $out[$name] = $body;
                }
            }
            $offset = (int) $after;
        }

        return $out;
    }

    /**
     * 挖掉一批调用（含配对右括号），返回剩下的文本。
     *
     * 门禁用它区分「有判据兜着」和「没人兜着」：`self::pick($data, 'x', $row['x'], …)`
     * 里的 `$data['x']` 是合法参数，整段挖掉之后还留着 `$data['x'] ?? 0` 的才是缺陷。
     *
     * @param list<string> $needles 以 `(` 结尾的调用片段
     */
    public static function dropCalls(string $src, array $needles): string
    {
        foreach ($needles as $needle) {
            while (($at = strpos($src, $needle)) !== false) {
                $open = $at + strlen($needle) - 1; // needle 自带的那个 `(`
                $depth = 0;
                $len = strlen($src);
                $end = null;
                for ($i = $open; $i < $len; ++$i) {
                    if ($src[$i] === '(') {
                        ++$depth;
                    } elseif ($src[$i] === ')' && --$depth === 0) {
                        $end = $i;
                        break;
                    }
                }
                if ($end === null) {
                    break;
                }
                $src = substr($src, 0, $at) . substr($src, $end + 1);
            }
        }

        return $src;
    }

    /** 从 `$src` 的第一个 `{` 起，返回到它配对的 `}`（含两端）。 */
    private static function bracedFrom(string $src): string
    {
        $open = strpos($src, '{');
        if ($open === false) {
            return '';
        }
        $depth = 0;
        $len = strlen($src);
        for ($i = (int) $open; $i < $len; ++$i) {
            if ($src[$i] === '{') {
                ++$depth;
            } elseif ($src[$i] === '}') {
                if (--$depth === 0) {
                    return substr($src, (int) $open, $i - $open + 1);
                }
            }
        }

        return '';
    }
}
