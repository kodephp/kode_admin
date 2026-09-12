<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use app\http\concerns\ApiResponse;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 部门管理：树形列表 / 新增 / 改名 / 删除（含校验：有子部门或有人时不可删）。
 */
final class DeptController extends Controller
{
    use ApiResponse;

    public function tree()
    {
        $rows = Db::select('SELECT id, name, parent_id, sort, status, remark, created_at FROM kode_depts ORDER BY sort, id');
        $members = [];
        foreach (Db::select('SELECT dept_id, COUNT(*) AS c FROM kode_users WHERE dept_id > 0 GROUP BY dept_id') as $r) {
            $members[(int) $r['dept_id']] = (int) $r['c'];
        }
        $parentOf = [];
        $byEid = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $parentOf[$id] = (int) $row['parent_id'];
            $row['id'] = \app\support\HashId::enc($id);
            $row['member_count'] = $members[$id] ?? 0;
            if (($row['created_at'] ?? null) instanceof \DateTimeInterface) {
                $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
            }
            unset($row['parent_id']);
            $byEid[$row['id']] = $row;
        }

        return $this->ok($this->buildTree($parentOf, $byEid));
    }

    /** @param array<int,int> $parentOf @param array<string,array> $byEid */
    private function buildTree(array $parentOf, array $byEid): array
    {
        $eidOf = [];
        foreach ($parentOf as $id => $pid) {
            $eidOf[$id] = \app\support\HashId::enc($id);
        }
        $grouped = [];
        foreach ($parentOf as $id => $pid) {
            $grouped[$pid][] = $id;
        }
        $walk = static function (int $pid) use (&$walk, $grouped, $byEid, $eidOf): array {
            $out = [];
            foreach ($grouped[$pid] ?? [] as $id) {
                $node = $byEid[$eidOf[$id]] ?? null;
                if ($node === null) {
                    continue;
                }
                $node['children'] = $walk($id);
                $out[] = $node;
            }

            return $out;
        };

        return $walk(0);
    }

    public function store()
    {
        $data = $this->validate(Request::all(), [
            'name' => 'required|min:1|max:64',
            'parent_id' => 'nullable|string',
            'sort' => 'nullable|integer',
            'status' => 'nullable|integer',
            'remark' => 'nullable|max:255',
        ]);

        $parentId = 0;
        if (!empty($data['parent_id'])) {
            $parentId = \app\support\HashId::dec((string) $data['parent_id']) ?? -1;
            if ($parentId < 0) {
                return $this->fail('上级部门不存在', 404);
            }
            $exists = Db::select('SELECT id FROM kode_depts WHERE id = ? LIMIT 1', [$parentId]);
            if ($exists === []) {
                return $this->fail('上级部门不存在', 404);
            }
        }

        $id = Db::insert(
            'INSERT INTO kode_depts (name, parent_id, sort, status, remark, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$data['name'], $parentId, (int) ($data['sort'] ?? 0), array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : 1, substr((string) ($data['remark'] ?? ''), 0, 255)]
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
            'name' => 'required|min:1|max:64',
            'sort' => 'nullable|integer',
            'status' => 'nullable|integer',
            'remark' => 'nullable|max:255',
        ]);
        $row = Db::select('SELECT status, remark FROM kode_depts WHERE id = ? LIMIT 1', [$id]);
        if ($row === []) {
            return $this->fail('不存在', 404);
        }

        Db::update(
            'UPDATE kode_depts SET name = ?, sort = ?, status = ?, remark = ?, updated_at = NOW() WHERE id = ?',
            [
                $data['name'], (int) ($data['sort'] ?? 0),
                array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : (int) ($row[0]['status'] ?? 1),
                array_key_exists('remark', $data) ? substr((string) $data['remark'], 0, 255) : (string) ($row[0]['remark'] ?? ''),
                $id,
            ]
        );

        return $this->ok(['id' => \app\support\HashId::enc($id)]);
    }

    public function destroy()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $kids = Db::select('SELECT id FROM kode_depts WHERE parent_id = ? LIMIT 1', [$id]);
        if ($kids !== []) {
            return $this->fail('存在子部门，不可删除', 409);
        }
        $members = Db::select('SELECT id FROM kode_users WHERE dept_id = ? LIMIT 1', [$id]);
        if ($members !== []) {
            return $this->fail('部门下仍有用户，不可删除', 409);
        }
        Db::delete('DELETE FROM kode_depts WHERE id = ?', [$id]);

        return $this->ok(['deleted' => \app\support\HashId::enc($id)]);
    }
}
