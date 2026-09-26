<?php

declare(strict_types=1);

namespace app\tests;

use app\support\DbBackup;
use PHPUnit\Framework\TestCase;

/**
 * 静态门禁：备份范围不许再被写死，恢复不许再对退出码撒谎。
 *
 * 旧实现的两处「常量口径」各自都成立，合起来就是一份危险的备份：
 *  1. `DbBackup::TABLES` 是一份 15 项常量，里面原样写着
 *     `'kode_audit_logs_00'、'kode_audit_logs_01'、'kode_audit_logs_02'、'kode_audit_logs_03'`
 *     —— 片名钉死在 table_shards=4 的那一刻，扩片/缩片之后产物与库里的东西不再是同一批，
 *     而主库实际有 47 张表，插件建的所有表一张都不在这份清单里；
 *  2. 恢复用 `psql -f` 且不收退出码 —— 实测 psql 不加 `ON_ERROR_STOP` 时**中间语句报错照样
 *     exit 0**，于是「一行都没恢复」在接口上回的是「恢复完成」；
 *  3. 修 2 的时候在控制器里自己抄了一份 `proc_open`，fd2 写成 `['stream', 'php://stdout']`
 *     —— PHP 的 stream 描述符只认已打开的资源，字符串路径让它**直接返回 false**，
 *     恢复接口从此对任何人固定 500。抄第二份子进程调用就是把第二个坑复制一遍，
 *     所以恢复的子进程也只留 `app\support\DbRestore` 一处。
 *
 * 行为断言在 `BackupScopeTest`（现场新建的表必须进范围、写进分片库的一行必须出现在产物里）
 * 与 `BackupRestoreE2ETest`（真产物在真服务器上恢复得回来、坏一节只回滚自己）。
 * 这里挡的是**写法回潮**：一旦有人为了「省事」再把片名抄回常量、把恢复命令里的
 * `ON_ERROR_STOP` 当成冗余删掉、或另起一份 `proc_open`，前者的行为断言要等到扩片那天才变红，
 * 后两种永远不会变红。
 *
 * 每条规则都带一组自证对照（needle 必须在允许处真的命中、被禁的形状必须真的判红、
 * 注释里的字面量不算命中），否则「门禁全绿」和「门禁在扫空气」输出长得一样。
 */
final class BackupScopeGateTest extends TestCase
{
    /** 被禁的形状：引号包住的 `_NN` 结尾标识符 = 把某一片的名字抄进了代码。 */
    private const SHARD_LITERAL = '/[\'"][A-Za-z0-9_]*_[0-9]{2}[\'"]/';

    /** 恢复命令的必须形状。 */
    private const ON_ERROR_STOP = '/-v\s+ON_ERROR_STOP=1\b/';

    /** 被禁的形状：stream 描述符里塞的是字符串路径（proc_open 会直接返回 false）。 */
    private const BAD_STREAM_DESC = '/\[\s*[\'"]stream[\'"]\s*,\s*[\'"]/';

    /** 扫描到的 php 文件数下限（当前约 123，只挡「扫错目录 / 目录搬家」）。 */
    private const MIN_SCANNED_FILES = 100;

    public function test_no_shard_table_name_is_hardcoded_anywhere(): void
    {
        $hits = [];
        foreach (self::firstPartyFiles() as $file) {
            preg_match_all(self::SHARD_LITERAL, self::codeOnly($file), $found);
            if ($found[0] !== []) {
                $hits[self::relative($file)] = array_values(array_unique($found[0]));
            }
        }

        self::assertSame(
            [],
            $hits,
            "发现写死的分片/后缀表名：范围必须由 DbBackup::scope() 现场读各库的 pg_tables，"
            . "片名跟着 Shard::tableShards() 漂移（写死 = 扩片那天开始漏备）：\n  "
            . implode("\n  ", array_map(
                static fn (string $f): string => $f . ' => ' . implode(', ', $hits[$f]),
                array_keys($hits)
            ))
        );
    }

    /**
     * 正对照：上面那条正则得真的判得住旧写法，否则它只是在扫空气。
     *
     * 样本按旧常量的形状**拼**出来（不在本文件代码里写字面量 —— 类注释里那份才是
     * `test_comment_text_is_not_counted_as_a_violation` 的对照样本）。
     */
    public function test_the_rule_would_have_flagged_the_list_it_replaces(): void
    {
        $legacy = "['" . implode("', '", array_map(
            static fn (int $i): string => sprintf('kode_audit_logs_%02d', $i),
            range(0, 3)
        )) . "']";
        preg_match_all(self::SHARD_LITERAL, $legacy, $found);

        self::assertCount(4, $found[0], '正则没抓住旧常量的片名：这条门禁已经是空转的');
        self::assertContains(sprintf("'kode_audit_logs_%02d'", 2), $found[0]);

        // 反向：合法形状不许误伤（动态拼片名的 sprintf、LIKE 模式里的通配）
        foreach ([
            "sprintf('shard_%02d', \$d)",
            "tablename LIKE 'kode\\\\_%'",
            "'kode_notices'",
        ] as $ok) {
            preg_match_all(self::SHARD_LITERAL, $ok, $none);
            self::assertSame([], $none[0], "误伤合法写法：{$ok}");
        }
    }

    public function test_the_hardcoded_manifest_is_gone_and_the_live_one_is_public(): void
    {
        $class = new \ReflectionClass(DbBackup::class);

        self::assertFalse(
            $class->hasConstant('TABLES'),
            'DbBackup::TABLES 又回来了：任何常量表清单都会与现场脱钩，范围只能是 scope() 现读'
        );

        $scope = $class->getMethod('scope');
        self::assertTrue($scope->isStatic() && $scope->isPublic(), 'scope() 必须是公开的静态口径（控制器与任务共用一份）');

        // 库名必须来自那座库自己的报数，而不是配置的模板拼接
        $code = self::codeOnly(dirname(__DIR__) . '/app/support/DbBackup.php');
        self::assertStringContainsString(
            'SELECT current_database() AS db',
            $code,
            'section() 不再现查 current_database()：照 db_name 模板猜名字时，配置写错也能「看起来对」'
        );
        self::assertStringNotContainsString(
            "['database']",
            $code,
            'DbBackup 又从配置里读库名了：Db::getConfig() 对未注册连接会回落主库配置，猜出来的名字会 dump 错库'
        );
    }

    public function test_the_restore_command_still_stops_on_the_first_error(): void
    {
        $code = self::codeOnly(dirname(__DIR__) . '/app/support/DbRestore.php');

        self::assertSame(
            1,
            preg_match(self::ON_ERROR_STOP, $code),
            'DbRestore 的命令中 ON_ERROR_STOP 的位置/写法变了：psql 不加它时中间报错不影响退出码'
            . '（实测照样 exit 0），接口会在「一行都没恢复」时回「恢复完成」'
        );
        self::assertMatchesRegularExpression(
            '/proc_open\(/',
            $code,
            '恢复必须能拿到退出码：shell_exec 拿不到，退回它等于把上一条判据变成摆设'
        );
        self::assertStringNotContainsString(
            'shell_exec(',
            $code,
            'DbRestore 里出现了 shell_exec：拿不到退出码，等于恢复成功与否由 psql 的心情决定'
        );
        self::assertStringContainsString(
            'bool $stopOnError = true',
            $code,
            'ON_ERROR_STOP 的默认值被翻成关：所有没显式传参的调用点（含恢复接口）都会退回「报错也 exit 0」'
        );

        // 恢复的子进程只此一处 —— 抄第二份出去，就会第二份忘记某个坑（本轮已经发生过一次）
        $controller = self::codeOnly(dirname(__DIR__) . '/app/admin/controllers/BackupController.php');
        self::assertStringContainsString('DbRestore::run(', $controller, '恢复不再走 DbRestore：口径漂回调用方了');
        self::assertStringNotContainsString('proc_open(', $controller, '控制器里又自己起了一份恢复子进程');
    }

    /**
     * `proc_open` 的 stream 描述符只认「已打开的资源」，写路径字符串会让它直接返回 false。
     *
     * 本轮在 DbRestore 里就发生过一次：`2 => ['stream', 'php://stdout']` 让恢复接口
     * 对任何人都固定回 500「恢复进程启动失败」，而所有下游退出码判据都在「进程起来了」
     * 之后 —— 一条测试都不红。子进程调用点只有一处，写法统一由这条门禁钉住。
     */
    public function test_no_subprocess_descriptor_is_handed_a_path_string(): void
    {
        $hits = [];
        foreach (self::firstPartyFiles() as $file) {
            if (preg_match_all(self::BAD_STREAM_DESC, self::codeOnly($file), $found)) {
                $hits[self::relative($file)] = array_values(array_unique($found[0]));
            }
        }
        self::assertSame([], $hits, "['stream', ...] 的第二元必须是已打开的资源，字符串路径会让 proc_open 返回 false。"
            . "要收 stderr 用 ['pipe','w']，要丢弃用 ['file','/dev/null','w']：\n  "
            . implode("\n  ", array_map(
                static fn (string $f): string => $f . ' => ' . implode(', ', $hits[$f]),
                array_keys($hits)
            )));
    }

    /** 两头对照：坏写法必须判红，三种合法写法必须判绿。 */
    public function test_the_descriptor_rule_judges_both_ways(): void
    {
        self::assertSame(1, preg_match(self::BAD_STREAM_DESC, "\$d = [1 => ['pipe', 'w'], 2 => ['stream', 'php://stdout']];"));
        foreach ([
            "[2 => ['pipe', 'w']]",
            "[2 => ['file', '/dev/null', 'w']]",
            "[1 => ['stream', \$err]]",
        ] as $ok) {
            self::assertSame(0, preg_match(self::BAD_STREAM_DESC, $ok), "误伤合法写法：{$ok}");
        }
    }

    /**
     * 两头对照：旧命令串必须判不过，正确形状必须判得过。
     *
     * 只测「旧的不过」的话，一条永远不匹配的正则照样全绿 —— 而它挡不住任何东西。
     */
    public function test_the_rule_accepts_only_the_shape_that_actually_stops(): void
    {
        self::assertSame(
            0,
            preg_match(self::ON_ERROR_STOP, "psql -h %s -p %d -U %s -d %s -f %s 2>&1"),
            '旧命令串被判过了：这条门禁现在挡不住「删掉 ON_ERROR_STOP」的回潮'
        );
        self::assertSame(
            1,
            preg_match(self::ON_ERROR_STOP, "psql -h 127.0.0.1 -d kode_app -v ON_ERROR_STOP=1 -f /tmp/x.sql"),
            '正确形状判不过：正则在扫空气'
        );
        self::assertSame(
            0,
            preg_match(self::ON_ERROR_STOP, "psql -v ON_ERROR_STOP =1 -f %s"),
            'pset 式写法不是 psql 认的 -v KEY=VALUE，不该放过'
        );
    }

    /**
     * 去注释这一步必须真的在去注释。
     *
     * 样本就是本文件：类注释里**原样写着**被禁的旧片名，而代码里一处都没有。
     * 于是「原文命中 4 次 / 去注释后 0 次」是一组自证对照 —— 没有这一步，
     * 「解释为什么禁的那段话算不算违规」就只是我说了算，大家会开始删注释。
     */
    public function test_comment_text_is_not_counted_as_a_violation(): void
    {
        $self = __DIR__ . '/' . basename(__FILE__);
        $raw = (string) file_get_contents($self);
        $code = self::codeOnly($self);

        preg_match_all(self::SHARD_LITERAL, $raw, $found);
        self::assertSame(4, count($found[0]), '本文件注释里的旧片名个数变了，请同步这条对照');
        preg_match_all(self::SHARD_LITERAL, $code, $stripped);
        self::assertSame([], $stripped[0], '去注释后仍命中：codeOnly() 没去掉注释，门禁会把文档判成违规');
    }

    // ─── 私有 ───────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private static function firstPartyFiles(): array
    {
        $root = realpath(dirname(__DIR__));
        self::assertNotFalse($root, '项目根解析失败：扫描范围不可信');

        $files = [];
        foreach (['app', 'plugins'] as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        self::assertGreaterThanOrEqual(
            self::MIN_SCANNED_FILES,
            count($files),
            '只扫到 ' . count($files) . ' 个一方 php 文件：扫描范围缩了，这条门禁的结论不可信'
        );

        return $files;
    }

    private static function relative(string $file): string
    {
        return str_replace(dirname(__DIR__) . '/', '', $file);
    }

    /** 去掉注释后的源码（见 test_comment_text_is_not_counted_as_a_violation 的对照）。 */
    private static function codeOnly(string $file): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
