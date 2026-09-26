<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\concerns\ApiResponse;
use app\concerns\PartialUpdate;
use app\support\TreeParent;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 部门管理：树形列表 / 新增 / 改名 / 删除（含校验：有子部门或有人时不可删）。
 *
 * PUT 是局部更新：四个可改列一律「缺键保留原值」（判据见 `app\concerns\PartialUpdate`）。
 * 管理端那个编辑框只发 `{name, status, remark}` —— `sort` 根本不在表单里，而列表正是
 * 按 `ORDER BY sort, id` 排的，于是无条件覆盖等于「每改一次部门名，全站部门排序归零一次」，
 * 且页面上没有任何地方显示过 sort，没人知道自己抹掉了什么。
 */
final class DeptController extends Controller
{
    use ApiResponse;
    use PartialUpdate;

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
            $parent = (int) $row['parent_id'];
            $parentOf[$id] = $parent;
            $row['id'] = \app\support\HashId::enc($id);
            $row['member_count'] = $members[$id] ?? 0;
            if (($row['created_at'] ?? null) instanceof \DateTimeInterface) {
                $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
            }
            // 上级必须与 id 同形状对外：编辑框的那个 TreeSelect 拿 valueField='id' 回填，
            // 留裸整数（或干脆 unset 掉，这是原先的行为）就永远匹配不上 —— 于是「上级部门」
            // 在编辑时恒空，用户不动它、提交，看起来像「这个部门没有上级」。
            $row['parent_id'] = $parent > 0 ? \app\support\HashId::enc($parent) : '';
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
            'name' => 'required|min:1|length:64',
            'parent_id' => 'nullable',
            'sort' => 'nullable|integer',
            'status' => 'nullable|integer',
            'remark' => 'nullable|length:255',
        ]);

        // parent_id 只声明 nullable：它的形状是「HashId 串或 0」两种，规则里写 string 会拒掉
        // 表单清空选择框时发来的 0，写 integer 会拒掉正常 eid —— 真正的判定在 TreeParent 里。
        $resolved = TreeParent::resolve('kode_depts', $data['parent_id'] ?? null);
        $failure = TreeParent::failureFor($resolved, '部门');
        if ($failure !== null) {
            return $this->fail($failure['msg'], $failure['status']);
        }

        $id = Db::insert(
            'INSERT INTO kode_depts (name, parent_id, sort, status, remark, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (string) $data['name'], $resolved['parent_id'],
                (int) ($data['sort'] ?? 0),
                self::supplied($data, 'status') ? ((int) $data['status'] !== 0 ? 1 : 0) : 1,
                (string) ($data['remark'] ?? ''),
            ]
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
            'name' => 'required|min:1|length:64',
            'parent_id' => 'nullable',
            'sort' => 'nullable|integer',
            'status' => 'nullable|integer',
            'remark' => 'nullable|length:255',
        ]);
        $rows = Db::select('SELECT parent_id, sort, status, remark FROM kode_depts WHERE id = ? LIMIT 1', [$id]);
        if ($rows === []) {
            return $this->fail('不存在', 404);
        }
        $cur = $rows[0];

        // 上级：没带这个键就是「没改」。此前 update() 的 SQL 里根本没有 parent_id 这一列，
        // 而前端 api.ts 写着 `void pid`（把值丢掉以免白传）—— 那个选择框两侧都没人接，
        // 改了不落地还回 200。
        $parentId = (int) ($cur['parent_id'] ?? 0);
        if (self::supplied($data, 'parent_id')) {
            $resolved = TreeParent::resolve('kode_depts', $data['parent_id'], $id);
            $failure = TreeParent::failureFor($resolved, '部门');
            if ($failure !== null) {
                return $this->fail($failure['msg'], $failure['status']);
            }
            $parentId = $resolved['parent_id'];
        }

        Db::update(
            'UPDATE kode_depts SET name = ?, parent_id = ?, sort = ?, status = ?, remark = ?, updated_at = NOW() WHERE id = ?',
            [
                (string) $data['name'], $parentId,
                self::pick($data, 'sort', (int) ($cur['sort'] ?? 0), static fn(mixed $v): int => (int) $v),
                self::pick($data, 'status', (int) ($cur['status'] ?? 1), static fn(mixed $v): int => (int) $v !== 0 ? 1 : 0),
                self::pick($data, 'remark', (string) ($cur['remark'] ?? ''), static fn(mixed $v): string => (string) $v),
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
