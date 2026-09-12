<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use app\http\concerns\ApiResponse;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 文件上传（本地存储）+ 下载。
 *
 * 生产静态资源请走 nginx/CDN；本接口用于头像、导入模板等中小文件，
 * 下载经鉴权，天然带访问控制（公开 CDN 做不到）。
 */
final class UploadController extends Controller
{
    use ApiResponse;

    private const MAX_SIZE = 10 * 1024 * 1024;

    private static function maxSize(): int
    {
        // upload.max_mb 可配（配置中心，默认 10MB，上限 100MB）。
        $mb = \app\support\SysConfig::getInt('upload.max_mb', 10, 1, 100);

        return $mb * 1024 * 1024;
    }

    /** @var list<string> */
    private const ALLOW_EXT = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf',
        'xls', 'xlsx', 'doc', 'docx', 'txt', 'csv', 'zip',
    ];

    /** @return list<string> */
    private static function allowExt(): array
    {
        // upload.allow_exts 可配（逗号分隔，默认内置白名单）。
        $raw = strtolower((string) \app\support\SysConfig::get('upload.allow_exts', ''));
        $exts = array_values(array_filter(array_map(
            static fn($e): string => trim((string) $e, " \t."),
            explode(',', $raw)
        )));
        $exts = array_values(array_filter($exts, static fn($e): bool => (bool) preg_match('/^[a-z0-9]{1,10}$/', $e)));

        return $exts === [] ? self::ALLOW_EXT : array_values(array_unique($exts));
    }

    public function store()
    {
        $file = Request::file('file');
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->fail('未收到有效文件', 400);
        }
        $maxSize = self::maxSize();
        if ($file->getSize() > $maxSize) {
            return $this->fail('文件超过 ' . (int) ($maxSize / 1024 / 1024) . 'MB', 400);
        }
        $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, self::allowExt(), true)) {
            return $this->fail('不支持的文件类型', 400);
        }
        if (!$this->mimeOk($file, $ext)) {
            return $this->fail('文件内容与扩展名不符（MIME 校验失败）', 400);
        }

        // dir：媒体库目标子目录（uploads 下相对路径，仅字母数字/_-，自动创建）。
        $dir = self::normDir((string) ($this->query('dir', '') ?? ''));
        if ($dir === null) {
            return $this->fail('目录非法', 400);
        }
        $relDir = $dir === '' ? date('Ym') : $dir;
        $base = base_path('storage/uploads');
        $target = $base . '/' . $relDir;
        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }
        $name = self::fileName($ext, (string) $file->getClientFilename());
        $file->moveTo($target . '/' . $name);
        $relPath = "uploads/{$relDir}/{$name}";
        $disk = \app\support\StorageManager::disk(0);
        $mediaId = \app\support\StorageManager::register(
            (string) $file->getClientFilename(),
            $relPath,
            (int) $disk['id'],
            (string) (Request::attr('auth')->username ?? '')
        );

        return $this->ok([
            'id' => $mediaId,
            'path' => $relPath,
            'url' => "/api/files/{$relDir}/{$name}",
            'size' => $file->getSize(),
        ], 201);
    }

    /** @return ?string null=非法，''=默认按月 */
    public static function normDir(string $dir): ?string
    {
        $dir = trim($dir, '/');
        if ($dir === '') {
            return '';
        }
        if (strlen($dir) > 128 || str_contains($dir, '..')) {
            return null;
        }
        foreach (explode('/', $dir) as $seg) {
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $seg) !== 1) {
                return null;
            }
        }

        return $dir;
    }

    /** 命名规则（upload.naming）：date_random / origin / uuid。 */
    private static function fileName(string $ext, string $orig): string
    {
        $rule = \app\support\SysConfig::get('upload.naming', 'date_random');
        if ($rule === 'uuid') {
            return bin2hex(random_bytes(16)) . '.' . $ext;
        }
        if ($rule === 'origin') {
            $base = pathinfo($orig, PATHINFO_FILENAME);
            $base = substr((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $base), 0, 48);
            $base = trim($base, '_') !== '' ? trim($base, '_') : bin2hex(random_bytes(4));

            return $base . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        }

        return date('d') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    }
    private function mimeOk(mixed $file, string $ext): bool
    {
        if (\app\support\SysConfig::getInt('upload.check_mime', 1, 0, 1) !== 1) {
            return true;
        }
        $tmp = (string) $file->getStream()->getMetadata('uri');
        if (!is_file($tmp)) {
            return true;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $map = [
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
            'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
            'pdf' => ['application/pdf'],
            'xls' => ['application/vnd.ms-excel'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'doc' => ['application/msword'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv'],
            'zip' => ['application/zip'],
        ];
        if (!isset($map[$ext])) {
            return true;
        }

        return in_array($mime, $map[$ext], true) || str_starts_with($mime, 'text/');
    }

    public function show()
    {
        // basename 防目录穿越；uploads 下任意层级文件可下载。
        $path = (string) $this->param('path');
        $parts = array_values(array_filter(explode('/', $path), static fn($p) => $p !== '' && $p !== '.' && $p !== '..'));
        if (count($parts) < 2) {
            return $this->fail('不存在', 404);
        }
        $name = (string) array_pop($parts);
        foreach ($parts as $seg) {
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $seg) !== 1 && preg_match('/^\d{6}$/', $seg) !== 1) {
                return $this->fail('不存在', 404);
            }
        }
        if (!preg_match('/^[A-Za-z0-9_]+\.[A-Za-z0-9]+$/', $name)) {
            return $this->fail('不存在', 404);
        }
        $file = base_path('storage/uploads/' . implode('/', $parts) . '/' . $name);
        if (!is_file($file)) {
            return $this->fail('不存在', 404);
        }

        $mime = (string) (mime_content_type($file) ?: 'application/octet-stream');

        return \Kode\Framework\Http\Resp::make('', 200, [])
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) filesize($file))
            ->withBody(\Kode\Http\Psr7\Stream::create((string) file_get_contents($file)));
    }
}
