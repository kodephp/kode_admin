<?php

declare(strict_types=1);

namespace app\admin\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;
use app\concerns\ApiResponse;
use app\concerns\PartialUpdate;
use app\support\Clip;

/**
 * 字典管理：类型 CRUD + 字典项 CRUD（code 全局唯一约束在类型上，项按 type_code 聚合）。
 *
 * PUT 是局部更新：可改列「缺键保留原值」（判据见 `app\concerns\PartialUpdate`）。
 */
final class DictController extends Controller
{
    use ApiResponse;
    use PartialUpdate;

    public function types()
    {
        $rows = Db::select('SELECT id, name, code, remark FROM kode_dict_types ORDER BY id');
        foreach ($rows as &$row) {
            $row['id'] = \app\support\HashId::enc((int) $row['id']);
        }
        unset($row);

        return $this->ok($rows);
    }

    public function storeType()
    {
        $data = $this->validate(Request::all(), [
            'name' => 'required|min:1|max:64',
            'code' => 'required|min:2|max:64',
            'remark' => 'nullable|max:255',
        ]);
        $code = strtolower(trim((string) $data['code']));
        if (preg_match('/^[a-z0-9_]{2,64}$/', $code) !== 1) {
            return $this->fail('编码非法', 400);
        }
        $exists = Db::select('SELECT id FROM kode_dict_types WHERE code = ? LIMIT 1', [$code]);
        if ($exists !== []) {
            return $this->fail('编码已存在', 409);
        }
        $id = Db::insert(
            'INSERT INTO kode_dict_types (name, code, remark, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
            [$data['name'], $code, (string) ($data['remark'] ?? '')]
        );

        return $this->ok(['id' => \app\support\HashId::enc((int) $id)], 201);
    }

    public function updateType()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $data = $this->validate(Request::all(), [
            'name' => 'required|min:1|max:64',
            'remark' => 'nullable|max:255',
        ]);
        $exists = Db::select('SELECT id, code, remark FROM kode_dict_types WHERE id = ? LIMIT 1', [$id]);
        if ($exists === []) {
            return $this->fail('不存在', 404);
        }
        Db::update(
            'UPDATE kode_dict_types SET name = ?, remark = ?, updated_at = NOW() WHERE id = ?',
            [
                (string) $data['name'],
                self::pick($data, 'remark', (string) ($exists[0]['remark'] ?? ''), static fn(mixed $v): string => (string) $v),
                $id,
            ]
        );

        return $this->ok(['id' => \app\support\HashId::enc($id)]);
    }

    public function destroyType()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $row = Db::select('SELECT code FROM kode_dict_types WHERE id = ? LIMIT 1', [$id]);
        if ($row === []) {
            return $this->fail('不存在', 404);
        }
        Db::delete('DELETE FROM kode_dict_data WHERE type_code = ?', [(string) $row[0]['code']]);
        Db::delete('DELETE FROM kode_dict_types WHERE id = ?', [$id]);

        return $this->ok(['deleted' => \app\support\HashId::enc($id)]);
    }

    public function items()
    {
        $code = Clip::chars((string) $this->query('type', ''), 64);
        if ($code === '') {
            return $this->fail('缺少 type 参数', 400);
        }
        $rows = Db::select(
            'SELECT id, label, value, sort, remark FROM kode_dict_data WHERE type_code = ? ORDER BY sort, id',
            [$code]
        );
        foreach ($rows as &$row) {
            $row['id'] = \app\support\HashId::enc((int) $row['id']);
        }
        unset($row);

        return $this->ok($rows);
    }

    public function storeItem()
    {
        $data = $this->validate(Request::all(), [
            'type' => 'required|min:2|max:64',
            'label' => 'required|min:1|max:64',
            'value' => 'required|min:1|max:128',
            'sort' => 'nullable|integer',
            'remark' => 'nullable|max:255',
        ]);
        $exists = Db::select('SELECT id FROM kode_dict_types WHERE code = ? LIMIT 1', [$data['type']]);
        if ($exists === []) {
            return $this->fail('字典类型不存在', 404);
        }
        $id = Db::insert(
            'INSERT INTO kode_dict_data (type_code, label, value, sort, remark, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$data['type'], $data['label'], $data['value'], (int) ($data['sort'] ?? 0), (string) ($data['remark'] ?? '')]
        );

        return $this->ok(['id' => \app\support\HashId::enc((int) $id)], 201);
    }

    public function updateItem()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $data = $this->validate(Request::all(), [
            'label' => 'required|min:1|max:64',
            'value' => 'required|min:1|max:128',
            'sort' => 'nullable|integer',
            'remark' => 'nullable|max:255',
        ]);
        $exists = Db::select('SELECT id, sort, remark FROM kode_dict_data WHERE id = ? LIMIT 1', [$id]);
        if ($exists === []) {
            return $this->fail('不存在', 404);
        }
        $cur = $exists[0];
        Db::update(
            'UPDATE kode_dict_data SET label = ?, value = ?, sort = ?, remark = ?, updated_at = NOW() WHERE id = ?',
            [
                (string) $data['label'], (string) $data['value'],
                self::pick($data, 'sort', (int) ($cur['sort'] ?? 0), static fn(mixed $v): int => (int) $v),
                self::pick($data, 'remark', (string) ($cur['remark'] ?? ''), static fn(mixed $v): string => (string) $v),
                $id,
            ]
        );

        return $this->ok(['id' => \app\support\HashId::enc($id)]);
    }

    public function destroyItem()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        Db::delete('DELETE FROM kode_dict_data WHERE id = ?', [$id]);

        return $this->ok(['deleted' => \app\support\HashId::enc($id)]);
    }
}
