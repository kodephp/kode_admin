<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Database\Db\Db;
use PHPUnit\Framework\TestCase;

/**
 * 插件测试的「僵尸登记」金丝雀。
 *
 * 挡的是这一类：写 kode_plugins 的用例把 cleanup 写在方法末尾，一旦中途断言失败就不执行，
 * 于是**一次红跑**往开发库里留一批 test_plugin_xxx / zz_xxx 僵尸行 —— 它们会出现在
 * 插件列表页上（实测留过 7 条），而写它们的那个文件下一跑照样绿，因为每个用例都自己造新名字。
 *
 * 这个文件不造数据、只核对：库里不得残留任何「测试专用前缀」的行。
 * 前缀清单 = 各测试文件自报的临时命名口径（见下方 PREFIX_OWNERS）；
 * 新增一个会写 kode_plugins 的测试文件时，把它的前缀一起登记进来，否则它漏的行没人管。
 *
 * 顺序口径：主套件按文件名字母序，本文件若混在里面会先于 PluginVersionHistoryTest 等
 * 写库用例跑，只能抓到**上一次**跑漏的行；phpunit.xml 把它从主套件 exclude 出去、
 * 单列成「Leak Canary」套件排到最后，抓的是本次跑刚漏的。
 */
final class PluginTestRowLeakTest extends TestCase
{
    /**
     * 临时插件名前缀 => 谁在用。用 str_starts_with 精确比对，
     * 不走 SQL LIKE：LIKE 的 `_` 是单字符通配符，'test_plugin%' 实际能匹配到
     * 'testXplugin...' 一类的名字，等于把判定面放宽。
     */
    private const PREFIX_OWNERS = [
        'test_plugin_' => 'PluginVersionHistoryTest',
        'dep_probe_' => 'PluginDependencyTest',
        'uninst_gate_' => 'PluginUninstallDepGateTest',
        'zz' => 'PluginBackupRestoreTest / PluginSupplyChainTest',
    ];

    private function hasDb(): bool
    {
        try {
            Db::select('SELECT 1 AS ok');

            return true;
        } catch (\Exception $e) {
            // 只吞 \Exception（连接层失败）：\Error 一律让它炸。
            // 本文件初版把 FQCN 写成 `Kode\Database\Db`（真实是 `Kode\Database\Db\Db`），
            // 那次 catch (\Throwable) 把「类不存在」渲染成「DB 不可用，跳过」，
            // 整类静默 skip、输出行仍写着「挡的就是这条」—— 正是「API 用错被显示成没有数据」。
            // 是插入一行僵尸做红对照时才暴露的。
            $this->markTestSkipped('DB 不可用，跳过僵尸登记核对：' . $e->getMessage());
        }
    }

    public function test_no_stray_plugin_rows(): void
    {
        \Kode\Framework\Application::make(dirname(__DIR__));
        $this->hasDb();

        $stray = [];
        foreach (Db::select('SELECT name FROM kode_plugins ORDER BY name') as $row) {
            foreach (self::PREFIX_OWNERS as $prefix => $owner) {
                if (str_starts_with((string) $row['name'], $prefix)) {
                    $stray[] = $row['name'] . '（归属 ' . $owner . '）';
                }
            }
        }

        self::assertSame(
            [],
            $stray,
            'kode_plugins 残留测试行：' . implode('、', $stray)
            . '。原因是某个用例在断言处中断、末尾的清理没跑到；'
            . "手工删除：DELETE FROM kode_plugin_versions WHERE name LIKE ...; DELETE FROM kode_plugins WHERE name = ...;"
            . '并已把清理挂进该类 tearDown。'
        );
    }

    public function test_no_stray_version_ledger_rows(): void
    {
        \Kode\Framework\Application::make(dirname(__DIR__));
        $this->hasDb();

        $stray = [];
        foreach (Db::select('SELECT name FROM kode_plugin_versions ORDER BY name') as $row) {
            foreach (self::PREFIX_OWNERS as $prefix => $owner) {
                if (str_starts_with((string) $row['name'], $prefix)) {
                    $stray[] = $row['name'] . '（归属 ' . $owner . '）';
                }
            }
        }

        self::assertSame(
            [],
            $stray,
            'kode_plugin_versions 残留测试行：' . implode('、', $stray)
            . '。版本账本只记真实插件，僵尸行会让 rollback 候选里冒出「从没装过的插件」。'
        );
    }
}
