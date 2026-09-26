<?php

declare(strict_types=1);

namespace app\tests;

use app\support\PluginMarket;
use app\support\PluginVersionHistory;
use PHPUnit\Framework\TestCase;

/**
 * 插件版本历史测试。
 *
 * 覆盖：record / list / changeVersion（改列 + 留痕的唯一派发口）
 * / rollback（含「目标必须是登记过的版本」这一条约束）/ changelogFor / checkCompatibility。
 *
 * rollback 一组的价值在于它此前**没有**测试：文件头的自述写着覆盖 rollback，
 * 而整个类一次都没调过它 —— 于是「回滚把版本列改成了从没存在过的版本号」这种
 * 缺陷既没有测试挡、又被自述掩盖着。「版本列只能由 changeVersion 改」的静态口径
 * 在 PluginVersionWriteGateTest。
 *
 * 注意：需要 DB 连接。如果连接失败，测试自动 skip。
 */
final class PluginVersionHistoryTest extends TestCase
{
    private bool $dbAvailable = false;

    private string $dbSkipReason = '';

    /** @var list<string> 本次跑落进 kode_plugins / kode_plugin_versions 的临时插件名 */
    private array $seeded = [];

    protected function tearDown(): void
    {
        // 清理挂在 tearDown 而不是每个用例末尾：写在末尾的 cleanup 一旦被断言打断就不执行，
        // 于是「一次红跑」往开发库里留一堆 test_plugin_xxx 僵尸登记（实测留过 7 条，
        // 它们会出现在插件列表页上）。挡这条的运行时口径见 PluginTestRowLeakTest。
        foreach ($this->seeded as $name) {
            $this->cleanup($name);
        }
        $this->seeded = [];

        parent::tearDown();
    }

    protected function setUp(): void
    {
        // 必须先装配应用再探 DB：本类此前不 boot，于是 phpunit 进程里没有 .env，
        // Db 拿到的默认连接是「mysql + 空账号」，checkDb() 的 catch (\Throwable) 把
        // Access denied 吞成「DB 不可用」，11 个用例**全部静默 skip** —— 套件只报
        // "OK, but some tests were skipped"，于是这个文件从没跑过一行也没人发现。
        // （PluginDependencyTest 一开始就 make()，是它跑得动的原因。）
        \Kode\Framework\Application::make(dirname(__DIR__));

        $this->dbAvailable = $this->checkDb();
        if (!$this->dbAvailable) {
            $this->markTestSkipped('DB 不可用，跳过版本历史测试：' . $this->dbSkipReason);
        }
    }

    public function test_record_and_list(): void
    {
        $name = $this->tempName();
        PluginVersionHistory::record($name, '1.0.0', '1.1.0', PluginVersionHistory::OP_UPGRADE, 'Added feature X');

        $history = PluginVersionHistory::list($name);
        self::assertCount(1, $history);
        self::assertSame('1.0.0', $history[0]['from_version']);
        self::assertSame('1.1.0', $history[0]['to_version']);
        self::assertSame('upgrade', $history[0]['operation']);
        self::assertSame('Added feature X', $history[0]['changelog']);
    }

    public function test_list_respects_limit(): void
    {
        $name = $this->tempName();
        for ($i = 1; $i <= 5; $i++) {
            PluginVersionHistory::record($name, "0.{$i}", "0.{$i}.1", PluginVersionHistory::OP_UPGRADE);
        }

        $history = PluginVersionHistory::list($name, 3);
        self::assertCount(3, $history);
    }

    public function test_check_compatibility_version_regression(): void
    {
        $result = PluginVersionHistory::checkCompatibility('nonexistent', '2.0.0', '1.0.0');
        self::assertFalse($result['ok']);
        self::assertNotEmpty($result['blockers']);
    }

    public function test_check_compatibility_plugin_not_found(): void
    {
        $result = PluginVersionHistory::checkCompatibility('nonexistent_plugin_xyz', '1.0.0', '2.0.0');
        // 依赖校验可能通过（插件不存在时依赖也可能 ok），但版本比较应通过
        self::assertTrue($result['ok']);
    }

    public function test_changelog_for_empty_manifest(): void
    {
        $changelog = PluginVersionHistory::changelogFor('nonexistent', '1.0.0', '2.0.0');
        self::assertSame('', $changelog);
    }

    public function test_change_version_writes_the_column_and_the_ledger_together(): void
    {
        $name = $this->seed();

        // setStatus 建的行自带 1.0.0；显式交代「装之前并没有登记」，账本才不会写出假前置版本
        PluginVersionHistory::changeVersion($name, '1.0.0', PluginVersionHistory::OP_INSTALL, '', 'tester', [], '');
        self::assertSame('1.0.0', $this->versionOf($name));
        $rows = PluginVersionHistory::list($name);
        self::assertCount(1, $rows, '安装必须留痕：账本此前只记 upgrade 的账');
        self::assertSame('', (string) $rows[0]['from_version']);
        self::assertSame(PluginVersionHistory::OP_INSTALL, (string) $rows[0]['operation']);
        self::assertSame('tester', (string) $rows[0]['upgraded_by']);

        PluginVersionHistory::changeVersion($name, '1.1.0', PluginVersionHistory::OP_UPGRADE, 'feat X', 'tester');
        self::assertSame('1.1.0', $this->versionOf($name));
        self::assertCount(2, PluginVersionHistory::list($name));

        // 同版本重复调用不灌噪声行（否则时间线会被「一样的两条」刷满）
        PluginVersionHistory::changeVersion($name, '1.1.0', PluginVersionHistory::OP_UPGRADE, '', 'tester');
        self::assertCount(2, PluginVersionHistory::list($name), '版本没变就不该记账');

    }

    public function test_rollback_refuses_a_version_that_was_never_registered(): void
    {
        $name = $this->seed();
        PluginVersionHistory::changeVersion($name, '1.0.0', PluginVersionHistory::OP_INSTALL, '', 'tester', [], '');
        PluginVersionHistory::changeVersion($name, '1.1.0', PluginVersionHistory::OP_UPGRADE, '', 'tester');

        $r = PluginVersionHistory::rollback($name, '9.9.9', 'tester');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('不是该插件登记过的版本', $r['message']);
        self::assertSame(['1.0.0'], $r['candidates'], '候选要如实列出（账本里只有 1.0.0 可回退）');
        self::assertSame('1.1.0', $this->versionOf($name), '被拒的回滚不得改动版本列');
        self::assertCount(2, PluginVersionHistory::list($name), '被拒的回滚不得留账本行');

    }

    public function test_rollback_moves_the_registered_version_and_says_so(): void
    {
        $name = $this->seed();
        PluginVersionHistory::changeVersion($name, '1.0.0', PluginVersionHistory::OP_INSTALL, '', 'tester', [], '');
        PluginVersionHistory::changeVersion($name, '1.1.0', PluginVersionHistory::OP_UPGRADE, '', 'tester');

        $r = PluginVersionHistory::rollback($name, '1.0.0', 'tester');
        self::assertTrue($r['ok'], $r['message']);
        self::assertSame('1.0.0', $this->versionOf($name));
        // 文案必须承认「只改了登记、代码没动」——否则管理员以为回滚把插件换回去了
        self::assertStringContainsString('插件代码未变', $r['message']);
        self::assertStringContainsString('备份恢复', $r['message']);

        $latest = PluginVersionHistory::list($name)[0];
        self::assertSame(PluginVersionHistory::OP_ROLLBACK, (string) $latest['operation']);
        self::assertSame('1.1.0', (string) $latest['from_version']);
        self::assertSame('1.0.0', (string) $latest['to_version']);

    }

    public function test_rollback_refuses_a_missing_plugin_and_the_current_version(): void
    {
        $r = PluginVersionHistory::rollback('test_plugin_not_installed', '1.0.0', 'tester');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('未安装', $r['message']);

        $name = $this->seed();
        $same = PluginVersionHistory::rollback($name, '1.0.0', 'tester');
        self::assertFalse($same['ok']);
        self::assertStringContainsString('当前已是目标版本', $same['message']);
        self::assertCount(0, PluginVersionHistory::list($name));

    }

    /**
     * 取一个本次跑负责的临时插件名（登记给 tearDown）。
     */
    private function tempName(): string
    {
        $name = 'test_plugin_' . uniqid();
        $this->seeded[] = $name;

        return $name;
    }

    /**
     * 装一条登记：kode_plugins 建行（setStatus 的默认版本 1.0.0）。
     *
     * 用 setStatus 而不是手写 INSERT：它就是生产 install 的第一步，测试因此跟着它的
     * 默认值走，而不是跟着一个我自己编的形状走。
     */
    private function seed(): string
    {
        $name = $this->tempName();
        PluginMarket::setStatus($name, PluginMarket::ENABLED);

        return $name;
    }

    private function versionOf(string $name): string
    {
        return (string) (PluginMarket::states()[$name]['version'] ?? '');
    }

    private function checkDb(): bool
    {
        try {
            \Kode\Database\Db\Db::select('SELECT 1');
            // 检查目标表是否存在（兼容 MySQL/PostgreSQL）
            $driver = \Kode\Database\Db\Db::getDriver();
            if ($driver === 'pgsql') {
                $tables = \Kode\Database\Db\Db::select(
                    "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?",
                    ['kode_plugin_versions']
                );
            } else {
                $tables = \Kode\Database\Db\Db::select(
                    'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                    ['kode_plugin_versions']
                );
            }
            return count($tables) > 0;
        } catch (\Throwable $e) {
            // 原因必须出现在 skip 文案里：上一版的「无声吞掉 + 一句 DB 不可用」把
            // 「凭证没加载」伪装成「这台机器没库」，看输出根本分不开。
            $this->dbSkipReason = $e->getMessage();

            return false;
        }
    }

    private function cleanup(string $name): void
    {
        try {
            \Kode\Database\Db\Db::update(
                'DELETE FROM kode_plugin_versions WHERE name = ?',
                [$name]
            );
            // seed() 往 kode_plugins 建了行，就得自己拆掉：漏一次等于往开发库里
            // 堆 test_plugin_xxx 僵尸登记（它们会出现在插件列表页上）。
            \Kode\Database\Db\Db::update(
                'DELETE FROM kode_plugins WHERE name = ?',
                [$name]
            );
        } catch (\Throwable) {
            // ignore
        }
    }
}
