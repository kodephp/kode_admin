<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;

/**
 * 系统配置读取（kode_sys_configs，读走 redis 热缓存；缺 key 落默认值）。
 * 写入口统一走 ConfigController::set（写必失效对应缓存键 `config:{key}`）。
 */
final class SysConfig
{
    public static function get(string $key, string $default = ''): string
    {
        try {
            $value = HotCache::remember("config:{$key}", 300, static function () use ($key): ?string {
                $rows = Db::select('SELECT cvalue FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);

                return $rows[0]['cvalue'] ?? null;
            });
        } catch (\Throwable) {
            return $default;
        }

        return $value ?? $default;
    }

    public static function getInt(string $key, int $default, int $min, int $max): int
    {
        return min($max, max($min, (int) self::get($key, (string) $default)));
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int}> [key, value, remark, type, title, group, sort] */
    public static function defaults(): array
    {
        return [
            ['site.title', 'Kode Admin', '站点标题', 'text', '站点标题', 'site', 10],
            ['site.logo', '', '站点 Logo 地址（空=默认）', 'image', 'Logo', 'site', 20],
            ['site.icp', '', '站点备案号', 'text', '备案号', 'site', 30],
            ['site.description', '', '站点描述', 'textarea', '站点描述', 'site', 40],
            ['auth.login_max_attempts', '10', '登录防爆破：阈值（次）', 'number', '防爆破阈值', 'auth', 10],
            ['auth.login_decay_seconds', '600', '登录防爆破：窗口（秒）', 'number', '防爆破窗口', 'auth', 20],
            ['audit.retention_days', '90', '审计保留天数（清理默认值）', 'number', '审计保留天数', 'audit', 10],
            ['upload.max_mb', '10', '上传大小上限（MB）', 'number', '上传上限', 'upload', 10],
            ['upload.allow_exts', '', '允许扩展名（逗号分隔，空=内置白名单）', 'text', '允许扩展名', 'upload', 20],
            ['upload.check_mime', '1', '上传 MIME 实测（1=开启）', 'boolean', 'MIME 实测', 'upload', 30],
            ['upload.naming', 'date_random', '命名规则：date_random=日期+随机 / origin=原文件名 / uuid', 'text', '命名规则', 'upload', 40],
            ['auth.password_min_length', '6', '密码最小长度（注册/改密/重置统一执行）', 'number', '密码最小长度', 'auth', 30],
            ['market.api_url', '', '官方插件市场 API（空=未对接，仅展示本地）', 'text', '市场 API', 'market', 10],
        ];
    }
}
