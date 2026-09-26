<?php

declare(strict_types=1);

namespace app\tests;

use PHPUnit\Framework\TestCase;

/**
 * 静态门禁：凡「枚举分片清单」的文件，逐片执行必须走 `Shard::scan()` 这一个派发口。
 *
 * 治的是读侧那两类互相放大的坏法（`AGENTS.md` §3「禁止把结构错误渲染成没有数据」）：
 *  1. 拿着分片表名去 `Db::connection('shard_02')` —— 未注册的名字不抛错而是**回落全局配置**，
 *     于是查的是主库；主库有残留同名片表时读到的是别处的数据，`total` 还会乘重复；
 *  2. 每片一个 `try { ... } catch (\Throwable) { continue; }` —— 缺片、库不可达、片表少列
 *     都只让列表少一截，页面与「这台系统没有数据」长得一模一样。
 *
 * 两条的解法是同一个：唯一派发口 `Shard::scan()` 里既挡未注册连接，又把失败片连原因带回来。
 * 所以门禁不查「有没有 catch」（合法的重试也用它），而查「枚举了清单却没走派发口」。
 *
 * 门禁自己也要被扫：`test_scanner_actually_scans` 把收口前的三处真实写法各喂一遍（必须命中），
 * 再验 needle 在真实文件里命中在它该命中的地方 —— 否则「正则写错、什么都扫不到」和
 * 「代码真的干净」在输出上长得一样。剥注释是必需的：`Shard::scan()` 的 docblock 里就抄着
 * 反面教材的 `catch (\Throwable) { continue; }`。
 */
final class ShardReadDispatchGateTest extends TestCase
{
    /** 派发口所在的文件（它自己就是那份实现，不参与「必须调用它」的对账）。 */
    private const DISPATCH_HOME = 'app/support/Shard.php';

    /** 枚举分片清单的写法（readUnits 是片清单、tables 是片表名清单）。 */
    private const ENUM_PAT = '/Shard::readUnits\s*\(|Shard::tables\s*\(|\bself::readUnits\s*\(|\bstatic::readUnits\s*\(/';

    /** 走派发口的写法：调用 scan，或本身就是 scan 的定义处。 */
    private const DISPATCH_PAT = '/(?:Shard|self|static)::scan\s*\(/';

    /** 旧写法一：分片总览面板逐片 COUNT，异常吞掉、total 静默偏小（收口前的 MonitorController）。 */
    private const OLD_COUNT_LOOP = <<<'PHP'
        <?php
        foreach (\app\support\Shard::tables($base) as $table) {
            $count = null;
            try {
                $count = (int) (Db::select("SELECT COUNT(*) AS c FROM {$table}")[0]['c'] ?? 0);
                $total += $count;
            } catch (\Throwable) {
            }
            $tables[] = ['table' => $table, 'rows' => $count];
        }
        PHP;

    /** 旧写法二：逐片 try/catch + continue 把缺片吃成「这一片没有数据」（收口前的 AuditController）。 */
    private const OLD_CONTINUE_LOOP = <<<'PHP'
        <?php
        foreach (Shard::readUnits($base, false, false) as $unit) {
            try {
                $rows = Shard::db($unit[0])->select("SELECT * FROM {$unit[1]} LIMIT ?", [100]);
            } catch (\Throwable) {
                continue;
            }
            $all = array_merge($all, $rows);
        }
        PHP;

    /** 旧写法三：拿未注册的连接名当库用（Db::connection 不抛错，回落主库配置）。 */
    private const OLD_RAW_CONNECTION = <<<'PHP'
        <?php
        $units = Shard::readUnits($base);
        foreach ($units as $unit) {
            $rows = Db::connection((string) $unit[0])->select("SELECT * FROM {$unit[1]} LIMIT ?", [100]);
        }
        PHP;

    /** 正对照：新写法的三条都必须是绿（判据只在一处，反向不成立就是它扫错了东西）。 */
    private const CURRENT_SHAPES = [
        '调用派发口' => <<<'PHP'
            <?php
            $scan = Shard::scan(Shard::readUnits($base, false, false), static function (?string $conn, string $table) {
                return Shard::db($conn)->select("SELECT * FROM {$table} LIMIT ?", [100]);
            });
            PHP,
    ];

    /** 逐文件对账：枚举了片清单却没走派发口的，一律列出。 */
    public function test_every_shard_enumeration_goes_through_the_dispatch_point(): void
    {
        $offenders = [];
        $enumerating = 0;

        foreach (self::firstPartyFiles() as $file) {
            if (self::relative($file) === self::DISPATCH_HOME) {
                continue;
            }
            $code = self::codeOnly((string) file_get_contents($file));
            if (preg_match(self::ENUM_PAT, $code) !== 1) {
                continue;
            }
            $enumerating++;
            if (preg_match(self::DISPATCH_PAT, $code) !== 1) {
                $offenders[] = self::relative($file);
            }
        }

        self::assertSame(
            [],
            $offenders,
            '这些地方枚举了分片清单却自己写了逐片循环：缺片/连不上的片会被吃成「没有数据」，'
            . '而未注册的分片连接会被当成主库用（逐片执行只应由 Shard::scan() 派发）：'
            . "\n  " . implode("\n  ", $offenders)
        );
        self::assertGreaterThanOrEqual(
            5,
            $enumerating,
            '只扫到 ' . $enumerating . ' 个枚举分片的文件：读侧收口的射程不像真的（派发口应当被多处用到）'
        );
    }

    /**
     * 门禁自己：三处收口前的真实写法必须逐个判红，新写法必须绿。
     *
     * 这一条挡的是「正则写错，于是门禁永远全绿」—— 而全绿正是它最危险的时候，
     * 因为它会让人相信「这里已经没有手搓循环了」。
     */
    public function test_scanner_actually_scans(): void
    {
        foreach ([
            '逐片 COUNT 吞异常' => self::OLD_COUNT_LOOP,
            '逐片 try/catch + continue' => self::OLD_CONTINUE_LOOP,
            '未注册连接当库用' => self::OLD_RAW_CONNECTION,
        ] as $label => $old) {
            self::assertSame(
                1,
                preg_match(self::ENUM_PAT, $old),
                $label . '：连「枚举了分片清单」都扫不出来，needle 写错了'
            );
            self::assertSame(
                0,
                preg_match(self::DISPATCH_PAT, $old),
                $label . '：旧写法里出现了派发口调用，门禁判不出分叉'
            );
        }

        foreach (self::CURRENT_SHAPES as $label => $code) {
            self::assertSame(1, preg_match(self::ENUM_PAT, $code), $label . '：新写法的枚举形态与 needle 不符');
            self::assertSame(1, preg_match(self::DISPATCH_PAT, $code), $label . '：新写法的派发形态与 needle 不符');
        }
    }

    /**
     * 派发口本身必须还带着那两道闸门。
     *
     * 对账只保证「大家都调它」；它自己被人改薄了（比如未注册连接的判定被删）就全都没了。
     * 这里按**结构性标记**核对而不是整段文本比对：改名可以，删闸门不行。
     */
    public function test_the_dispatch_point_still_holds_both_gates(): void
    {
        $root = realpath(dirname(__DIR__));
        self::assertNotFalse($root);
        $code = self::codeOnly((string) file_get_contents($root . '/' . self::DISPATCH_HOME));

        self::assertMatchesRegularExpression(
            '/Db::hasConnection\s*\(/',
            $code,
            '派发口不再核对连接是否注册：未注册的 shard_NN 会回落主库配置，读到别处的数据'
        );
        self::assertMatchesRegularExpression(
            '/\$unreadable\[\]\s*=\s*\[/',
            $code,
            '派发口不再收集失败片：读不出来的片就只剩下「被 continue 跳过」这一条路'
        );
        self::assertMatchesRegularExpression(
            '/function\s+failureReason\s*\(/',
            $code,
            '失败原因的分类口径没了：响应里只会有一句原始异常文本'
        );
    }

    // ─── 私有 ───────────────────────────────────────────────

    /**
     * 剥掉注释后的代码文本。
     *
     * 必须剥：`Shard::scan()` 的 docblock 里抄着 `catch (\Throwable) { continue; }` 这段反面教材，
     * 而本门禁的射程正是「枚举 + 手搓循环」，不剥注释就会把说明文字判成违规（然后被人顺手关掉门禁）。
     */
    private static function codeOnly(string $src): string
    {
        $out = '';
        foreach (\token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /** @return list<string> app/ 与 plugins/ 下的全部 php 文件 */
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

    private static function relative(string $file): string
    {
        return str_replace(realpath(dirname(__DIR__)) . '/', '', $file);
    }
}
