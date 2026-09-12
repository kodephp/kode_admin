<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use app\http\concerns\ApiResponse;
use app\support\HotCache;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 系统配置中心：KV 读写（读走 redis 热缓存，写必失效）。
 *
 * 敏感 key（含 secret、password、token 字样）读接口直接掩码，写不受影响。
 */
final class ConfigController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $rows = Db::select('SELECT ckey, cvalue, remark, ctype, ctitle, cgroup, sort, updated_at FROM kode_sys_configs ORDER BY cgroup, sort, ckey');
        foreach ($rows as &$row) {
            $row['cvalue'] = $this->maskIfSensitive((string) $row['ckey'], (string) $row['cvalue']);
        }
        unset($row);

        return $this->ok($rows);
    }

    public function get()
    {
        $key = (string) $this->param('key');
        if (!preg_match('/^[a-z0-9_.]{2,128}$/', $key)) {
            return $this->fail('不存在', 404);
        }
        $value = HotCache::remember("config:{$key}", 300, static function () use ($key): ?string {
            $rows = Db::select('SELECT cvalue FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);

            return $rows[0]['cvalue'] ?? null;
        });
        if ($value === null) {
            return $this->fail('不存在', 404);
        }

        return $this->ok(['key' => $key, 'value' => $this->maskIfSensitive($key, $value)]);
    }

    public function set()
    {
        $data = $this->validate(Request::all(), [
            'key' => 'required|min:2|max:128',
            'value' => 'nullable|max:2000',
            'remark' => 'nullable|max:255',
            'type' => 'nullable|max:16',
            'title' => 'nullable|max:64',
            'group' => 'nullable|max:32',
            'sort' => 'nullable|integer',
        ]);
        $key = (string) $data['key'];
        if (!preg_match('/^[a-z0-9_.]{2,128}$/', $key)) {
            return $this->fail('键名非法', 400);
        }
        $value = (string) ($data['value'] ?? '');
        $type = in_array(($data['type'] ?? 'text'), ['text', 'number', 'textarea', 'boolean', 'image', 'json'], true)
            ? (string) $data['type'] : 'text';
        $title = substr((string) ($data['title'] ?? ''), 0, 64);
        $group = preg_match('/^[a-z]{2,32}$/', (string) ($data['group'] ?? '')) === 1
            ? (string) $data['group'] : 'custom';
        $sort = (int) ($data['sort'] ?? 0);
        $rows = Db::select('SELECT id FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);
        if ($rows === []) {
            Db::insert(
                'INSERT INTO kode_sys_configs (ckey, cvalue, remark, ctype, ctitle, cgroup, sort, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$key, $value, (string) ($data['remark'] ?? ''), $type, $title, $group, $sort]
            );
        } else {
            Db::update(
                'UPDATE kode_sys_configs SET cvalue = ?, remark = ?, ctype = ?, ctitle = ?, cgroup = ?, sort = ?, updated_at = NOW() WHERE ckey = ?',
                [$value, (string) ($data['remark'] ?? ''), $type, $title, $group, $sort, $key]
            );
        }
        HotCache::forget("config:{$key}");

        return $this->ok(['key' => $key]);
    }

    public function destroy()
    {
        $key = (string) $this->param('key');
        if (!preg_match('/^[a-z0-9_.]{2,128}$/', $key)) {
            return $this->fail('不存在', 404);
        }
        Db::delete('DELETE FROM kode_sys_configs WHERE ckey = ?', [$key]);
        HotCache::forget("config:{$key}");

        return $this->ok(['deleted' => $key]);
    }

    /** 恢复出厂默认值（只补缺失键，不覆盖已有修改）。 */
    public function reset()
    {
        $added = 0;
        foreach (\app\support\SysConfig::defaults() as [$key, $value, $remark, $type, $title, $group, $sort]) {
            $exists = Db::select('SELECT id FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);
            if ($exists !== []) {
                continue;
            }
            Db::insert(
                'INSERT INTO kode_sys_configs (ckey, cvalue, remark, ctype, ctitle, cgroup, sort, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$key, $value, $remark, $type, $title, $group, $sort]
            );
            $added++;
        }

        return $this->ok(['added' => $added]);
    }

    private function maskIfSensitive(string $key, string $value): string
    {
        $lower = strtolower($key);
        // password 这种纯策略键（如 password_min_length）不掩码，只有明确的密钥类才掩。
        foreach (['secret', 'passwd', 'token', 'private', 'salt', 'credential'] as $word) {
            if (str_contains($lower, $word)) {
                return '******';
            }
        }

        return $value;
    }
}
