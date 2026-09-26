<?php

declare(strict_types=1);

namespace app\support;

use Kode\Http\Request;

/**
 * 「当前调用者是谁 / 是不是超管」的唯一判据。
 *
 * 为什么单独成一份：同一条规则（admin 角色或 uid=1）此前散在 `RequirePerm` 中间件和
 * `RolePerms` 里各写一遍。门禁的口径一旦和权限聚合的口径漂移，就会出现
 * 「菜单看得见、接口 403」或反过来「中间件放行而权限集按另一套算」——两处都自洽，
 * 合起来才矛盾。判定收在这里，两边各自喂自己该信的数据源：
 *  - 中间件/控制器：信登录时铸好的 token 载荷（`isAdmin`，热路径不查库）；
 *  - 权限聚合：信库里的实时角色（`fromRoles`，禁用角色要立刻不掉权限）。
 *
 * 见 AGENTS.md §4：处置即时生效靠的是写侧撤销令牌，不是读侧每次回查数据库。
 */
final class AccountScope
{
    /** 超管角色 slug（与 `kode_roles.slug`、初始种子一致）。 */
    public const ADMIN_ROLE = 'admin';

    /** 内置超管账号 id：不可禁用/删除，恒视为超管。 */
    public const ROOT_UID = 1;

    /**
     * 当前请求的 auth 载荷（未认证为 null）。
     *
     * @return object|array<string, mixed>|null
     */
    public static function payload(): mixed
    {
        return Request::attr('auth');
    }

    /**
     * @param object|array<string, mixed>|null $payload
     */
    public static function isAdmin(mixed $payload): bool
    {
        return self::fromRoles(self::rolesOf($payload), self::uidOf($payload));
    }

    /**
     * @param object|array<string, mixed>|null $payload
     */
    public static function uidOf(mixed $payload): int
    {
        return (int) self::field($payload, 'uid');
    }

    /**
     * @param object|array<string, mixed>|null $payload
     */
    public static function usernameOf(mixed $payload): string
    {
        return trim((string) self::field($payload, 'username'));
    }

    /**
     * @param object|array<string, mixed>|null $payload
     * @return list<string>
     */
    public static function rolesOf(mixed $payload): array
    {
        $roles = (array) self::field($payload, 'roles');

        return array_values(array_map(
            static fn(mixed $r): string => trim((string) $r),
            $roles
        ));
    }

    /**
     * 规则本体：admin 角色或内置超管账号。
     *
     * @param list<string> $roles
     */
    public static function fromRoles(array $roles, int $uid): bool
    {
        return in_array(self::ADMIN_ROLE, $roles, true) || $uid === self::ROOT_UID;
    }

    /**
     * @param object|array<string, mixed>|null $payload
     */
    private static function field(mixed $payload, string $key): mixed
    {
        if (is_array($payload)) {
            return $payload[$key] ?? null;
        }
        if (is_object($payload)) {
            return $payload->{$key} ?? null;
        }

        return null;
    }
}
