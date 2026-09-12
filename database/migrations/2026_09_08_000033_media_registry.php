<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 媒体注册表（ID 关联）：上传即登记，全端引用媒体 ID，展示经统一解析；
 * 磁盘抽象（local/oss/s3/cos…），远程推送需对应 SDK（见 StorageManager）。
 */
final class CreateMediaRegistry extends Migration
{
    public function up(): void
    {
        Db::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS kode_media (
                id          BIGSERIAL PRIMARY KEY,
                name        VARCHAR(255) NOT NULL,
                path        VARCHAR(512) NOT NULL UNIQUE,
                mime        VARCHAR(128) NOT NULL DEFAULT '',
                size        BIGINT       NOT NULL DEFAULT 0,
                width       INTEGER      NOT NULL DEFAULT 0,
                height      INTEGER      NOT NULL DEFAULT 0,
                duration    INTEGER      NOT NULL DEFAULT 0,
                disk_id     BIGINT       NOT NULL DEFAULT 0,
                created_by  VARCHAR(64)  NOT NULL DEFAULT '',
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
            SQL);
        Db::statement('CREATE INDEX IF NOT EXISTS kode_media_disk_idx ON kode_media (disk_id)');
        // 存量文件回填（按 path 去重）。
        $base = base_path('storage/uploads');
        if (is_dir($base)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $abs = $file->getPathname();
                $rel = 'uploads/' . ltrim(substr($abs, strlen($base)), '/');
                $exists = Db::select('SELECT id FROM kode_media WHERE path = ? LIMIT 1', [$rel]);
                if ($exists !== []) {
                    continue;
                }
                $mime = (string) (mime_content_type($abs) ?: 'application/octet-stream');
                $width = 0;
                $height = 0;
                if (str_starts_with($mime, 'image/')) {
                    $dim = @getimagesize($abs);
                    if (is_array($dim)) {
                        [$width, $height] = [(int) $dim[0], (int) $dim[1]];
                    }
                }
                Db::insert(
                    'INSERT INTO kode_media (name, path, mime, size, width, height, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
                    [basename($abs), $rel, $mime, $file->getSize(), $width, $height]
                );
            }
        }
    }

    public function down(): void
    {
    }
}
