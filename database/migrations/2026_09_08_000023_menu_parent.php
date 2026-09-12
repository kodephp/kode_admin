<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 菜单加 parent_id（无限树，0=顶级；删父菜单时子菜单上移顶级，按钮联带删除）。
 */
final class MenuParentId extends Migration
{
    public function up(): void
    {
        Db::statement('ALTER TABLE kode_menus ADD COLUMN IF NOT EXISTS parent_id BIGINT NOT NULL DEFAULT 0');
        Db::statement('CREATE INDEX IF NOT EXISTS kode_menus_parent_idx ON kode_menus (parent_id)');
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
