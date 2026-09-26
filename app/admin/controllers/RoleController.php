<?php

declare(strict_types=1);

namespace app\admin\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use app\concerns\ApiResponse;
use app\concerns\PartialUpdate;
use app\services\UserService;
use app\support\Clip;
use Kode\Http\Request;

/**
 * 角色管理：列表 / 新增 / 改名改权限 / 删除（slug 唯一）。
 *
 * PUT 是局部更新：四个可改字段一律「缺键保留原值」，列表页的状态开关只发 {status}。
 * 禁用或删除角色会撤销其成员的在发令牌（slug 铸在 JWT 载荷里，不撤销就还是生效身份）。
 */
final class RoleController extends Controller
{
    use ApiResponse;
    use PartialUpdate;
    public function index()
    {
        $search = Clip::chars(trim((string) $this->query('search', '')), 64);
        if ($search === '') {
            $rows = Db::select('SELECT id, name, slug, perms, status, remark, created_at FROM kode_roles ORDER BY id');
        } else {
            $rows = Db::select(
                'SELECT id, name, slug, perms, status, remark, created_at FROM kode_roles WHERE name LIKE ? OR slug LIKE ? ORDER BY id',
                ['%' . $search . '%', '%' . $search . '%']
            );
        }
        foreach ($rows as &$row) {
            $row['id'] = \app\support\HashId::enc((int) $row['id']);
            $row['perms'] = json_decode((string) ($row['perms'] ?? '[]'), true) ?: [];
            if (($row['created_at'] ?? null) instanceof \DateTimeInterface) {
                $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
            }
        }
        unset($row);
        $counts = [];
        foreach (Db::select('SELECT role_slug, COUNT(*) AS c FROM kode_role_user GROUP BY role_slug') as $r) {
            $counts[(string) $r['role_slug']] = (int) $r['c'];
        }
        foreach ($rows as &$row) {
            $row['member_count'] = $counts[(string) ($row['slug'] ?? '')] ?? 0;
        }
        unset($row);

        return $this->ok($rows);
    }

    public function store()
    {
        $data = $this->validate(Request::all(), [
            'name' => 'required|min:2|length:64',
            'slug' => 'required|min:2|length:64',
            'perms' => 'nullable|array',
            'remark' => 'nullable|length:255',
        ]);

        $exists = Db::select('SELECT id FROM kode_roles WHERE slug = ? LIMIT 1', [$data['slug']]);
        if ($exists !== []) {
            return $this->fail('标识已存在', 409);
        }

        $id = Db::insert(
            'INSERT INTO kode_roles (name, slug, perms, remark, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$data['name'], $data['slug'], json_encode(array_values((array) ($data['perms'] ?? []))), (string) ($data['remark'] ?? '')]
        );

        return $this->ok(['id' => \app\support\HashId::enc((int) $id)], 201);
    }

    public function update()
    {
        $slug = (string) $this->param('slug');
        if ($slug === 'admin') {
            return $this->fail('超管角色不可修改', 403);
        }
        $data = $this->validate(Request::all(), [
            'name' => 'nullable|min:2|length:64',
            'perms' => 'nullable|array',
            'status' => 'nullable|integer',
            'remark' => 'nullable|length:255',
        ]);

        $exists = Db::select('SELECT id, name, perms, status, remark FROM kode_roles WHERE slug = ? LIMIT 1', [$slug]);
        if ($exists === []) {
            return $this->fail('不存在', 404);
        }
        $row = $exists[0];

        // 四个字段一律「缺键保留原值」。早先 name 是 required，列表页那个只发 {status} 的开关直接 422；
        // perms 又无条件覆盖，「只改名字」的 PUT 会把权限点清成 []，整批成员当场掉光权限。
        $name = self::pick($data, 'name', (string) ($row['name'] ?? ''), static fn(mixed $v): string => trim((string) $v));
        $perms = self::pick($data, 'perms', (string) ($row['perms'] ?? '[]'), static fn(mixed $v): string => (string) json_encode(array_values((array) $v)));
        $newStatus = self::pick($data, 'status', (int) ($row['status'] ?? 1), static fn(mixed $v): int => (int) $v !== 0 ? 1 : 0);
        $remark = self::pick($data, 'remark', (string) ($row['remark'] ?? ''), static fn(mixed $v): string => (string) $v);

        // 禁用前先撤销成员会话：slug 铸在 JWT 载荷里，不撤销的话 RequireRole 照放行到令牌过期
        $revoked = (int) ($row['status'] ?? 1) === 1 && $newStatus === 0
            ? resolve(UserService::class)->invalidateSessionsByRole($slug)
            : 0;

        Db::update(
            'UPDATE kode_roles SET name = ?, perms = ?, status = ?, remark = ?, updated_at = NOW() WHERE slug = ?',
            [$name, $perms, $newStatus, $remark, $slug]
        );

        return $this->ok(['slug' => $slug, 'revoked_sessions' => $revoked]);
    }

    public function destroy()
    {
        $slug = (string) $this->param('slug');
        if ($slug === 'admin') {
            return $this->fail('超管角色不可删除', 403);
        }

        $revoked = resolve(UserService::class)->invalidateSessionsByRole($slug);
        Db::delete('DELETE FROM kode_role_user WHERE role_slug = ?', [$slug]);
        Db::delete('DELETE FROM kode_roles WHERE slug = ?', [$slug]);

        return $this->ok(['deleted' => $slug, 'revoked_sessions' => $revoked]);
    }
}
