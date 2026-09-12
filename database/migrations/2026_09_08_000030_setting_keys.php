<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 补系统设置新键（幂等）：上传命名规则 + 密码最小长度。
 */
final class SeedSettingKeys extends Migration
{
    public function up(): void
    {
        foreach (\app\support\SysConfig::defaults() as [$key, $value, $remark, $type, $title, $group, $sort]) {
            $exists = Db::select('SELECT id FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);
            if ($exists !== []) {
                continue;
            }
            Db::insert(
                'INSERT INTO kode_sys_configs (ckey, cvalue, remark, ctype, ctitle, cgroup, sort, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$key, $value, $remark, $type, $title, $group, $sort]
            );
            \app\support\HotCache::forget("config:{$key}");
        }
    }

    public function down(): void
    {
    }
}
