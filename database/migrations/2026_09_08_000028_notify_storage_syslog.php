<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 对标 webman 通用后台：通知中心 + 存储配置 + 系统日志。
 */
final class NotifyStorageSyslog extends Migration
{
    public function up(): void
    {
        Db::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS kode_notifications (
                id          BIGSERIAL PRIMARY KEY,
                user_id     BIGINT       NOT NULL DEFAULT 0,
                type        VARCHAR(32)  NOT NULL DEFAULT 'info',
                title       VARCHAR(128) NOT NULL,
                content     VARCHAR(2000) NOT NULL DEFAULT '',
                level       VARCHAR(16)  NOT NULL DEFAULT 'info',
                url         VARCHAR(255) NOT NULL DEFAULT '',
                is_read     SMALLINT     NOT NULL DEFAULT 0,
                read_at     TIMESTAMPTZ  NULL,
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
            SQL);
        Db::statement('CREATE INDEX IF NOT EXISTS kode_notifications_user_idx ON kode_notifications (user_id)');
        Db::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS kode_storage_configs (
                id          BIGSERIAL PRIMARY KEY,
                name        VARCHAR(64)  NOT NULL,
                driver      VARCHAR(16)  NOT NULL DEFAULT 'local',
                config      TEXT         NOT NULL DEFAULT '{}',
                is_default  SMALLINT     NOT NULL DEFAULT 0,
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
            SQL);
        Db::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS kode_system_logs (
                id          BIGSERIAL PRIMARY KEY,
                level       VARCHAR(16)  NOT NULL DEFAULT 'info',
                module      VARCHAR(64)  NOT NULL DEFAULT '',
                message     VARCHAR(500) NOT NULL,
                context     TEXT         NOT NULL DEFAULT '{}',
                ip          VARCHAR(64)  NOT NULL DEFAULT '',
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
            SQL);
        Db::statement('CREATE INDEX IF NOT EXISTS kode_system_logs_level_idx ON kode_system_logs (level)');
        $exists = Db::select('SELECT id FROM kode_storage_configs WHERE driver = ? LIMIT 1', ['local']);
        if ($exists === []) {
            Db::insert(
                "INSERT INTO kode_storage_configs (name, driver, config, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())",
                ['本地存储', 'local', '{}', 1]
            );
        }
        $menus = [
            ['通知中心', '/notifications', 'bell', 'system', 37, 'notices:view'],
            ['存储配置', '/storage', 'cloud', 'system', 76, 'configs:view'],
            ['系统日志', '/syslogs', 'file-search', 'system', 81, 'audits:view'],
        ];
        foreach ($menus as [$title, $path, $icon, $plugin, $sort, $code]) {
            $exists = Db::select('SELECT id FROM kode_menus WHERE path = ? LIMIT 1', [$path]);
            if ($exists !== []) {
                continue;
            }
            Db::insert(
                'INSERT INTO kode_menus (title, path, icon, plugin, sort, auth_code) VALUES (?, ?, ?, ?, ?, ?)',
                [$title, $path, $icon, $plugin, $sort, $code]
            );
        }
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
