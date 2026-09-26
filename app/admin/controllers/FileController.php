<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 媒体库：storage/uploads 子树浏览 / 文件夹增删改 / 移动 / 删除 / 详情。
 * 路径一律 uploads 下相对路径，禁 ..，段仅字母数字/_-（月份目录数字兼容）。
 */
final class FileController extends Controller
{
    use ApiResponse;
    use PageWindow;

    private static function base(): string
    {
        return base_path('storage/uploads');
    }

    /** @return ?list<string> null=非法 */
    private static function split(string $rel): ?array
    {
        $rel = trim($rel, '/');
        if ($rel === '') {
            return [];
        }
        if (strlen($rel) > 256 || str_contains($rel, '..')) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $rel) as $seg) {
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $seg) !== 1 && preg_match('/^\d{6}$/', $seg) !== 1) {
                return null;
            }
            $parts[] = $seg;
        }

        return $parts;
    }

    public function index()
    {
        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];
        $parts = self::split((string) $this->query('dir', ''));
        if ($parts === null) {
            return $this->fail('目录非法', 400);
        }
        $dir = self::base() . ($parts === [] ? '' : '/' . implode('/', $parts));
        $items = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = "{$dir}/{$name}";
                $rel = ($parts === [] ? '' : implode('/', $parts) . '/') . $name;
                if (is_dir($path)) {
                    $items[] = ['kind' => 'dir', 'name' => $name, 'path' => "uploads/{$rel}", 'mtime' => date('Y-m-d H:i:s', (int) filemtime($path))];
                    continue;
                }
                if (!is_file($path)) {
                    continue;
                }
                $items[] = self::stat("uploads/{$rel}", $path, $name);
            }
            usort($items, static function ($a, $b): int {
                if ($a['kind'] !== $b['kind']) {
                    return $a['kind'] === 'dir' ? -1 : 1;
                }

                return $b['mtime'] <=> $a['mtime'];
            });
        }
        $total = count($items);
        $items = array_slice($items, $offset, $size);
        // 附带媒体注册 ID（前端按 ID 关联引用）。只对**当页**这几行查：整目录一个 path 一个绑定参数
        // 等于「文件数无界 → SQL 无界」，pgsql 协议自己在 65535 个参数处把请求打成 500。
        $paths = [];
        foreach ($items as $it) {
            if (($it['kind'] ?? '') === 'file') {
                $paths[] = $it['path'];
            }
        }
        $idByPath = [];
        if ($paths !== []) {
            $holders = implode(',', array_fill(0, count($paths), '?'));
            foreach (Db::select("SELECT id, path FROM kode_media WHERE path IN ({$holders})", $paths) as $m) {
                $idByPath[(string) $m['path']] = (int) $m['id'];
            }
        }
        foreach ($items as &$it) {
            if (($it['kind'] ?? '') === 'file') {
                // 对外走 HashId 混淆（AGENTS.md §4）；未登记为空串（前端按 falsy 判断）
                $mid = $idByPath[$it['path']] ?? 0;
                $it['media_id'] = $mid > 0 ? \app\support\HashId::enc($mid) : '';
            }
        }
        unset($it);

        return $this->page($items, $total, $page, $size);
    }

    /** 文件夹树（两层，供左侧目录）。 */
    public function dirs()
    {
        $walk = static function (string $dir, string $prefix, int $depth) use (&$walk): array {
            if ($depth <= 0 || !is_dir($dir)) {
                return [];
            }
            $out = [];
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..' || !is_dir("{$dir}/{$name}")) {
                    continue;
                }
                if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1 && preg_match('/^\d{6}$/', $name) !== 1) {
                    continue;
                }
                $rel = $prefix === '' ? $name : "{$prefix}/{$name}";
                $out[] = ['name' => $name, 'path' => $rel, 'children' => $walk("{$dir}/{$name}", $rel, $depth - 1)];
            }

            return $out;
        };

        return $this->ok($walk(self::base(), '', 3));
    }

    public function mkdir()
    {
        $data = $this->validate(Request::all(), [
            'dir' => 'nullable|max:256',
            'name' => 'required|min:1|max:64',
        ]);
        $parts = self::split((string) ($data['dir'] ?? ''));
        $name = (string) $data['name'];
        if ($parts === null || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1) {
            return $this->fail('目录非法', 400);
        }
        $target = self::base() . ($parts === [] ? '' : '/' . implode('/', $parts)) . '/' . $name;
        if (is_dir($target) || is_file($target)) {
            return $this->fail('已存在', 409);
        }
        mkdir($target, 0775, true);

        return $this->ok(['path' => 'uploads/' . ($parts === [] ? '' : implode('/', $parts) . '/') . $name], 201);
    }

    public function rename()
    {
        $data = $this->validate(Request::all(), [
            'from' => 'required|min:1|max:256',
            'name' => 'required|min:1|max:128',
        ]);
        $src = self::resolve((string) $data['from']);
        $name = (string) $data['name'];
        if ($src === null) {
            return $this->fail('不存在', 404);
        }
        $isDir = is_dir($src);
        if (!$isDir && !is_file($src)) {
            return $this->fail('不存在', 404);
        }
        $pat = $isDir ? '/^[A-Za-z0-9_-]{1,64}$/' : '/^[A-Za-z0-9_]+\.[A-Za-z0-9]+$/';
        if (preg_match($pat, $name) !== 1) {
            return $this->fail('名称非法', 400);
        }
        $dst = dirname($src) . '/' . $name;
        if (is_dir($dst) || is_file($dst)) {
            return $this->fail('已存在', 409);
        }
        rename($src, $dst);
        $rel = substr($dst, strlen(self::base()) + 1);

        return $this->ok(['path' => "uploads/{$rel}"]);
    }

    public function move()    {
        $data = $this->validate(Request::all(), [
            'from' => 'required|min:1|max:256',
            'to_dir' => 'nullable|max:256',
        ]);
        $src = self::resolve((string) $data['from']);
        if ($src === null || !is_file($src)) {
            return $this->fail('不存在', 404);
        }
        $parts = self::split((string) ($data['to_dir'] ?? ''));
        if ($parts === null) {
            return $this->fail('目录非法', 400);
        }
        $dstDir = self::base() . ($parts === [] ? '' : '/' . implode('/', $parts));
        if (!is_dir($dstDir)) {
            mkdir($dstDir, 0775, true);
        }
        $dst = $dstDir . '/' . basename($src);
        if (is_file($dst) || is_dir($dst)) {
            return $this->fail('目标已存在同名文件', 409);
        }
        rename($src, $dst);
        $rel = substr($dst, strlen(self::base()) + 1);

        return $this->ok(['path' => "uploads/{$rel}"]);
    }

    /** 原地替换文件内容（保持路径不变，MIME 重检）。 */
    public function replace()
    {
        $data = (array) (Request::all() ?? []);
        $src = self::resolve((string) ($data['path'] ?? ''));
        if ($src === null || !is_file($src)) {
            return $this->fail('不存在', 404);
        }
        $file = Request::file('file');
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->fail('未收到有效文件', 400);
        }
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $newExt = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if ($newExt !== $ext) {
            return $this->fail('扩展名必须一致', 400);
        }
        $maxMb = \app\support\SysConfig::getInt('upload.max_mb', 10, 1, 100);
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            return $this->fail('文件超过 ' . $maxMb . 'MB', 400);
        }
        $file->moveTo($src . '.tmp');
        rename($src . '.tmp', $src);

        return $this->ok(['path' => (string) ($data['path'] ?? '')]);
    }

    /** 引用串 → 内部媒体 id：兼容 media: 前缀、历史纯数字与 HashId 串。 */
    private static function mediaRefId(string $tok): ?int
    {
        $key = str_starts_with($tok, 'media:') ? substr($tok, 6) : $tok;
        if ($key === '') {
            return null;
        }

        return ctype_digit($key) ? (($key > 0) ? (int) $key : null) : \app\support\HashId::dec($key);
    }

    /** ID/引用统一解析：?ids=<hashid|media:id|id>,… → {引用: url}（远程失败回退本地）。 */
    public function resolveUrls()
    {
        $tokens = array_values(array_filter(array_map(
            static fn($v): string => trim((string) $v),
            explode(',', (string) $this->query('ids', ''))
        ), static fn(string $t): bool => $t !== ''));

        $out = [];
        foreach (array_slice($tokens, 0, 100) as $tok) {
            $id = self::mediaRefId($tok);
            if ($id === null) {
                continue;
            }
            // 键按调用方原样（去 media: 前缀）回传，前端以提交值索引
            $key = str_starts_with($tok, 'media:') ? substr($tok, 6) : $tok;
            $out[$key] = \app\support\StorageManager::url($id);
        }

        return $this->ok($out);
    }

    /** 批量转移磁盘（local 即时生效；远程需 SDK，否则逐条报错）。 */
    public function transfer()
    {
        $data = $this->validate(Request::all(), [
            'ids' => 'required|array',
            'disk_id' => 'required|string',
        ]);
        $diskId = \app\support\HashId::dec((string) $data['disk_id']);
        if ($diskId === null || $diskId <= 0) {
            return $this->fail('disk_id 非法', 400);
        }

        // ids 为对外 HashId 串（兼容历史纯数字），解码后进服务层
        $ids = [];
        $invalid = [];
        foreach (array_values((array) $data['ids']) as $tok) {
            $id = self::mediaRefId((string) $tok);
            if ($id === null) {
                $invalid[] = (string) $tok;
                continue;
            }
            $ids[] = $id;
        }

        $result = \app\support\StorageManager::transfer($ids, $diskId);
        // 出参 id 回到对外形态
        foreach ($result as &$row) {
            if (isset($row['id'])) {
                $row['id'] = \app\support\HashId::enc((int) $row['id']);
            }
        }
        unset($row);
        foreach ($invalid as $tok) {
            $result[] = ['id' => $tok, 'ok' => false, 'msg' => '媒体 ID 非法'];
        }

        return $this->ok($result);
    }

    public function destroy()
    {
        $src = self::resolve((string) $this->param('path'));
        if ($src === null) {
            return $this->fail('不存在', 404);
        }
        if (is_dir($src)) {
            self::rmdir($src);
        } elseif (is_file($src)) {
            @unlink($src);
        } else {
            return $this->fail('不存在', 404);
        }

        return $this->ok(['deleted' => (string) $this->param('path')]);
    }

    /** uploads/xxx 相对路径 → 绝对路径（越界/非法返回 null）。 */
    private static function resolve(string $rel): ?string
    {
        $rel = trim($rel, '/');
        if (!str_starts_with($rel, 'uploads/')) {
            return null;
        }
        $parts = self::split(substr($rel, 8));
        if ($parts === null || $parts === []) {
            return null;
        }
        $name = (string) array_pop($parts);
        // 文件名字符集与下载端同源（UploadController::NAME_PAT）：
        // origin 命名会保留连字符，这里少放一个字符媒体库就列不出也删不掉那个文件。
        if (!preg_match(UploadController::NAME_PAT, $name)
            && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1
            && preg_match('/^\d{6}$/', $name) !== 1) {
            return null;
        }
        $abs = realpath(self::base());
        if ($abs === false) {
            return null;
        }
        $target = $abs . ($parts === [] ? '' : '/' . implode('/', $parts)) . '/' . $name;
        $parent = realpath(dirname($target)) ?: $abs;
        if (!str_starts_with($parent, $abs)) {
            return null;
        }

        return $target;
    }

    /** @return array<string, mixed> */
    private static function stat(string $rel, string $abs, string $name): array
    {
        $isImage = (bool) preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $name);
        $isVideo = (bool) preg_match('/\.(mp4|webm|mov)$/i', $name);
        $mime = (string) (mime_content_type($abs) ?: 'application/octet-stream');
        $width = null;
        $height = null;
        if ($isImage) {
            $dim = @getimagesize($abs);
            if (is_array($dim)) {
                [$width, $height] = [(int) $dim[0], (int) $dim[1]];
            }
        }

        return [
            'kind' => 'file',
            'name' => $name,
            'path' => $rel,
            'url' => '/api/files/' . substr($rel, 8),
            'size' => filesize($abs),
            'mime' => $mime,
            'image' => $isImage,
            'video' => $isVideo,
            'width' => $width,
            'height' => $height,
            'mtime' => date('Y-m-d H:i:s', (int) filemtime($abs)),
        ];
    }

    private static function rmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = "{$dir}/{$name}";
            if (is_dir($path)) {
                self::rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
