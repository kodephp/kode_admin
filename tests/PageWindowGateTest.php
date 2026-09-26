<?php

declare(strict_types=1);

namespace app\tests;

use PHPUnit\Framework\TestCase;

/**
 * 静态门禁：分页窗口只由 `app\concerns\PageWindow` 判一次。
 *
 * 四条口径，都对应收口前的真实写法：
 *  1. `($page - 1)` 只允许出现在 trait 里 —— 「`page` 饱和成 `PHP_INT_MAX` 后乘成 float」
 *     这一步就发生在这个乘法上，第二处乘法就是第二个 500 现场；
 *  2. 读 `page` / `size` 的方法必须调用 `pageWindow(` 或 `mergedWindow(`，
 *     否则「谁决定翻多远」又有了第二份答案（旧的 `max(1, (int) query('page', 1))` 只挡下限，
 *     `9223372036854775807` 照放行）；
 *  3. 自己夹取 `page`/`size` 的两行旧写法不得复活；
 *  4. 归并型入口（方法体里出现 `$page * $size`，即每片各取这么多行进 PHP）必须用
 *     `mergedWindow(`：SQL 侧那个十万行预算限的是「DB 扫弃多少行」，不是「PHP 里存多少行」，
 *     乘上分片数（`100000 × 4 片 ≈ 354 MB`）就是一次内存 fatal。
 *
 * 门禁自己也要被扫：`test_scanner_actually_scans` 拿真实旧写法和刚收口的写法各喂一遍，
 * 只测「现在全绿」的话，「正则写错、什么都扫不到」和「代码真的干净」在输出上长得一样。
 * 剥注释同理 —— 本文件与 `PageWindow` 的注释正好在引用反面教材，不剥就是自己判自己违规。
 */
final class PageWindowGateTest extends TestCase
{
    /** 唯一允许做 `($page - 1)` 乘法的文件（相对项目根）。 */
    private const OFFSET_HOME = 'app/concerns/PageWindow.php';

    /** 已收口入口数下限：明显低于现值说明扫描面或判定被改坏了（本仓当前 16 处）。 */
    private const MIN_GATED_SITES = 14;

    /** 归并型入口数下限（本仓当前 3 处：审计列表、我的审计、公告列表）。 */
    private const MIN_MERGED_SITES = 3;

    /** 收口前的两行旧写法（本条门禁要抓的就是它们）。 */
    private const OLD_PAGE_CLAMP = "\$page = max(1, (int) \$this->query('page', 1));";

    private const OLD_SIZE_CLAMP = "\$size = min(100, max(1, (int) \$this->query('size', 20)));";

    /** 收口后的标准块：必须一条都不命中，否则门禁会把正确写法一起打死。 */
    private const GATED_BLOCK = "\$win = \$this->pageWindow(\$this->query('page'), \$this->query('size'));\n"
        . "if (\$win['error'] !== null) { return \$this->fail(\$win['error'], 400); }\n"
        . "[\$page, \$size, \$offset] = [\$win['page'], \$win['size'], \$win['offset']];";

    /** 口径 1 + 3：整个 app/ 与 plugins/ 里除 trait 本人外不得再算 offset、再自行夹取。 */
    public function test_no_second_page_window_survives_in_the_codebase(): void
    {
        $offenders = [];
        foreach (self::firstPartyFiles() as $file) {
            $relative = self::relative($file);
            if ($relative === self::OFFSET_HOME) {
                continue;
            }
            foreach ($this->clampOffenses(self::codeOnly((string) file_get_contents($file))) as $why) {
                $offenders[] = $relative . '（' . $why . '）';
            }
        }

        self::assertSame(
            [],
            $offenders,
            "仍在自行决定分页窗口：offset 只应由 pageWindow() 算一次，"
            . "第二处 (`\$page - 1) * \$size` 就是第二个「一个查询参数把列表接口打成 500」的现场：\n  "
            . implode("\n  ", $offenders)
        );
    }

    /** 口径 2：读了 `page`/`size` 就必须问过闸门，否则「收口」只是收了几个入口。 */
    public function test_every_page_reader_actually_asks_the_gate(): void
    {
        $offenders = [];
        $gated = 0;
        foreach (self::firstPartyFiles() as $file) {
            $code = self::codeOnly((string) file_get_contents($file));
            foreach ($this->methodBodies($code) as $name => $body) {
                if (!$this->readsWindow($body)) {
                    continue;
                }
                if ($this->asksGate($body)) {
                    $gated++;
                    continue;
                }
                $offenders[] = self::relative($file) . '::' . $name;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "读 page/size 却没问过分页闸门（越界没人挡，offset 自己乘）：\n  "
            . implode("\n  ", $offenders)
        );
        // 防空转：真的收口过，而不是这条断言压根没匹配到任何方法。
        self::assertGreaterThanOrEqual(
            self::MIN_GATED_SITES,
            $gated,
            '只找到 ' . $gated . " 处「读过 page/size 且问过闸门」的方法，远低于本仓入口数量："
            . '判定或扫描面被改坏了'
        );
    }

    /**
     * 口径 4：归并型入口必须用摊薄后的行预算。
     *
     * 抓的是「每片各取 page*size 行进 PHP 再 usort 切片」这一类：它没有 OFFSET，
     * 所以 SQL 侧的十万行预算对它毫无意义 —— 代价是 `page * size * 分片数` 行数组，
     * 4 片就是 251 MB，一枚有效 token 一个查询参数即可把 worker 撞成内存 fatal。
     */
    public function test_merge_paginated_readers_use_the_merged_budget(): void
    {
        $offenders = [];
        $merged = 0;
        foreach (self::firstPartyFiles() as $file) {
            if (self::relative($file) === self::OFFSET_HOME) {
                continue;
            }
            $code = self::codeOnly((string) file_get_contents($file));
            foreach ($this->methodBodies($code) as $name => $body) {
                if (!$this->multipliesIntoEveryShard($body)) {
                    continue;
                }
                if (str_contains($body, 'mergedWindow(')) {
                    $merged++;
                    continue;
                }
                $offenders[] = self::relative($file) . '::' . $name;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "按片取行却没走 mergedWindow()（行预算没摊薄到分片数上）：\n  "
            . implode("\n  ", $offenders)
        );
        self::assertGreaterThanOrEqual(
            self::MIN_MERGED_SITES,
            $merged,
            '只找到 ' . $merged . " 处归并型分页入口，远低于本仓分片列表的数量：判据被改坏了"
        );
    }

    /** 正对照：扫描器对真实文件与两种写法都看得见。 */
    public function test_scanner_actually_scans(): void
    {
        $old = $this->clampOffenses(self::OLD_PAGE_CLAMP . "\n" . self::OLD_SIZE_CLAMP . "\n\$params[] = (\$page - 1) * \$size;");
        self::assertContains('又乘了一次 offset', $old);
        self::assertContains('夹取 page', $old);
        self::assertContains('夹取 size', $old);

        // 收口后的标准块一条都不能命中（否则门禁连正确写法一起报）。
        self::assertSame([], $this->clampOffenses(self::GATED_BLOCK));

        // 真实代码里那唯一一处乘法必须还在：证明读文件 + 剥注释 + 匹配这条链没断。
        $gate = $this->clampOffenses(self::codeOnly(
            (string) file_get_contents(dirname(__DIR__) . '/' . self::OFFSET_HOME)
        ));
        self::assertSame(['又乘了一次 offset'], $gate, 'PageWindow 自己不再算 offset，扫描器就是瞎的');

        // 口径 2 的正/负对照：同一个 page 读取，包里没有闸门名才算违规。
        self::assertTrue($this->readsWindow("\$page = max(1, (int) \$this->query('page', 1));"));
        self::assertTrue($this->readsWindow(self::GATED_BLOCK));
        self::assertFalse($this->readsWindow("return \$this->ok(['page' => 1, 'size' => 20]);"));

        // 口径 2 认 mergedWindow（否则三个归并入口会被这条一起打死），口径 4 只认它。
        $mergedSite = "\$win = \$this->mergedWindow(\$this->query('page'), \$this->query('size'), count(\$tables));\n"
            . "[\$page, \$size, \$offset] = [\$win['page'], \$win['size'], \$win['offset']];\n"
            . "\$need = \$page * \$size;";
        self::assertTrue($this->readsWindow($mergedSite), '归并入口也读入参，口径 2 得认它');
        self::assertTrue($this->asksGate($mergedSite), 'asksGate 漏掉了 mergedWindow');
        self::assertTrue($this->multipliesIntoEveryShard($mergedSite), '乘法判据扫不到真实写法');
        self::assertFalse(
            $this->multipliesIntoEveryShard(self::GATED_BLOCK),
            '单表分页（SQL 侧 OFFSET）不该被口径 4 拦下'
        );
        // 两条判据各管一半：只用 pageWindow 的入口满足口径 2、不满足口径 4。
        self::assertTrue($this->asksGate(self::GATED_BLOCK), 'asksGate 漏掉了 pageWindow');
        self::assertFalse(str_contains(self::GATED_BLOCK, 'mergedWindow('));
    }

    /** 问过闸门 = 调了 `pageWindow()` 或 `mergedWindow()`（同一判据的两种预算）。 */
    private function asksGate(string $body): bool
    {
        return preg_match('/\$this->(?:page|merged)Window\s*\(/', $body) === 1;
    }

    /** 归并型分页的判据：方法体里把 `page * size` 当成「每片要取多少行」。 */
    private function multipliesIntoEveryShard(string $body): bool
    {
        return preg_match('/\$page\s*\*\s*\$size/', $body) === 1;
    }

    /**
     * 对一个方法的源码判「它读没读分页入参」。
     *
     * 只认「紧跟左括号的字面量」—— 也就是取参器调用（`query('page')`、`param('size')`、
     * `only(['page', ...])` 的裸数组字面量都在其内），而 `['page' => $page]` 这种出参键不读。
     */
    private function readsWindow(string $code): bool
    {
        return preg_match("/\(\s*['\"](?:page|size)['\"]\s*[,)=]/", $code) === 1
            || preg_match("/\(\s*\[\s*['\"](?:page|size)['\"]\s*,/", $code) === 1;
    }

    /**
     * 三条文本判据，命中项说明文字。
     *
     * @return list<string>
     */
    private function clampOffenses(string $code): array
    {
        $out = [];
        if (preg_match('/\(\s*\$page\s*-\s*1\s*\)/', $code) === 1) {
            $out[] = '又乘了一次 offset';
        }
        if (preg_match("/max\(\s*1\s*,\s*\(int\)\s*\\\$this->(?:query|param|input|post)\s*\(\s*'page'/", $code) === 1) {
            $out[] = '夹取 page';
        }
        if (preg_match("/min\(\s*\d+\s*,\s*max\(\s*1\s*,\s*\(int\)\s*\\\$this->(?:query|param|input|post)\s*\(\s*'size'/", $code) === 1) {
            $out[] = '夹取 size';
        }

        return $out;
    }

    /**
     * 按 `function` 关键字切分方法体（本仓方法不嵌套，够这个门禁用）。
     *
     * @return array<string, string> 方法名 => 方法体源码片段
     */
    private function methodBodies(string $code): array
    {
        $out = [];
        if (preg_match_all('/\bfunction\s+(\w+)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE) === false) {
            return $out;
        }
        $total = count($m[0]);
        foreach ($m[1] as $i => [$name, $offset]) {
            $start = $offset + strlen($m[0][$i][0]);
            $end = $i + 1 < $total ? $m[1][$i + 1][1] : strlen($code);
            $out[$name] = substr($code, $start, max(0, $end - $start));
        }

        return $out;
    }

    /** 剥掉注释与 docblock 后的纯代码文本（判据说的是代码，不是说明文字）。 */
    private function codeOnly(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)
                && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private static function relative(string $file): string
    {
        return str_replace(dirname(__DIR__) . '/', '', $file);
    }

    /**
     * 一方代码（app/ 与 plugins/）的 php 文件。
     *
     * @return list<string>
     */
    private static function firstPartyFiles(): array
    {
        $root = realpath(dirname(__DIR__));
        self::assertNotFalse($root, '项目根解析失败，扫描面就成了空集');

        $files = [];
        foreach (['app', 'plugins'] as $dir) {
            $base = $root . '/' . $dir;
            self::assertDirectoryExists($base, '扫描目录 ' . $dir . ' 不在了：门禁会永远全绿');
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }
        self::assertGreaterThan(60, count($files), '只扫到 ' . count($files) . ' 个文件，不像是一方代码的全量');

        sort($files);

        return $files;
    }
}
