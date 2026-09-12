<?php

declare(strict_types=1);

use Kode\Database\Db\Db;
use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

/**
 * 插件版本历史表：记录每次升级/回滚的版本变更。
 *
 * 用途：
 *   - 升级前展示变更日志（changelog 字段）
 *   - 回滚时定位上一个版本
 *   - 审计：谁在何时把插件从 A 升到了 B
 */
final class CreatePluginVersionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('kode_plugin_versions', function (Schema $t): void {
            $t->id();
            $t->string('name', 64);            // 插件名（与 kode_plugins.name 对齐）
            $t->string('from_version', 32);    // 升级前版本（回滚时为升级后的版本）
            $t->string('to_version', 32);      // 升级后版本（回滚时为升级前的版本）
            $t->text('changelog');             // 变更日志（从 plugin.json 或 CHANGELOG.md 提取）
            $t->string('operation', 16);       // upgrade / rollback / install / uninstall
            $t->string('upgraded_by', 64);     // 操作者（JWT sub 或 'system'）
            $t->text('detail');                // 附加信息（JSON：依赖校验结果等）
            $t->timestamp('created_at');
        });

        // 索引按方言创建（PostgreSQL 不支持 CREATE TABLE 内联 INDEX）
        $driver = Db::getDriver();
        if ($driver === 'pgsql') {
            Db::statement('CREATE INDEX IF NOT EXISTS kode_plugin_versions_name_idx ON kode_plugin_versions (name)');
        } else {
            Db::statement('CREATE INDEX kode_plugin_versions_name_idx ON kode_plugin_versions (name)');
        }
    }

    public function down(): void
    {
        $driver = Db::getDriver();
        if ($driver === 'pgsql') {
            Db::statement('DROP INDEX IF EXISTS kode_plugin_versions_name_idx');
        } else {
            Db::statement('DROP INDEX IF EXISTS kode_plugin_versions_name_idx ON kode_plugin_versions');
        }
        Schema::drop('kode_plugin_versions');
    }
}
