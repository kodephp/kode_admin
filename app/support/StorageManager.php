<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;
use Kode\Framework\Facades\Event;

/**
 * 存储抽象：磁盘（kode_storage_configs）+ 媒体 URL 统一解析。
 *
 * 引用约定：全端关联媒体 ID（`media:123`），展示时经 `url()` 解析：
 * - local 盘 → 本地鉴权地址；
 * - 远程盘（oss/s3/cos…）→ 按配置拼接远程地址，拼不出则回退本地；
 * - 远程推送（transfer）需对应 SDK，未安装时如实报错，不静默失败。
 */
final class StorageManager
{
    /** 远程盘推送事件名（插件驱动经此完成真实上传，见 StorageDriverPlugin）。 */
    public const REMOTE_RESOLVE_EVENT = 'storage_driver_resolve';

    /** @return array{id: int, driver: string, config: array} */
    public static function disk(int $id): array
    {
        if ($id > 0) {
            $rows = Db::select('SELECT id, driver, config FROM kode_storage_configs WHERE id = ? LIMIT 1', [$id]);
            if ($rows !== []) {
                return [
                    'id' => (int) $rows[0]['id'],
                    'driver' => (string) $rows[0]['driver'],
                    'config' => json_decode((string) ($rows[0]['config'] ?? '{}'), true) ?: [],
                ];
            }
        }
        $rows = Db::select("SELECT id, driver, config FROM kode_storage_configs WHERE is_default = 1 LIMIT 1");
        if ($rows !== []) {
            return [
                'id' => (int) $rows[0]['id'],
                'driver' => (string) $rows[0]['driver'],
                'config' => json_decode((string) ($rows[0]['config'] ?? '{}'), true) ?: [],
            ];
        }

        return ['id' => 0, 'driver' => 'local', 'config' => []];
    }

    /** 媒体登记（上传入口调用），返回注册 ID。 */
    public static function register(string $name, string $path, int $diskId = 0, string $owner = ''): int
    {
        $abs = self::localPath($path);
        $mime = is_file($abs) ? (string) (mime_content_type($abs) ?: 'application/octet-stream') : '';
        $size = is_file($abs) ? (int) filesize($abs) : 0;
        $width = 0;
        $height = 0;
        if (str_starts_with($mime, 'image/') && is_file($abs)) {
            $dim = @getimagesize($abs);
            if (is_array($dim)) {
                [$width, $height] = [(int) $dim[0], (int) $dim[1]];
            }
        }
        $exists = Db::select('SELECT id FROM kode_media WHERE path = ? LIMIT 1', [$path]);
        if ($exists !== []) {
            $id = (int) $exists[0]['id'];
            Db::update(
                'UPDATE kode_media SET name = ?, mime = ?, size = ?, width = ?, height = ?, disk_id = ? WHERE id = ?',
                [$name, $mime, $size, $width, $height, $diskId, $id]
            );

            return $id;
        }

        return (int) Db::insert(
            'INSERT INTO kode_media (name, path, mime, size, width, height, disk_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$name, $path, $mime, $size, $width, $height, $diskId, Clip::chars($owner, 64)]
        );
    }

    /** 统一解析：媒体 ID 或 `media:ID` 或直链 → 可展示 URL（远程失败回退本地）。 */
    public static function url(int|string $ref): string
    {
        if (is_string($ref) && str_starts_with($ref, 'media:')) {
            $ref = (int) substr($ref, 6);
        }
        if (is_string($ref)) {
            return $ref;
        }
        $rows = Db::select('SELECT path, disk_id FROM kode_media WHERE id = ? LIMIT 1', [$ref]);
        if ($rows === []) {
            return '';
        }
        $path = (string) $rows[0]['path'];
        $disk = self::disk((int) $rows[0]['disk_id']);
        if ($disk['driver'] !== 'local') {
            $remote = self::remoteObjectUrl($disk, self::remoteObjectKey($disk, $path));
            if ($remote !== '') {
                return $remote;
            }
        }

        return '/api/files/' . substr($path, 8);
    }

    /** 批量转移磁盘：local 之间直接改登记；远程盘经插件驱动真实推送，无实现如实报错。 */
    public static function transfer(array $ids, int $diskId): array
    {
        $disk = self::disk($diskId);
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            $rows = Db::select('SELECT id, path FROM kode_media WHERE id = ? LIMIT 1', [$id]);
            if ($rows === []) {
                $out[] = ['id' => $id, 'ok' => false, 'msg' => '不存在'];
                continue;
            }
            if ($disk['driver'] === 'local') {
                Db::update('UPDATE kode_media SET disk_id = ? WHERE id = ?', [$disk['id'], $id]);
                $out[] = ['id' => $id, 'ok' => true, 'msg' => ''];
                continue;
            }
            $res = self::pushRemote($disk, (string) $rows[0]['path'], $id);
            // 只有真实推送成功才改登记，避免「改了 disk_id 但远程没有文件」。
            if ($res['ok']) {
                Db::update('UPDATE kode_media SET disk_id = ? WHERE id = ?', [$disk['id'], $id]);
            }

            $out[] = $res;
        }

        return $out;
    }

    /**
     * 推送单个媒体到远程盘：派发 storage_driver_resolve，由已注册的插件驱动完成真实上传。
     *
     * 契约（与 StorageDriverPlugin / FlysystemStoragePlugin 对齐）：
     *  - 派发入参：driver / path / key / disk / id；
     *    `path` 恒为**绝对**本地路径（驱动只做 is_file + read，不该按进程 CWD 猜落点），
     *    `key`  恒为**完整**远程对象键（prefix 已含且只出现一次），
     *    `id` 是**媒体** id（disk 内的 `id` 是盘 id，两者不同名不同义）；
     *  - `disk` 恒为**平铺**形态：endpoint/bucket/ak/sk/prefix 直接挂在顶层，不再嵌在 `config` 里；
     *  - 驱动通过 $event->set('pushed', true) + $event->set('url', ...) 回报结果，
     *    失败用 $event->set('pushed', false) + $event->set('msg', ...)；
     *  - 本方法绝不自行拼 URL 假装推送成功；无监听者 / 驱动抛错都如实返回 ok=false。
     *
     * @param string|null $key 覆盖对象键（连通性探针用）；null = 由 $path 推导
     *
     * @return array{id: int, ok: bool, msg: string, url: string}
     */
    public static function pushRemote(array $disk, string $path, int $mediaId, ?string $key = null): array
    {
        $driver = (string) $disk['driver'];
        $disk = self::flattenDisk($disk);
        try {
            if (!Event::hasListeners(self::REMOTE_RESOLVE_EVENT)) {
                return [
                    'id' => $mediaId,
                    'ok' => false,
                    'msg' => "驱动 {$driver} 无实现：安装对应存储驱动插件后重试",
                    'url' => '',
                ];
            }
            $event = Event::dispatch(self::REMOTE_RESOLVE_EVENT, [
                'driver' => $driver,
                'path'   => self::localPath($path),
                'key'    => $key ?? self::remoteObjectKey($disk, $path),
                'disk'   => $disk,
                'id'     => $mediaId,
            ]);
            if (!$event instanceof \Kode\Event\Event) {
                return ['id' => $mediaId, 'ok' => false, 'msg' => '驱动返回异常结果', 'url' => ''];
            }
            $data = $event->getData();
            if (($data['pushed'] ?? false) !== true) {
                return [
                    'id'  => $mediaId,
                    'ok'  => false,
                    'msg' => (string) ($data['msg'] ?? "驱动 {$driver} 未接受推送"),
                    'url' => (string) ($data['url'] ?? ''),
                ];
            }

            return [
                'id'  => $mediaId,
                'ok'  => true,
                'msg' => '',
                'url' => (string) ($data['url'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return [
                'id'  => $mediaId,
                'ok'  => false,
                'msg' => "驱动 {$driver} 推送失败：{$e->getMessage()}",
                'url' => '',
            ];
        }
    }

    /**
     * 派发载荷归一：`disk()` 的嵌套形态（`{id, driver, config:{…}}`）→ 平铺形态。
     *
     * 为什么必须归一：`transfer()` 传的是 `disk()` 原样，`StorageController::test()`
     * 传的是自己 `array_merge` 平铺过的，而插件驱动读的是 `$disk['endpoint']` 这类顶层键。
     * 两种形状并存的结果是「连通性测试通过、批量转移必失败」——同一份配置，两条路径，
     * 只有一条真的能用。顶层的 `id`/`driver` 恒以记录本身为准，只把 config 的键提上来。
     *
     * 本方法对 `flattenDisk` 的下游（派发、算键、算 URL）都是同一份平铺形态。
     *
     * @param array<string, mixed> $disk
     * @return array<string, mixed>
     */
    public static function flattenDisk(array $disk): array
    {
        if (!isset($disk['config']) || !is_array($disk['config'])) {
            return $disk;
        }
        $config = $disk['config'];
        unset($disk['config']);

        return array_merge($config, $disk);
    }

    /**
     * 媒体相对路径 → 本地绝对路径。
     *
     * `kode_media.path` 存的是 `uploads/2026/09/x.png` 这种**相对 storage/** 的串。
     * 此前 `pushRemote()` 把这串原样交给驱动，驱动 `is_file($path)` 按进程 CWD 解析——
     * 守护进程的 CWD 实测是 `/`，于是远程推送永远报「源文件不存在」。
     * 登记侧（register）与推送侧现在同走这一处。
     */
    public static function localPath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path('storage/' . ltrim($path, '/'));
    }

    /**
     * 远程对象键：`{prefix}/{去掉 uploads/ 前缀的媒体路径}`，prefix 恰好出现一次。
     *
     * 这是**唯一**一处算键的地方——写侧（派发给驱动的 `key`）与读侧（`url()` 拼的展示地址）
     * 必须同源。此前驱动自带一份 `{prefix}/{Y/m/d}/{mediaId}_{basename}`、适配器又挂了
     * pathPrefix，对象实际落在 `prefix/prefix/…`，而展示侧按 path 算，两边永远对不上：
     * transfer 成功后 disk_id 翻了，前端拿到的远程 URL 指向一个从未存在过的对象。
     *
     * 键由 path 推导而非随机名，是因为桶里必须能反查回 kode_media 行（path 唯一）。
     */
    public static function remoteObjectKey(array $disk, string $mediaPath): string
    {
        $flat = self::flattenDisk($disk);
        $prefix = trim((string) ($flat['prefix'] ?? ''), '/');
        $rel = str_starts_with($mediaPath, 'uploads/') ? substr($mediaPath, 8) : ltrim($mediaPath, '/');
        $rel = ltrim($rel, '/');

        return ($prefix === '' ? '' : $prefix . '/') . $rel;
    }

    /**
     * 远程对象的访问 URL：`{endpoint}/{bucket}/{key}`；endpoint 或 bucket 缺失回空串
     * （调用方据此回落本地地址，而不是拼出一个点开必 404 的半成品）。
     */
    public static function remoteObjectUrl(array $disk, string $key): string
    {
        $flat = self::flattenDisk($disk);
        $endpoint = rtrim((string) ($flat['endpoint'] ?? ''), '/');
        $bucket = trim((string) ($flat['bucket'] ?? ''), '/');
        if ($endpoint === '' || $bucket === '' || $key === '') {
            return '';
        }

        return "{$endpoint}/{$bucket}/" . ltrim($key, '/');
    }
}
