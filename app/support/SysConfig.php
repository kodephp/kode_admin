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

    /**
     * 数值型配置读取：库里存什么就用什么，但夹在声明的合法区间内。
     *
     * 四个参数都是必填：区间是调用方对该键的约束声明，缺一个就没有约束，
     * 所以宁可让调用方写全，也不要给默认值（少传参数在 strict_types 下是
     * ArgumentCountError，不是「恰好能用」——本轮就是 `getInt($key, 1)` 读开关，
     * 结果每次上传都在文件已 moveTo 之后炸掉）。开关请用 getBool()。
     */
    public static function getInt(string $key, int $default, int $min, int $max): int
    {
        return min($max, max($min, (int) self::get($key, (string) $default)));
    }

    /**
     * 布尔开关读取（库里存 '1'/'0'，也认 'true'/'false'/'on'/'off'/'yes'/'no'）。
     *
     * 单列一个方法的原因见 getInt() 注释：开关没有区间概念，硬套 getInt 要么少传参数
     * 直接炸，要么把 min/max 写成无意义的 0/1 让下一位读者猜它想约束什么。
     * 认不出的字面量按 $default 处理——库里手写的脏值不该把一个开关读成「开」。
     */
    public static function getBool(string $key, bool $default): bool
    {
        $raw = strtolower(trim(self::get($key, '')));
        if ($raw === '') {
            return $default;
        }
        if (in_array($raw, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($raw, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }

        return $default;
    }

    /**
     * 写入配置（创建或更新），并失效对应缓存键。
     */
    public static function set(string $key, string $value): void
    {
        $rows = Db::select('SELECT id FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);
        if ($rows === []) {
            Db::insert(
                'INSERT INTO kode_sys_configs (ckey, cvalue, ctype, ctitle, cgroup, sort, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$key, $value, 'json', $key, 'custom', 0]
            );
        } else {
            Db::update(
                'UPDATE kode_sys_configs SET cvalue = ?, updated_at = NOW() WHERE ckey = ?',
                [$value, $key]
            );
        }
        HotCache::forget("config:{$key}");
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
            ['upload.local_backup', '1', '推送远程后是否保留本地副本；当前仅回显不执行（删本地需先给驱动契约补下载/删除动词，否则 transfer 回本地盘无从反查）', 'boolean', '保留本地副本（暂未生效）', 'upload', 50],
            ['auth.password_min_length', '6', '密码最小长度（注册/改密/重置统一执行）', 'number', '密码最小长度', 'auth', 30],
            ['market.api_url', '', '官方插件市场 API（空=未对接，仅展示本地）', 'text', '市场 API', 'market', 10],
        ];
    }
}
