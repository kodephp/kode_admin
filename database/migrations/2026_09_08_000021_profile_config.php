<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 双配置模型 + 头像 + 登录日志 + 恢复配置中心菜单：
 * - kode_sys_configs 加 ctype/ctitle/cgroup/sort（对齐 Setting/CustomConfig：类型化分组配置）；
 * - kode_users 加 avatar；
 * - kode_login_logs（后台登录日志：成功/失败，UA/设备/IP）；
 * - 恢复 /configs 菜单（自定义配置入口，与系统设置并存）。
 */
final class ProfileConfigUpgrade extends Migration
{
    public function up(): void
    {
        Db::statement("ALTER TABLE kode_sys_configs ADD COLUMN IF NOT EXISTS ctype VARCHAR(16) NOT NULL DEFAULT 'text'");
        Db::statement("ALTER TABLE kode_sys_configs ADD COLUMN IF NOT EXISTS ctitle VARCHAR(64) NOT NULL DEFAULT ''");
        Db::statement("ALTER TABLE kode_sys_configs ADD COLUMN IF NOT EXISTS cgroup VARCHAR(32) NOT NULL DEFAULT 'custom'");
        Db::statement('ALTER TABLE kode_sys_configs ADD COLUMN IF NOT EXISTS sort INTEGER NOT NULL DEFAULT 0');
        Db::statement("ALTER TABLE kode_users ADD COLUMN IF NOT EXISTS avatar VARCHAR(512) NOT NULL DEFAULT ''");
        Db::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS kode_login_logs (
                id          BIGSERIAL PRIMARY KEY,
                user_id     BIGINT       NOT NULL DEFAULT 0,
                username    VARCHAR(64)  NOT NULL DEFAULT '',
                ip          VARCHAR(64)  NOT NULL DEFAULT '',
                user_agent  VARCHAR(512) NOT NULL DEFAULT '',
                device      VARCHAR(128) NOT NULL DEFAULT '',
                status      SMALLINT     NOT NULL DEFAULT 0,
                fail_reason VARCHAR(255) NOT NULL DEFAULT '',
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
            SQL);
        Db::statement('CREATE INDEX IF NOT EXISTS kode_login_logs_user_idx ON kode_login_logs (user_id)');
        $exists = Db::select('SELECT id FROM kode_menus WHERE path = ? LIMIT 1', ['/configs']);
        if ($exists === []) {
            Db::insert(
                'INSERT INTO kode_menus (title, path, icon, plugin, sort, auth_code) VALUES (?, ?, ?, ?, ?, ?)',
                ['配置中心', '/configs', 'database', 'system', 70, 'configs:view']
            );
        }
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
