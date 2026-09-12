<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 字典管理：kode_dict_types（字典类型）+ kode_dict_data（字典项）。
 */
final class CreateDicts extends Migration
{
    public function up(): void
    {
        Db::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS kode_dict_types (
                id          BIGSERIAL PRIMARY KEY,
                name        VARCHAR(64)  NOT NULL,
                code        VARCHAR(64)  NOT NULL UNIQUE,
                remark      VARCHAR(255) NOT NULL DEFAULT '',
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
            SQL);
        Db::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS kode_dict_data (
                id          BIGSERIAL PRIMARY KEY,
                type_code   VARCHAR(64)  NOT NULL,
                label       VARCHAR(64)  NOT NULL,
                value       VARCHAR(128) NOT NULL,
                sort        INTEGER      NOT NULL DEFAULT 0,
                remark      VARCHAR(255) NOT NULL DEFAULT '',
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
            SQL);
        Db::statement('CREATE INDEX IF NOT EXISTS kode_dict_data_type_idx ON kode_dict_data (type_code)');
        $seeds = [
            ['性别', 'sys_gender', [['男', '1'], ['女', '0']]],
            ['是否', 'sys_yes_no', [['是', '1'], ['否', '0']]],
        ];
        foreach ($seeds as [$name, $code, $items]) {
            $exists = Db::select('SELECT id FROM kode_dict_types WHERE code = ? LIMIT 1', [$code]);
            if ($exists !== []) {
                continue;
            }
            Db::insert(
                'INSERT INTO kode_dict_types (name, code, remark, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
                [$name, $code, '系统内置']
            );
            $sort = 0;
            foreach ($items as [$label, $value]) {
                $sort += 10;
                Db::insert(
                    'INSERT INTO kode_dict_data (type_code, label, value, sort, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
                    [$code, $label, $value, $sort]
                );
            }
        }
    }

    public function down(): void
    {
    }
}
