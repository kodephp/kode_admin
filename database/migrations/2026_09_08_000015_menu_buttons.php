<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 菜单按钮表（按钮级权限：每个按钮一个 code，角色按码授权）。
 * 约定：code 全局唯一（如 users:create），菜单删除时联带删除其按钮。
 */
final class CreateMenuButtons extends Migration
{
    public function up(): void
    {
        Db::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS kode_menu_buttons (
                id          BIGSERIAL PRIMARY KEY,
                menu_id     BIGINT       NOT NULL,
                code        VARCHAR(128) NOT NULL,
                title       VARCHAR(64)  NOT NULL,
                sort        INTEGER      NOT NULL DEFAULT 0,
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT kode_menu_buttons_code_uniq UNIQUE (code)
            )
            SQL);
        Db::statement('CREATE INDEX IF NOT EXISTS kode_menu_buttons_menu_idx ON kode_menu_buttons (menu_id)');
    }

    public function down(): void
    {
    }
}
