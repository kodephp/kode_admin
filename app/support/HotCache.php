<?php

declare(strict_types=1);

namespace app\support;

/**
 * 热数据缓存（redis 直连，pconnect 常驻复用，跨 worker 共享）。
 *
 * 不走 kode/cache（其 redis 在当前依赖约束下不可用，见框架 CacheServiceProvider 注释）；
 * redis 不可用时静默穿透回源，业务无感知。
 *
 * 约定：key 前缀按域划分，写操作必须同步失效对应 key。
 * 物理键一律是 `kode:hot:{逻辑键}`。
 */
final class HotCache
{
    /**
     * 全部命名空间（「清理热缓存」与健康探针的 repair 只读这一份）。
     *
     * 新增一个 `remember/forget` 前缀必须同时登记在这里：清理口此前只认 `menus:`/`config:`，
     * 而插件侧写的是 `plugin:config:*` 与 `plugin:status:*`，于是超管点了「清理缓存」
     * 之后插件仍按 5 分钟 TTL 读到旧值 —— 「写成功了但行为没变」最难查的那种。
     *
     * @var list<string>
     */
    public const NAMESPACES = ['menus:', 'config:', 'plugin:'];

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

    /**
     * 健康检查用的连接出口：连接参数只有本类一份，
     * 探测方自己拼 host/port 的话，探活对象和真实使用的对象可能不是同一个。
     */
    public static function connection(): ?\Redis
    {
        return self::conn();
    }

    public static function get(string $key): mixed
    {
        try {
            $conn = self::conn();
            if ($conn === null) {
                return null;
            }
            $raw = $conn->get('kode:hot:' . $key);
            if ($raw === false) {
                return null;
            }

            // allowed_classes=false：缓存被别的进程/租户写过（或被投毒）时，
            // 反序列化只能还原标量与数组，不会再凭一条缓存串实例化任意类。
            return unserialize((string) $raw, ['allowed_classes' => false]);
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

    /**
     * 按前缀批量失效（SCAN 增量删，不用 FLUSHDB）。
     *
     * 返回值分得开两种「没删到东西」：`0` = 这一轮真跑完了、没有匹配键；
     * `null` = 一个键都没清（连不上，或连接已死）。
     * 常驻 worker 握的是 pconnect 长连接，redis 重启之后连接对象还在、`scan()` 只会回
     * false（实测：未连接的 \Redis 客户端调 scan() 返回 false，不抛）—— 把它算成 0
     * 等于对着失败的清理回「已清理（共 0 个键）」，两种相反的状态共用一个数字。
     */
    public static function clear(string $prefix = ''): ?int
    {
        try {
            $conn = self::conn();
            if ($conn === null) {
                return null;
            }
            $pattern = 'kode:hot:' . $prefix . '*';
            $deleted = 0;
            $cursor = null;
            do {
                $keys = $conn->scan($cursor, $pattern, 200);
                if ($keys === false) {
                    return null;
                }
                foreach ($keys as $key) {
                    $deleted += (int) $conn->del($key);
                }
            } while ($cursor > 0);

            return $deleted;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 清理全部命名空间，返回 `{前缀: 删除数|null}`（null 的含义见 {@see clear()}）。
     *
     * 派发口只有一个：调用方各自列前缀 = 早晚会漏掉新前缀（见 NAMESPACES 注释）。
     *
     * @return array<string, int|null>
     */
    public static function clearAll(): array
    {
        $out = [];
        foreach (self::NAMESPACES as $ns) {
            $out[$ns] = self::clear($ns);
        }

        return $out;
    }

    /**
     * 一份 clearAll() 结果里「没清成」的命名空间。
     *
     * 判定只写这一份：清理口与「一键修复」两处各自 filter 一遍，迟早只改对一个，
     * 于是页面上一个说成功、另一个说失败。
     *
     * @param array<string, int|null> $cleared
     * @return list<string>
     */
    public static function incomplete(array $cleared): array
    {
        return array_keys(array_filter($cleared, static fn ($n): bool => $n === null));
    }
}
