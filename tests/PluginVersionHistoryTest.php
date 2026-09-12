<?php

declare(strict_types=1);

namespace app\tests;

use app\support\PluginVersionHistory;
use PHPUnit\Framework\TestCase;

/**
 * 插件版本历史测试。
 *
 * 覆盖：record/list/previousVersion/rollback/changelogFor/checkCompatibility。
 *
 * 注意：需要 DB 连接。如果连接失败，测试自动 skip。
 */
final class PluginVersionHistoryTest extends TestCase
{
    private bool $dbAvailable = false;

    protected function setUp(): void
    {
        $this->dbAvailable = $this->checkDb();
        if (!$this->dbAvailable) {
            $this->markTestSkipped('DB 不可用，跳过版本历史测试');
        }
    }

    public function test_record_and_list(): void
    {
        $name = 'test_plugin_' . uniqid();
        PluginVersionHistory::record($name, '1.0.0', '1.1.0', PluginVersionHistory::OP_UPGRADE, 'Added feature X');

        $history = PluginVersionHistory::list($name);
        self::assertCount(1, $history);
        self::assertSame('1.0.0', $history[0]['from_version']);
        self::assertSame('1.1.0', $history[0]['to_version']);
        self::assertSame('upgrade', $history[0]['operation']);
        self::assertSame('Added feature X', $history[0]['changelog']);

        $this->cleanup($name);
    }

    public function test_list_respects_limit(): void
    {
        $name = 'test_plugin_' . uniqid();
        for ($i = 1; $i <= 5; $i++) {
            PluginVersionHistory::record($name, "0.{$i}", "0.{$i}.1", PluginVersionHistory::OP_UPGRADE);
        }

        $history = PluginVersionHistory::list($name, 3);
        self::assertCount(3, $history);

        $this->cleanup($name);
    }

    public function test_previous_version_returns_older(): void
    {
        $name = 'test_plugin_' . uniqid();
        PluginVersionHistory::record($name, '1.0.0', '1.1.0', PluginVersionHistory::OP_UPGRADE);
        PluginVersionHistory::record($name, '1.1.0', '1.2.0', PluginVersionHistory::OP_UPGRADE);

        $prev = PluginVersionHistory::previousVersion($name);
        self::assertSame('1.1.0', $prev);

        $this->cleanup($name);
    }

    public function test_previous_version_no_upgrade(): void
    {
        $name = 'test_plugin_' . uniqid();
        PluginVersionHistory::record($name, '0.0.0', '1.0.0', PluginVersionHistory::OP_INSTALL);

        $prev = PluginVersionHistory::previousVersion($name);
        self::assertNull($prev);

        $this->cleanup($name);
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
        } catch (\Throwable) {
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
        } catch (\Throwable) {
            // ignore
        }
    }
}
