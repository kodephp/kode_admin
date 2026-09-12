<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 菜单加 status（1 启用 / 0 禁用；禁用菜单非超管不可见）。
 */
final class MenuStatus extends Migration
{
    public function up(): void
    {
        Db::statement('ALTER TABLE kode_menus ADD COLUMN IF NOT EXISTS status SMALLINT NOT NULL DEFAULT 1');
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
