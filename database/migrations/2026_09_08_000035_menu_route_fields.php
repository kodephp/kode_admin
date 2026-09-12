<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 菜单对齐官方模型：name（路由名）/ component（视图路径）/ meta（JSON：徽标/显隐/缓存等）。
 */
final class MenuRouteFields extends Migration
{
    public function up(): void
    {
        Db::statement("ALTER TABLE kode_menus ADD COLUMN IF NOT EXISTS name VARCHAR(64) NOT NULL DEFAULT ''");
        Db::statement("ALTER TABLE kode_menus ADD COLUMN IF NOT EXISTS component VARCHAR(255) NOT NULL DEFAULT ''");
        Db::statement("ALTER TABLE kode_menus ADD COLUMN IF NOT EXISTS meta TEXT NOT NULL DEFAULT '{}'");
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
