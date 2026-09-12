<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 时间列统一为 TIMESTAMPTZ（带时区，导入导出不漂移，与 schema.sql 一致）。
 */
final class AlterTimestampsToTimestamptz extends Migration
{
    public function up(): void
    {
        foreach (['users', 'roles', 'notices'] as $table) {
            foreach (['created_at', 'updated_at'] as $col) {
                Db::statement("ALTER TABLE {$table} ALTER COLUMN {$col} TYPE TIMESTAMPTZ USING {$col}::timestamptz");
            }
        }
    }

    public function down(): void
    {
    }
}
