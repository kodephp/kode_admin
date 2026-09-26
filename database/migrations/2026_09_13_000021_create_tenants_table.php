<?php

declare(strict_types=1);

use Kode\Database\Db\Db;
use Kode\Framework\Database\Migration;

/**
 * 多租户主表：存活跃租户的标识、状态、数据库名。
 */
final class CreateTenantsTable extends Migration
{
    public function up(): void
    {
        Db::statement("
            CREATE TABLE IF NOT EXISTS kode_tenants (
                id VARCHAR(32) PRIMARY KEY,
                name VARCHAR(64) NOT NULL UNIQUE,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                db_name VARCHAR(128) NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
    }

    public function down(): void
    {
        Db::statement('DROP TABLE IF EXISTS kode_tenants');
    }
}
