<?php

declare(strict_types=1);

namespace app\support;

use Hashids\Hashids;

/**
 * 对外 ID 混淆（淘宝式防猜）：内部自增 id 不变，对外只暴露 HashId。
 *
 *  - enc(1) → "dY3kK3MW"（不连续、不可枚举）；
 *  - dec("xxx") → 1，非法返回 null（控制器转 404）。
 * 盐放 HASHID_SALT（轮换后旧 eid 失效，按需保留多盐兼容）。
 */
final class HashId
{
    private static ?Hashids $codec = null;

    public static function codec(): Hashids
    {
        if (self::$codec === null) {
            $salt = (string) (env('HASHID_SALT', '') ?: env('JWT_SECRET', 'kode'));
            self::$codec = new Hashids($salt, 8);
        }

        return self::$codec;
    }

    public static function enc(int $id): string
    {
        return self::codec()->encode($id);
    }

    public static function dec(string $eid): ?int
    {
        $ids = self::codec()->decode($eid);

        return isset($ids[0]) ? (int) $ids[0] : null;
    }

    /** @param list<int> $ids */
    public static function encIds(array $ids): string
    {
        return self::codec()->encode(...$ids);
    }

    /** @return list<int>|null */
    public static function decIds(string $eid): ?array
    {
        $ids = self::codec()->decode($eid);

        return $ids === [] ? null : array_map('intval', $ids);
    }
}
