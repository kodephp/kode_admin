<?php

declare(strict_types=1);

namespace app\tests;

use PHPUnit\Framework\TestCase;

/**
 * 静态门禁：`kode_plugins.version` 只能由 `PluginVersionHistory::changeVersion()` 改。
 *
 * 为什么必须是「一个派发口」而不是「四处各自记得调一次 record()」：
 * 版本列与账本（`kode_plugin_versions`）是同一件事的两半 —— 列回答「现在是什么版本」，
 * 账本回答「是怎么变成这个版本号的」。此前只有 `POST /api/plugins/{name}/upgrade`
 * 两半都写，install、远程安装、备份恢复三处只改列。结果账本看着是完整的历史记录，
 * 实际只记了四分之一的账，而 `rollback()` 全建立在这张账本上：
 * 没记 install 的插件，回滚目标算出来是空的；记了升级却没记恢复的插件，
 * 回滚会把「恢复带进来的版本号」当成一次升级留痕。
 *
 * 三道断言各挡一种「门禁自己坏掉」：
 *   - 允许清单必须**真的命中**（改名/挪文件让规则变成空规则时，这里先红）；
 *   - 扫描文件数有下限（扫错目录会「全绿」）；
 *   - 允许清单外的命中即违规。
 */
final class PluginVersionWriteGateTest extends TestCase
{
    /**
     * 规则 => 允许出现的文件（相对项目根）。
     *
     * 第二条挡的是「绕过 setVersion 直接写 SQL」：那条路连 PluginMarket 都不经过，
     * 只看第一条规则是发现不了的。
     *
     * @var array<string, list<string>>
     */
    private const RULES = [
        'PluginMarket::setVersion(' => ['app/support/PluginVersionHistory.php'],
        'UPDATE kode_plugins SET version' => ['app/support/PluginMarket.php'],
    ];

    /** 扫描到的 php 文件数下限（当前约 120，只挡「扫错目录 / 目录搬家」）。 */
    private const MIN_SCANNED_FILES = 100;

    public function test_the_version_column_is_written_from_exactly_one_place(): void
    {
        $files = self::firstPartyFiles();
        $sources = [];
        foreach ($files as $file) {
            $sources[self::relative($file)] = self::codeOnly($file);
        }

        foreach (self::RULES as $needle => $allowed) {
            $hits = [];
            foreach ($sources as $relative => $code) {
                $count = substr_count($code, $needle);
                if ($count > 0) {
                    $hits[$relative] = $count;
                }
            }

            // 正对照：规则得在允许的那一处真的命中，否则这条规则已经在扫空气
            foreach ($allowed as $file) {
                self::assertArrayHasKey(
                    $file,
                    $hits,
                    "「{$needle}」在 {$file} 里已经不在了：规则失配，这条门禁现在是空转的，请一并更新它"
                );
            }

            // 反对照：允许清单外一处都不许有
            $offenders = array_diff_key($hits, array_flip($allowed));
            self::assertSame(
                [],
                $offenders,
                "发现绕过 PluginVersionHistory::changeVersion() 的版本写入。改版本必须与留账本同时发生，"
                . "请改为调用 changeVersion()（它同时改列并写 kode_plugin_versions）：\n  "
                . implode("\n  ", array_map(
                    static fn (string $f, int $n): string => $f . ' ×' . $n,
                    array_keys($offenders),
                    array_values($offenders)
                ))
            );
        }
    }

    /**
     * changeVersion 的记账条件必须还在：账本的完整性靠这一句，而不是靠调用者自觉。
     *
     * 「同版本不记账」与「版本真变了必记账」是一体两面；把条件删成无条件会灌噪声行，
     * 删成永不记则整张账本变空表 —— 两种都把这条门禁要治的病变成它自己的病。
     */
    public function test_changeVersion_still_writes_the_ledger_when_the_version_changes(): void
    {
        $code = self::codeOnly(dirname(__DIR__) . '/app/support/PluginVersionHistory.php');

        self::assertStringContainsString(
            'if ($from !== $version) {',
            $code,
            'changeVersion() 的记账条件被改动：账本与版本列的一致性不再由派发口保证'
        );
        self::assertMatchesRegularExpression(
            '/if \(\$from !== \$version\) \{\s*self::record\(/',
            $code,
            'changeVersion() 里记账条件之后必须紧跟着 record()'
        );
    }

    /**
     * 去注释这一步必须真的在去注释。
     *
     * 样本就是本文件：下面 `codeOnly()` 的说明注释里**原样写着**两条 needle，
     * 而源码里它们各出现一次（RULES 的两行）。于是「原文 2 次 / 去注释后 1 次」
     * 是一组自证的对照 —— 改这段注释时请一起改计数，别把它删了：
     * 没有这一步，「注释里的字面量算不算违规」就只是我说了算。
     */
    public function test_comment_text_is_not_counted_as_a_write(): void
    {
        $self = __DIR__ . '/' . basename(__FILE__);
        $raw = (string) file_get_contents($self);
        $code = self::codeOnly($self);

        foreach (array_keys(self::RULES) as $needle) {
            self::assertSame(
                2,
                substr_count($raw, $needle),
                "本文件原文里「{$needle}」应出现 2 次（RULES 一行 + 下面那段注释），计数变了请同步这条对照"
            );
            self::assertSame(
                1,
                substr_count($code, $needle),
                "去注释后「{$needle}」只剩 RULES 那一处；仍是 2 次说明 codeOnly() 没去掉注释，"
                . "本门禁会把文档里的字面量判成违规"
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function firstPartyFiles(): array    {
        $root = dirname(__DIR__);
        $files = [];

        foreach (['app', 'plugins'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
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

    /**
     * 去掉注释后的源码。
     *
     * 本类的说明性注释里就写着 `PluginMarket::setVersion(` 与 `UPDATE kode_plugins SET version`
     * 的原文（为了讲清「为什么禁」），拿原文扫会把注释当成违规，于是大家把注释删掉 ——
     * 门禁反过来吃掉解释自己的那段话。
     */
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
