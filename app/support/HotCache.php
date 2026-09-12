<?php

declare(strict_types=1);

namespace app\support;

/**
 * 热数据缓存（redis 直连，pconnect 常驻复用，跨 worker 共享）。
 *
 * 不走 kode/cache（其 redis 在当前依赖约束下不可用，见框架 CacheServiceProvider 注释）；
 * redis 不可用时静默穿透回源，业务无感知。
 *
 * 约定：key 前缀按域划分（menu: / user:），写操作必须同步失效对应 key。
 */
final class HotCache
{
    private static ?\Redis $redis = null;

    private static function conn(): ?\Redis
    {
        if (self::$redis !== null) {
            return self::$redis;
        }
        if (!extension_loaded('redis')) {
            return null;
        }
        try {
            $r = new \Redis();
            $ok = @$r->pconnect(
                (string) env('REDIS_HOST', '127.0.0.1'),
                (int) env('REDIS_PORT', 6379),
                1.0
            );
            if (!$ok) {
                return null;
            }
            $password = (string) env('REDIS_PASSWORD', '');
            if ($password !== '' && !$r->auth($password)) {
                return null;
            }
            $db = (int) env('REDIS_DB', 0);
            if ($db !== 0) {
                $r->select($db);
            }

            return self::$redis = $r;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function get(string $key): mixed
    {
        try {
            $conn = self::conn();
            if ($conn === null) {
                return null;
            }
            $raw = $conn->get('kode:hot:' . $key);

            return $raw === false ? null : unserialize((string) $raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 读缓存，命中直接返回；未命中执行 $fallback 回源并回填。
     *
     * @template T
     * @param callable(): T $fallback
     * @return T
     */
    public static function remember(string $key, int $ttl, callable $fallback): mixed
    {
        $hit = self::get($key);
        if ($hit !== null) {
            return $hit;
        }
        $value = $fallback();
        try {
            self::conn()?->setex('kode:hot:' . $key, $ttl, serialize($value));
        } catch (\Throwable) {
        }

        return $value;
    }

    public static function forget(string $key): void
    {
        try {
            self::conn()?->del('kode:hot:' . $key);
        } catch (\Throwable) {
        }
    }

    public static function set(string $key, mixed $value, int $ttl = 300): void
    {
        try {
            self::conn()?->setex('kode:hot:' . $key, $ttl, serialize($value));
        } catch (\Throwable) {
        }
    }

    /** 按前缀批量失效（SCAN 增量删，不用 FLUSHDB；返回删除数）。 */
    public static function clear(string $prefix = ''): int
    {
        try {
            $conn = self::conn();
            if ($conn === null) {
                return 0;
            }
            $pattern = 'kode:hot:' . $prefix . '*';
            $deleted = 0;
            $cursor = null;
            do {
                $keys = $conn->scan($cursor, $pattern, 200);
                if ($keys === false) {
                    break;
                }
                foreach ($keys as $key) {
                    $deleted += (int) $conn->del($key);
                }
            } while ($cursor > 0);

            return $deleted;
        } catch (\Throwable) {
            return 0;
        }
    }
}
