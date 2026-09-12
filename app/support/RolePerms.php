<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;

/**
 * 角色权限聚合：用户 roles（slug）→ perms 并集。多身份菜单过滤与 /api/auth/me 共用。
 */
final class RolePerms
{
    /** @return array{roles: list<string>, perms: list<string>, is_admin: bool} */
    public static function forUser(int $uid): array
    {
        $roles = [];
        if ($uid > 0) {
            foreach (Db::select('SELECT role_slug FROM kode_role_user WHERE user_id = ?', [$uid]) as $r) {
                $roles[] = (string) $r['role_slug'];
            }
        }
        $roles = array_values(array_unique($roles));
        $isAdmin = in_array('admin', $roles, true) || $uid === 1;

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
