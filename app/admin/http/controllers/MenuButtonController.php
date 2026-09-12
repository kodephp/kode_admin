<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;
use app\http\concerns\ApiResponse;

/**
 * 菜单按钮管理（按钮级权限码，全局唯一；角色按码授权，目录见 PermsController）。
 */
final class MenuButtonController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $menuId = $this->menuId();
        if ($menuId === null) {
            return $this->fail('菜单不存在', 404);
        }
        $rows = Db::select(
            'SELECT id, code, title, sort FROM kode_menu_buttons WHERE menu_id = ? ORDER BY sort, id',
            [$menuId]
        );
        foreach ($rows as &$row) {
            $row['id'] = \app\support\HashId::enc((int) $row['id']);
        }
        unset($row);

        return $this->ok($rows);
    }

    public function store()
    {
        $menuId = $this->menuId();
        if ($menuId === null) {
            return $this->fail('菜单不存在', 404);
        }
        $data = $this->validate(Request::all(), [
            'code' => 'required|min:2|max:128',
            'title' => 'required|min:1|max:64',
            'sort' => 'nullable|integer',
        ]);
        $code = $this->normCode($data['code']);
        if ($code === '') {
            return $this->fail('权限码非法', 400);
        }
        $exists = Db::select('SELECT id FROM kode_menu_buttons WHERE code = ? LIMIT 1', [$code]);
        if ($exists !== []) {
            return $this->fail('权限码已存在', 409);
        }
        $id = Db::insert(
            'INSERT INTO kode_menu_buttons (menu_id, code, title, sort, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$menuId, $code, $data['title'], (int) ($data['sort'] ?? 0)]
        );

        return $this->ok(['id' => \app\support\HashId::enc((int) $id)], 201);
    }

    public function update()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $data = $this->validate(Request::all(), [
            'title' => 'required|min:1|max:64',
            'sort' => 'nullable|integer',
        ]);
        $exists = Db::select('SELECT id FROM kode_menu_buttons WHERE id = ? LIMIT 1', [$id]);
        if ($exists === []) {
            return $this->fail('不存在', 404);
        }
        Db::update(
            'UPDATE kode_menu_buttons SET title = ?, sort = ?, updated_at = NOW() WHERE id = ?',
            [$data['title'], (int) ($data['sort'] ?? 0), $id]
        );

        return $this->ok(['id' => \app\support\HashId::enc($id)]);
    }

    public function destroy()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        Db::delete('DELETE FROM kode_menu_buttons WHERE id = ?', [$id]);

        return $this->ok(['deleted' => \app\support\HashId::enc($id)]);
    }

    /** 路由菜单 eid → 内部 id（插件菜单无内部 id，不可挂按钮）。 */
    private function menuId(): ?int
    {
        $id = $this->eid();
        if ($id === null) {
            return null;
        }
        $exists = Db::select('SELECT id FROM kode_menus WHERE id = ? LIMIT 1', [$id]);

        return $exists === [] ? null : $id;
    }

    private function normCode(mixed $value): string
    {
        $code = strtolower(trim((string) $value));

        return preg_match('/^[a-z0-9:._-]{2,128}$/', $code) === 1 ? $code : '';
    }
}
