<?php

declare(strict_types=1);

use Kode\Database\Db\Db;
use Kode\Framework\Database\Migration;

/**
 * 特性开关表：运行时动态开关 + 灰度发布。
 *
 * 用原生 SQL 建表以规避 Schema::boolean() 在 pgsql 下硬编码 tinyint(1) 的方言 bug
 * （vendor 层不可改；见 vendor/kode/database/src/Schema/Schema.php:550）。
 */
final class CreateFeatureFlagsTable extends Migration
{
    public function up(): void
    {
        Db::statement("
            CREATE TABLE IF NOT EXISTS kode_feature_flags (
                id SERIAL PRIMARY KEY,
                \"key\" VARCHAR(128) NOT NULL UNIQUE,
                description VARCHAR(255) NOT NULL DEFAULT '',
                enabled BOOLEAN NOT NULL DEFAULT TRUE,
                rollout INTEGER NOT NULL DEFAULT 100,
                environment VARCHAR(32) NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
    }

    public function down(): void
    {
        Db::statement('DROP TABLE IF EXISTS kode_feature_flags');
    }
}
