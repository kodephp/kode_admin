<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use app\http\concerns\ApiResponse;
use Kode\Http\Request;

/**
 * 角色管理：列表 / 新增 / 删除（slug 唯一）。
 */
final class RoleController extends Controller
{
    use ApiResponse;
    public function index()
    {
        $search = substr(trim((string) $this->query('search', '')), 0, 64);
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
            'name' => 'required|min:2|max:64',
            'slug' => 'required|min:2|max:64',
            'perms' => 'nullable|array',
            'remark' => 'nullable|max:255',
        ]);

        $exists = Db::select('SELECT id FROM kode_roles WHERE slug = ? LIMIT 1', [$data['slug']]);
        if ($exists !== []) {
            return $this->fail('标识已存在', 409);
        }

        $id = Db::insert(
            'INSERT INTO kode_roles (name, slug, perms, remark, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$data['name'], $data['slug'], json_encode(array_values((array) ($data['perms'] ?? []))), substr((string) ($data['remark'] ?? ''), 0, 255)]
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
            'name' => 'required|min:2|max:64',
            'perms' => 'nullable|array',
            'status' => 'nullable|integer',
            'remark' => 'nullable|max:255',
        ]);

        $exists = Db::select('SELECT id, status, remark FROM kode_roles WHERE slug = ? LIMIT 1', [$slug]);
        if ($exists === []) {
            return $this->fail('不存在', 404);
        }
        Db::update(
            'UPDATE kode_roles SET name = ?, perms = ?, status = ?, remark = ?, updated_at = NOW() WHERE slug = ?',
            [
                $data['name'], json_encode(array_values((array) ($data['perms'] ?? []))),
                array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : (int) ($exists[0]['status'] ?? 1),
                array_key_exists('remark', $data) ? substr((string) $data['remark'], 0, 255) : (string) ($exists[0]['remark'] ?? ''),
                $slug,
            ]
        );

        return $this->ok(['slug' => $slug]);
    }

    public function destroy()
    {
        $slug = (string) $this->param('slug');
        if ($slug === 'admin') {
            return $this->fail('超管角色不可删除', 403);
        }

        Db::delete('DELETE FROM kode_role_user WHERE role_slug = ?', [$slug]);
        Db::delete('DELETE FROM kode_roles WHERE slug = ?', [$slug]);

        return $this->ok(['deleted' => $slug]);
    }
}
