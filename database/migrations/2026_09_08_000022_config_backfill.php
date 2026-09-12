<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 存量配置回填类型/标题/分组/排序（与 SysConfig::defaults 对齐）。
 */
final class ConfigMetaBackfill extends Migration
{
    public function up(): void
    {
        foreach (\app\support\SysConfig::defaults() as [$key, , , $type, $title, $group, $sort]) {
            Db::update(
                'UPDATE kode_sys_configs SET ctype = ?, ctitle = ?, cgroup = ?, sort = ? WHERE ckey = ?',
                [$type, $title, $group, $sort, $key]
            );
            \app\support\HotCache::forget("config:{$key}");
        }
    }

    public function down(): void
    {
    }
}
