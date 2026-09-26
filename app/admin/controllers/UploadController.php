<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\concerns\ApiResponse;
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

    /**
     * 允许内联渲染的嗅探类型（图片预览 + PDF/纯文本阅读）。
     *
     * 名单外的一律按附件下发：上传内容是可控的，嗅探结果不能直接决定浏览器的渲染方式
     * （text/html、image/svg+xml、application/xhtml+xml 都能在本域弹脚本）。
     * SVG 刻意不入名单——它天然是脚本容器，即便扩展名白名单里没有 svg。
     *
     * @var list<string>
     */
    private const INLINE_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/avif',
        'image/x-icon', 'application/pdf', 'text/plain',
    ];

    /**
     * uploads 下文件名的合法字符集（含扩展名）。
     *
     * 必须与命名规则同源：`upload.naming = origin` 时 fileName() 保留原文件名的连字符
     * （`my-report.pdf` → `my-report_1a2b3c4d.pdf`），这里少放一个字符，
     * 传上来的文件就永远下载不到（404）。媒体库改名/浏览也用同一个模式（见 FileController）。
     */
    public const NAME_PAT = '/^[A-Za-z0-9_-]{1,128}\.[A-Za-z0-9]{1,10}$/';

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
            mkdir($target, 0775, true);
        }
        $name = self::fileName($ext, (string) $file->getClientFilename());
        $file->moveTo($target . '/' . $name);
        $relPath = "uploads/{$relDir}/{$name}";

        // local_backup：默认读取系统配置 upload.local_backup，可被 query 覆盖。
        // 口径（写清楚，别让人以为关掉勾选就会省本地空间）：这个值目前**只回显、不执行**——
        // 推送成功后本地副本始终保留。「远程盘成功后删本地」需要驱动契约先有删除动词，
        // 且 transfer 回本地盘时要能反查到对象，两者都没落地前删文件等于直接丢资源。
        $cfgDefault = \app\support\SysConfig::getBool('upload.local_backup', true);
        $raw = $this->query('local_backup', null);
        $localBackup = $raw === null || $raw === ''
            ? $cfgDefault
            : ((string) $raw === '1' || strtolower((string) $raw) === 'true');

        $disk = \app\support\StorageManager::disk(0);
        $diskId = (int) $disk['id'];
        $driver = (string) $disk['driver'];
        $remoteUrl = '';

        // 注册本地条目；即使默认盘是远程，也先在本地注册一条（path 指向本地副本）。
        // 这样即便远程推送失败，前端仍能通过本地地址访问，避免"传完即丢"。
        $mediaId = \app\support\StorageManager::register(
            (string) $file->getClientFilename(),
            $relPath,
            $diskId,
            (string) (Request::attr('auth')->username ?? '')
        );

        // 若默认盘是远程：立即推送；失败不阻断（本地副本保留，前端可重试）。
        if ($driver !== 'local') {
            $push = \app\support\StorageManager::pushRemote($disk, $relPath, $mediaId);
            if ($push['ok'] === true) {
                $remoteUrl = (string) ($push['url'] ?? '');
                // 成功推送后，更新 disk_id 为远程盘（前端展示走远程 URL）。
                \Kode\Database\Db\Db::update(
                    'UPDATE kode_media SET disk_id = ? WHERE id = ?',
                    [$disk['id'], $mediaId]
                );
            }
        }

        return $this->ok([
            // 对外 id 走 HashId 混淆（AGENTS.md §4）
            'id'           => \app\support\HashId::enc((int) $mediaId),
            'path'         => $relPath,
            'url'          => "/api/files/{$relDir}/{$name}",
            'size'         => $file->getSize(),
            'disk_id'      => \app\support\HashId::enc($diskId),
            'driver'       => $driver,
            'local_backup' => $localBackup,
            // remote_url 仅推送成功时非空；前端可优先使用。
            'remote_url'   => $remoteUrl,
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
        // 这里不能给 text/* 开兜底：finfo 对「内容是 HTML 的 .txt」直接嗅成 text/html
        // （实测：内联 <script> 的 txt → text/html、PHP 源码 → text/x-php），
        // 兜底等于让改名投递绕过扩展名白名单。合法 txt/csv 嗅探恒为 text/plain（含 BOM/CRLF），
        // 需要收新文本类型时往 $map 里逐个加。
        return in_array($mime, $map[$ext], true);
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
        if (preg_match(self::NAME_PAT, $name) !== 1) {
            return $this->fail('不存在', 404);
        }
        $file = base_path('storage/uploads/' . implode('/', $parts) . '/' . $name);
        if (!is_file($file)) {
            return $this->fail('不存在', 404);
        }

        $mime = (string) (mime_content_type($file) ?: 'application/octet-stream');
        $inline = in_array($mime, self::INLINE_TYPES, true);

        $response = \Kode\Framework\Http\Resp::make('', 200, [])
            ->withHeader('Content-Type', $inline ? $mime : 'application/octet-stream')
            ->withHeader('Content-Length', (string) filesize($file))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody(\Kode\Http\Psr7\Stream::create((string) file_get_contents($file)));

        // $name 已过 NAME_PAT（无引号/CR/LF 面），可直接进头。
        return $inline
            ? $response
            : $response->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
    }
}
