<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 公告正文改 TEXT（富文本 HTML 远超 2000 字符；仅基表，新分片按 Shard::ddlFor 建表自带）。
 * （迁移内避免调用 Shard::tables，其连接切换会打断迁移事务；跨库分片手动对齐。）
 */
final class NoticeBodyText extends Migration
{
    public function up(): void
    {
        Db::statement('ALTER TABLE kode_notices ALTER COLUMN body TYPE TEXT');
    }

    public function down(): void
    {
    }
}
