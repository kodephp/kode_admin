<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;

/**
 * 角色权限聚合：用户 roles（slug）→ perms 并集。多身份菜单过滤与 /api/auth/me 共用。
 *
 * 只认启用中的角色：kode_roles.status 是角色页的开关，若在读取侧无视它，
 * 「禁用角色」就只是个显示字段 —— 成员照样拿到 perms、照样出现在菜单过滤的身份列表里。
 * 指向已删除角色的授权记录一并落空（JOIN 不上即不授予）。
 */
final class RolePerms
{
    /** @return array{roles: list<string>, perms: list<string>, is_admin: bool} */
    public static function forUser(int $uid): array
    {
        $roles = [];
        if ($uid > 0) {
            foreach (Db::select(
                'SELECT ru.role_slug FROM kode_role_user ru
                 JOIN kode_roles r ON r.slug = ru.role_slug
                 WHERE ru.user_id = ? AND r.status = 1',
                [$uid]
            ) as $r) {
                $roles[] = (string) $r['role_slug'];
            }
        }
        $roles = array_values(array_unique($roles));
        $isAdmin = AccountScope::fromRoles($roles, $uid);

        $perms = [];
        if ($roles !== []) {
            $holders = implode(',', array_fill(0, count($roles), '?'));
            foreach (Db::select(
                "SELECT perms FROM kode_roles WHERE slug IN ({$holders})",
                $roles
            ) as $row) {
                foreach ((array) (json_decode((string) ($row['perms'] ?? '[]'), true) ?: []) as $code) {
                    $perms[] = (string) $code;
                }
            }
        }
        $perms = array_values(array_unique($perms));
        if ($isAdmin) {
            // 超管拥有全部菜单码（前端 v-access / authCode 比对用，后端菜单另有旁路）。
            foreach (Db::select("SELECT DISTINCT auth_code FROM kode_menus WHERE auth_code <> ''") as $row) {
                $perms[] = (string) $row['auth_code'];
            }
            $perms = array_values(array_unique($perms));
        }

        return ['roles' => $roles, 'perms' => $perms, 'is_admin' => $isAdmin];
    }
}
