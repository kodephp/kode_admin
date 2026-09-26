<?php

declare(strict_types=1);

namespace app\admin\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Framework\Facades\Event;
use Kode\Http\Request;
use app\concerns\ApiResponse;
use app\support\Clip;
use app\support\DriverRegistry;
use app\support\StorageManager;

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
            $config = json_decode((string) ($row['config'] ?? '{}'), true) ?: [];
            // 列表同样要脱敏：任何登录用户都能拉这张表，而 ak/sk 是云账号凭证。
            // /{id}/config 一路_mask 却在这里漏出去，等于没有脱敏。
            $masked = self::maskConfig($config);
            $row['config'] = $masked === [] ? new \stdClass() : $masked;
        }
        unset($row);

        return $this->ok($rows);
    }

    /**
     * GET /api/storage/{id}/config — 查看通道配置（敏感字段脱敏）。
     *
     * 返回：{ id, name, driver, is_default, config }，其中 config 内
     * ak/sk/api_key/token/password/secret/private_key 等密钥字段统一掩码为
     * `****`（保留前 4 位供识别），避免密钥泄漏。
     */
    public function config()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $row = Db::select(
            'SELECT id, name, driver, config, is_default, created_at, updated_at FROM kode_storage_configs WHERE id = ? LIMIT 1',
            [$id]
        );
        if ($row === []) {
            return $this->fail('不存在', 404);
        }
        $config = json_decode((string) ($row[0]['config'] ?? '{}'), true) ?: [];

        return $this->ok([
            'id' => \app\support\HashId::enc((int) $row[0]['id']),
            'name' => (string) $row[0]['name'],
            'driver' => (string) $row[0]['driver'],
            'is_default' => (int) ($row[0]['is_default'] ?? 0) === 1,
            'created_at' => (string) ($row[0]['created_at'] ?? ''),
            'updated_at' => (string) ($row[0]['updated_at'] ?? ''),
            'config' => self::maskConfig($config),
        ]);
    }

    /**
     * GET /api/storage/{id}/stats — 通道容量与文件统计。
     *
     * - local 盘：按 disk_id 聚合 kode_media，返回 count/size/size_human；
     * - 远程盘：仅返回 count/size（path 字段存的是本地路径，remote 文件清单需另做）。
     *
     * 返回：{ id, driver, file_count, total_bytes, total_human, last_added }
     */
    public function stats()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $row = Db::select('SELECT id, driver FROM kode_storage_configs WHERE id = ? LIMIT 1', [$id]);
        if ($row === []) {
            return $this->fail('不存在', 404);
        }
        $driver = (string) $row[0]['driver'];

        // 该通道下的媒体统计（size 单位 bytes，NULL 兜底 0）
        $stats = Db::select(
            'SELECT COUNT(*) AS c, COALESCE(SUM(size), 0) AS s, MAX(created_at) AS last_added
             FROM kode_media WHERE disk_id = ?',
            [$id]
        );
        $s = $stats[0] ?? ['c' => 0, 's' => 0, 'last_added' => null];

        return $this->ok([
            'id' => \app\support\HashId::enc($id),
            'driver' => $driver,
            'file_count' => (int) $s['c'],
            'total_bytes' => (int) $s['s'],
            'total_human' => self::bytesHuman((int) $s['s']),
            'last_added' => $s['last_added'] ? (string) $s['last_added'] : '',
        ]);
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
        $exists = Db::select('SELECT id, config FROM kode_storage_configs WHERE id = ? LIMIT 1', [$id]);
        if ($exists === []) {
            return $this->fail('不存在', 404);
        }
        $stored = json_decode((string) ($exists[0]['config'] ?? '{}'), true) ?: [];
        $config = self::restoreMasked((array) ($data['config'] ?? []), $stored);
        Db::update(
            'UPDATE kode_storage_configs SET name = ?, config = ?, updated_at = NOW() WHERE id = ?',
            [$data['name'], json_encode($config === [] ? new \stdClass() : $config), $id]
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

    /**
     * POST /api/storage/test — 连通性测试：上传一个极小测试文件到目标存储。
     *
     * 请求体二选一：
     *  - { id }     测试已保存的通道（按 hash id 查询配置）；
     *  - { driver, config }  测试表单中尚未保存的配置（新建/编辑前的预检）。
     *
     * 返回：{ ok, msg, url, duration_ms, test_key }
     * - local 驱动：跳过远端推送，仅校验本地写入路径可读（返回 ok=true）；
     * - 远程驱动：走 StorageManager::pushRemote 派发 storage_driver_resolve，
     *   由插件（FlysystemStoragePlugin）完成真实上传；
     * - test_key 是探针在**远端桶里**的对象键（`kode-probe/…`），运维照它去定位/清理残留；
     *   推送成功只说明「写得进去」，本接口不删它（事件契约里没有删除动词，
     *   口径见 docs/PLUGIN-ARCHITECTURE.md §7.3）。
     */
    public function test()
    {
        $payload = (array) Request::all();
        // 前端传入的 id 为对外 HashId 串（列表已混淆）；非法即 404，不再静默走未保存配置分支
        $rawId = (string) ($payload['id'] ?? '');
        $diskId = 0;
        if ($rawId !== '') {
            $decoded = ctype_digit($rawId) ? (int) $rawId : \app\support\HashId::dec($rawId);
            if ($decoded === null || $decoded <= 0) {
                return $this->fail('不存在', 404);
            }
            $diskId = $decoded;
        }
        $driver = strtolower((string) ($payload['driver'] ?? ''));
        $config = (array) ($payload['config'] ?? []);

        if ($diskId > 0) {
            $rows = Db::select('SELECT id, driver, config FROM kode_storage_configs WHERE id = ? LIMIT 1', [$diskId]);
            if ($rows === []) {
                return $this->fail('不存在', 404);
            }
            $driver = (string) $rows[0]['driver'];
            $config = json_decode((string) ($rows[0]['config'] ?? '{}'), true) ?: [];
        }

        if ($driver === '') {
            return $this->fail('缺少 driver', 400);
        }

        // local 驱动：跳过远端推送，直接验证本地路径可写。
        if ($driver === 'local') {
            $dir = sys_get_temp_dir();
            $probe = tempnam($dir, 'kode_probe_');
            $ok = $probe !== false && is_file($probe);
            if ($ok && is_resource($probe)) {
                fclose($probe);
            }
            if ($probe !== false && is_file($probe)) {
                @unlink($probe);
            }
            $localMsg = $ok ? '通过' : '失败';
            $this->SysLogInfo('storage', "连通性测试（local）：{$localMsg}");

            return $this->ok([
                'ok' => $ok,
                'msg' => $ok ? '本地存储可读写' : '本地存储写入失败',
                'url' => '',
                'duration_ms' => 0,
                'test_key' => '',
            ]);
        }

        if (!DriverRegistry::has($driver)) {
            $allowed = DriverRegistry::allowed();
            return $this->fail(
                '驱动「' . $driver . '」未注册，可用：' . implode('/', $allowed),
                400
            );
        }

        // 生成极小测试文件（< 1KB，避免计费）
        $tmp = sys_get_temp_dir();
        $path = tempnam($tmp, 'kode_storage_test_');
        if ($path === false) {
            return $this->fail('无法创建测试文件', 500);
        }
        $probeContent = 'kode storage probe ' . date('c') . ' ' . uniqid('', true) . PHP_EOL;
        if (file_put_contents($path, $probeContent) === false) {
            @unlink($path);
            return $this->fail('写入测试文件失败', 500);
        }

        // 与 transfer() 同形：交给 pushRemote 的唯一派发点去平铺，调用方不再自己 array_merge 一次
        $disk = ['id' => $diskId, 'driver' => $driver, 'config' => $config];
        // 探针对象单独一个前缀：混进媒体目录就分不清「测试残留」和真文件，也没法整批清。
        $probeKey = 'kode-probe/' . basename($path);
        $start = hrtime(true);

        // 无监听者时快速失败（提示安装对应插件）
        if (!Event::hasListeners(StorageManager::REMOTE_RESOLVE_EVENT)) {
            @unlink($path);
            return $this->fail("驱动 {$driver} 无监听者：请先安装对应存储驱动插件（如 flysystem_storage）", 400);
        }

        $result = StorageManager::pushRemote($disk, $path, 0, $probeKey);
        $duration = (int) ((hrtime(true) - $start) / 1_000_000);

        @unlink($path);

        $testStatus = $result['ok'] ? '通过' : '失败';
        $this->SysLogInfo('storage', "连通性测试（{$driver}）：{$testStatus} - {$result['msg']} ({$duration}ms)");

        return $this->ok([
            'ok' => (bool) $result['ok'],
            'msg' => (string) $result['msg'],
            'url' => (string) $result['url'],
            'duration_ms' => $duration,
            // 回的是**远程对象键**，不是本地临时文件名：运维要拿它去桶里定位/清理这个探针
            'test_key' => $probeKey,
        ]);
    }

    /** 轻量级 SysLog 写入（失败只忽略，不阻断测试）。 */
    private function SysLogInfo(string $module, string $msg): void
    {
        try {
            \app\support\SysLog::info($module, $msg);
        } catch (\Throwable) {
            // 忽略
        }
    }

    /**
     * 密钥字段白名单：递归脱敏配置。
     *
     * 保留前 4 位供管理员识别密钥来源，其余以 **** 掩码。
     * 非字符串值不处理；数组递归处理。
     */
    private const MASK_KEYS = [
        'ak', 'sk', 'access_key', 'access_key_id', 'access_key_secret',
        'secret', 'secret_key', 'api_key', 'apikey', 'token',
        'password', 'passwd', 'private_key', 'signing_key',
        'client_secret', 'session_key', 'app_secret',
    ];

    /** 递归脱敏敏感字段。 */
    private static function maskConfig(array $config): array
    {
        foreach ($config as $k => &$v) {
            $lowerKey = strtolower((string) $k);
            if (in_array($lowerKey, self::MASK_KEYS, true) && is_string($v) && $v !== '') {
                $v = self::maskValue($v);
            } elseif (is_array($v)) {
                $v = self::maskConfig($v);
            }
        }
        unset($v);

        return $config;
    }

    /**
     * 提交值仍是「库里真值的掩码」时，把真值回填回去。
     *
     * 列表/详情现在一律脱敏，编辑表单因此会把 `AKID****` 当作初始值；用户只改一个字段
     * 就整体 PUT 回来时，若不做这层合并，掩码会被当成新密钥写库，通道当场失效且很难归因。
     *
     * @param array<string, mixed> $incoming 客户端提交
     * @param array<string, mixed> $stored   库中现值
     */
    private static function restoreMasked(array $incoming, array $stored): array
    {
        foreach ($incoming as $k => &$v) {
            $previous = $stored[$k] ?? null;

            if (is_array($v)) {
                $v = self::restoreMasked($v, is_array($previous) ? $previous : []);
                continue;
            }

            if (is_string($v) && is_string($previous)
                && in_array(strtolower((string) $k), self::MASK_KEYS, true)
                && $v === self::maskValue($previous)) {
                $v = $previous;
            }
        }
        unset($v);

        return $incoming;
    }

    /** 保留前 4 位，其余以 **** 掩码；短值（<=4 字符）整体掩码。计数一律按字符。 */
    private static function maskValue(string $v): string
    {
        $len = mb_strlen($v);
        if ($len <= 4) {
            return '****';
        }
        return Clip::chars($v, 4) . str_repeat('*', max(0, $len - 4));
    }

    /** 字节数人类可读（B/KB/MB/GB/TB）。 */
    private static function bytesHuman(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        $prec = $i === 0 ? 0 : 2;

        return number_format($n, $prec) . ' ' . $units[$i];
    }
}
