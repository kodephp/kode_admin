<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 全表统一 kode_ 前缀（migrations 为框架迁移记录表，保持原名）。
 */
final class RenameTablesWithKodePrefix extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'users',
        'roles',
        'role_user',
        'menus',
        'notices',
        'audit_logs',
        'audit_logs_00',
        'audit_logs_01',
        'audit_logs_02',
        'audit_logs_03',
        'sys_configs',
        'depts',
        'plugins',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Db::statement("ALTER TABLE \"{$table}\" RENAME TO \"kode_{$table}\"");
        }
        // 分片库的 notices 表同步改名。
        foreach (['shard_00', 'shard_01'] as $conn) {
            try {
                $db = Db::connection($conn);
                foreach (['notices_00', 'notices_01'] as $table) {
                    $db->statement("ALTER TABLE \"{$table}\" RENAME TO \"kode_{$table}\"");
                }
            } catch (\Throwable) {
            }
        }
        // 序列一并改名（整洁性，不影响功能）。
        $seqs = Db::select(
            "SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' AND sequencename LIKE '%_id_seq'"
        );
        foreach ($seqs as $row) {
            $old = (string) $row['sequencename'];
            foreach (self::TABLES as $table) {
                if (str_starts_with($old, $table . '_')) {
                    $new = 'kode_' . $old;
                    Db::statement("ALTER SEQUENCE \"{$old}\" RENAME TO \"{$new}\"");
                    break;
                }
            }
        }
    }

    public function down(): void
    {
    }
}
