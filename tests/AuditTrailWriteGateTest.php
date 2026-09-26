<?php

declare(strict_types=1);

namespace app\tests;

use PHPUnit\Framework\TestCase;

/**
 * 静态门禁：审计流水的「落哪张表、这行 SQL 长什么样、来路 IP 怎么取」各只由一处判。
 *
 * 三条口径，都对应收口前的真实写法（`AGENTS.md` §3「一份数据有两种形状时，归一只放在唯一派发口」）：
 *  1. SQL 与落片调用里都不许再出现**写死的**审计表名：唯一那条 `INSERT` 的表名来自
 *     `AuditTrail::tableFor()` 的插值。收口前 `FeatureFlagController::logChange()` 写死了一条
 *     `INSERT INTO kode_audit_logs`（基表），而读侧只扫 `_00.._NN`，
 *     于是那条账写进了没人读的地方（不报错，只是永远查不到）；
 *  2. 审计落片只由 `AuditTrail::tableFor()` 算 —— 第二处「按什么键选表」就是第二份答案，
 *     一份按 uid、一份按 `(int) uid`（不透明串恒转成 0）就会落到不同片上；
 *  3. 来路 IP 只由 `app/support/ClientIp::resolve()` 判 —— `security.trusted_proxies` 这个参数
 *     漏传一次，该入口就把代理机 IP 当成来路（登录计数变成「一台 NAT 锁住全站账号」）。
 *
 * 门禁自己也要被扫：`test_scanner_actually_scans` 把三种旧写法各喂一遍（必须命中），
 * 再拿真实文件验 needle 命中在它该命中的地方（否则「正则写错、什么都扫不到」和「代码真的干净」
 * 在输出上长得一样）。剥注释同理：本文件与 `AuditTrail` 的 docblock 正好在引用反面教材。
 */
final class AuditTrailWriteGateTest extends TestCase
{
    /** 审计写入与落片的唯一归属文件。 */
    private const TRAIT_HOME = 'app/support/AuditTrail.php';

    /** 来路 IP 的唯一归属文件。 */
    private const IP_HOME = 'app/support/ClientIp.php';

    /** 必须经助手写审计的入口（少了任何一条，「收口」就只收了一半）。 */
    private const WRITERS = [
        'app/middleware/AuditMiddleware.php',
        'app/admin/controllers/FeatureFlagController.php',
    ];

    /** 旧写法一：自己拼审计行的 INSERT，且落在读侧不扫的基表上。 */
    private const OLD_BASE_INSERT
        = "Db::insert('INSERT INTO kode_audit_logs (user_id, username) VALUES (?, ?)', [1, 'x']);";

    /** 旧写法二：自己算审计落片。 */
    private const OLD_TABLE_MATH
        = "\$table = Shard::table('kode_audit_logs', \$uid !== '' ? \$uid : \$ip);";

    /** 旧写法三：自己解释转发头。 */
    private const OLD_IP_EXPR
        = "\$ip = (string) (Request::ip((array) config('security.trusted_proxies', [])) ?? '');";

    /** 口径 1 + 2：任何一处「把审计表名写死在 SQL/路由里」都不许存在（无豁免）。 */
    public function test_no_second_audit_write_survives_in_the_codebase(): void
    {
        $offenders = [];
        foreach (self::firstPartyFiles() as $file) {
            foreach ($this->offenses(self::codeOnly((string) file_get_contents($file))) as $why) {
                $offenders[] = self::relative($file) . '（' . $why . '）';
            }
        }

        self::assertSame(
            [],
            $offenders,
            "又在助手之外写审计流水了：落表与列宽只应由 AuditTrail 判一次，"
            . "第二处 INSERT 就是第二个「写进去了但没人读得到」的现场：\n  "
            . implode("\n  ", $offenders)
        );
    }

    /** 口径 3：来路 IP 的判定不得有第二份。 */
    public function test_no_second_client_ip_judgement_survives(): void
    {
        $offenders = [];
        foreach (self::firstPartyFiles() as $file) {
            $relative = self::relative($file);
            if ($relative === self::IP_HOME) {
                continue;
            }
            $code = self::codeOnly((string) file_get_contents($file));
            if ($this->readsProxyConfig($code)) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "绕开 ClientIp::resolve() 自己取来路 IP（漏传 trusted_proxies = 把代理机当成来路）：\n  "
            . implode("\n  ", $offenders)
        );
    }

    /** 口径 1 的另一半：两个写入口必须真的调助手，而不是只删掉了旧 SQL。 */
    public function test_both_audit_writers_go_through_the_helper(): void
    {
        $calling = [];
        foreach (self::firstPartyFiles() as $file) {
            if (preg_match('/AuditTrail::record\s*\(/', self::codeOnly((string) file_get_contents($file))) === 1) {
                $calling[] = self::relative($file);
            }
        }

        foreach (self::WRITERS as $writer) {
            self::assertContains(
                $writer,
                $calling,
                $writer . ' 不再经 AuditTrail::record() 写审计：落表口径又变成它自己说了算'
            );
        }
        self::assertGreaterThanOrEqual(
            count(self::WRITERS),
            count($calling),
            '调 AuditTrail::record() 的入口比已知写入口还少，助手大概被绕开了'
        );
    }

    /** 防空转：旧写法必须扫得出，needle 必须命中真实文件里它该命中的那一行。 */
    public function test_scanner_actually_scans(): void
    {
        self::assertSame(['又写了一条审计 INSERT'], $this->offenses($this->strip(self::OLD_BASE_INSERT)));
        self::assertSame(['又算了一次审计落片'], $this->offenses($this->strip(self::OLD_TABLE_MATH)));
        self::assertTrue(
            $this->readsProxyConfig($this->strip(self::OLD_IP_EXPR)),
            'IP 口径的 needle 扫不到旧写法，等于这条规则没在扫'
        );

        // 归属文件自己的形状：合法写法（表名由 tableFor() 算出来再插值）一条都不报，
        // 且它确实是那条唯一 INSERT 的住处。
        $home = self::codeOnly((string) file_get_contents(dirname(__DIR__) . '/' . self::TRAIT_HOME));
        self::assertSame([], $this->offenses($home), '门禁把正确写法一起报了');
        self::assertSame(
            1,
            preg_match('/INSERT\s+INTO\s+\{\$table\}/', $home),
            'AuditTrail 自己不再写审计行：口径 1 的 needle 与真实代码就对不上，扫了个空'
        );
        self::assertTrue($this->readsProxyConfig(self::codeOnly(
            (string) file_get_contents(dirname(__DIR__) . '/' . self::IP_HOME)
        )), 'ClientIp 里已经没有 trusted_proxies，口径 3 的豁免就是空档');
    }

    /**
     * 对一个文件的纯代码文本判违规，返回命中项说明。
     *
     * @return list<string>
     */
    private function offenses(string $code): array
    {
        $out = [];
        if (preg_match('/INSERT\s+INTO\s+kode_audit/i', $code) === 1) {
            $out[] = '又写了一条审计 INSERT';
        }
        if (preg_match("/Shard::table\(\s*['\"]kode_audit_logs/", $code) === 1) {
            $out[] = '又算了一次审计落片';
        }

        return $out;
    }

    /** 口径 3 的判据：代码里出现「自己读转发头配置」。 */
    private function readsProxyConfig(string $code): bool
    {
        return preg_match('/trusted_proxies/', $code) === 1;
    }

    /** 把片段里的字符串字面量按 PHP 源码那样嵌进一个函数体，再走同一套剥注释流程。 */
    private function strip(string $snippet): string
    {
        return $this->codeOnly("<?php\n\nfinal class Probe\n{\n    public function run(): void\n    {\n"
            . $snippet . "\n    }\n}\n");
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
