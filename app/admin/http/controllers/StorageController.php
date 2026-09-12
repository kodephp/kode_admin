<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;
use app\http\concerns\ApiResponse;
use app\support\DriverRegistry;

/**
 * 存储配置：按驱动注册表（`DriverRegistry`）管理通道。
 *
 * 声明⟺实现零漂移：可创建的驱动键不是写死的，而是「已启用插件在 `drivers()` 里
 * 声明过」的集合（当前仅 `storage_driver` 插件声明 local，故实际只有 local）。
 * 插件声明并启用新驱动（oss/s3/cos）后，本页无需改代码即自动放开。
 * 历史 remote 行仍可读、可 URL 回退解析，但不能再新增。
 */
final class StorageController extends Controller
{
    use ApiResponse;

    /**
     * 可用驱动目录（前端下拉数据源）：只读，不需要 admin。
     *
     * 由 DriverRegistry 产出，随插件启停即时变化。
     */
    public function drivers()
    {
        $available = DriverRegistry::available();
        $items = [];
        foreach (array_keys($available) as $driver) {
            $items[] = [
                'driver' => $driver,
                'plugin' => $available[$driver],
            ];
        }
        sort($items, static fn($a, $b) => strcmp($a['driver'], $b['driver']));

        return $this->ok($items);
    }

    public function index()
    {
        $rows = Db::select('SELECT id, name, driver, config, is_default FROM kode_storage_configs ORDER BY id');
        foreach ($rows as &$row) {
            $row['id'] = \app\support\HashId::enc((int) $row['id']);
            $row['config'] = json_decode((string) ($row['config'] ?? '{}'), true) ?: new \stdClass();
        }
        unset($row);

        return $this->ok($rows);
    }

    public function store()
    {
        $data = $this->validate(Request::all(), [
            'name' => 'required|min:1|max:64',
            'driver' => 'nullable|min:2|max:16',
            'config' => 'nullable|array',
        ]);
        $driver = strtolower((string) ($data['driver'] ?? 'local'));
        if (!DriverRegistry::has($driver)) {
            $allowed = DriverRegistry::allowed();
            return $this->fail(
                $allowed === []
                    ? '暂无可用驱动：请先安装并启用存储驱动插件'
                    : '驱动「' . $driver . '」未注册，可用：' . implode('/', $allowed),
                400
            );
        }
        $id = Db::insert(
            'INSERT INTO kode_storage_configs (name, driver, config, is_default, created_at, updated_at) VALUES (?, ?, ?, 0, NOW(), NOW())',
            [$data['name'], $driver, json_encode($data['config'] ?? new \stdClass())]
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
            'config' => 'nullable|array',
        ]);
        $exists = Db::select('SELECT id FROM kode_storage_configs WHERE id = ? LIMIT 1', [$id]);
        if ($exists === []) {
            return $this->fail('不存在', 404);
        }
        Db::update(
            'UPDATE kode_storage_configs SET name = ?, config = ?, updated_at = NOW() WHERE id = ?',
            [$data['name'], json_encode($data['config'] ?? new \stdClass()), $id]
        );

        return $this->ok(['id' => \app\support\HashId::enc($id)]);
    }

    public function setDefault()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $row = Db::select('SELECT id, driver FROM kode_storage_configs WHERE id = ? LIMIT 1', [$id]);
        if ($row === []) {
            return $this->fail('不存在', 404);
        }
        if (!DriverRegistry::has((string) $row[0]['driver'])) {
            return $this->fail(
                '驱动「' . $row[0]['driver'] . '」未注册（对应插件未启用），不能设为默认',
                400
            );
        }
        Db::update('UPDATE kode_storage_configs SET is_default = 0 WHERE is_default = 1', []);
        Db::update('UPDATE kode_storage_configs SET is_default = 1 WHERE id = ?', [$id]);

        return $this->ok(['id' => \app\support\HashId::enc($id)]);
    }

    public function destroy()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $row = Db::select('SELECT is_default FROM kode_storage_configs WHERE id = ? LIMIT 1', [$id]);
        if ($row === []) {
            return $this->fail('不存在', 404);
        }
        if ((int) ($row[0]['is_default'] ?? 0) === 1) {
            return $this->fail('默认通道不可删除', 409);
        }
        Db::delete('DELETE FROM kode_storage_configs WHERE id = ?', [$id]);

        return $this->ok(['deleted' => \app\support\HashId::enc($id)]);
    }
}
