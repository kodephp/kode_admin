<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 下线「配置中心」菜单：与系统设置重复，统一收口到 /settings（API 保留供排查）。
 */
final class DropConfigsMenu extends Migration
{
    public function up(): void
    {
        Db::delete('DELETE FROM kode_menus WHERE path = ?', ['/configs']);
        \app\support\HotCache::forget('menus:tree');
    }

    public function down(): void
    {
    }
}
