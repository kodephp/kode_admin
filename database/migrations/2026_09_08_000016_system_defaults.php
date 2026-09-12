<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 默认系统配置 + 默认部门（幂等，已存在则跳过）。
 * 配置键说明见系统设置页分组：site（站点）/ auth（登录安全）/ audit（审计保留）/
 * upload（上传）/ market（插件市场）。
 */
final class SeedSystemDefaults extends Migration
{
    public function up(): void
    {
        $configs = [
            ['site.title', 'Kode Admin', '站点标题'],
            ['site.logo', '', '站点 Logo 地址（空=默认）'],
            ['auth.login_max_attempts', '10', '登录防爆破：阈值（次）'],
            ['auth.login_decay_seconds', '600', '登录防爆破：窗口（秒）'],
            ['audit.retention_days', '90', '审计保留天数（清理默认值）'],
            ['upload.max_mb', '10', '上传大小上限（MB）'],
            ['market.api_url', '', '官方插件市场 API（空=未对接，仅展示本地）'],
        ];
        foreach ($configs as [$key, $value, $remark]) {
            $exists = Db::select('SELECT id FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);
            if ($exists !== []) {
                continue;
            }
            Db::insert(
                'INSERT INTO kode_sys_configs (ckey, cvalue, remark, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
                [$key, $value, $remark]
            );
            \app\support\HotCache::forget("config:{$key}");
        }

        $depts = [
            ['总经办', 0, 10],
            ['技术部', 0, 20],
            ['运营部', 0, 30],
        ];
        $techId = 0;
        foreach ($depts as [$name, $parent, $sort]) {
            $exists = Db::select('SELECT id FROM kode_depts WHERE name = ? AND parent_id = ? LIMIT 1', [$name, $parent]);
            if ($exists !== []) {
                $techId = $name === '技术部' ? (int) $exists[0]['id'] : $techId;
                continue;
            }
            $id = Db::insert(
                'INSERT INTO kode_depts (name, parent_id, sort, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
                [$name, $parent, $sort]
            );
            if ($name === '技术部') {
                $techId = (int) $id;
            }
        }
        if ($techId > 0) {
            foreach ([['前端组', 10], ['后端组', 20]] as [$name, $sort]) {
                $exists = Db::select(
                    'SELECT id FROM kode_depts WHERE name = ? AND parent_id = ? LIMIT 1',
                    [$name, $techId]
                );
                if ($exists !== []) {
                    continue;
                }
                Db::insert(
                    'INSERT INTO kode_depts (name, parent_id, sort, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
                    [$name, $techId, $sort]
                );
            }
        }
    }

    public function down(): void
    {
    }
}
