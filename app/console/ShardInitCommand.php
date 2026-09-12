<?php

declare(strict_types=1);

namespace app\console;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;

/**
 * 分片初始化：建分片库 + 逐库建分片表（当前演示 notices 分片）。
 *
 * 用法：php kode shard:init
 * 建库需管理权限（连 postgres 库执行 CREATE DATABASE）；表结构与 schema.sql 一致。
 */
#[AsCommand(name: 'shard:init', description: '初始化分片库表')]
final class ShardInitCommand extends Command
{
    protected function handle(): int
    {
        $cfg = (array) config('sharding', []);
        if (empty($cfg['enabled'])) {
            $this->warn('分片未开启（SHARD_ENABLED=true 后再执行）');

            return 1;
        }

        $dbCount = max(1, (int) ($cfg['db_count'] ?? 2));
        $tableShards = max(1, (int) ($cfg['table_shards'] ?? 2));
        $dbName = (string) ($cfg['db_name'] ?? 'kode_app_%02d');

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=postgres',
            env('DB_HOST', '127.0.0.1'),
            (int) env('DB_PORT', 5432)
        );
        $pdo = new \PDO($dsn, (string) env('DB_USERNAME', 'root'), (string) env('DB_PASSWORD', ''));
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        for ($i = 0; $i < $dbCount; $i++) {
            $name = sprintf($dbName, $i);
            $exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = " . $pdo->quote($name))->fetch();
            if (!$exists) {
                $pdo->exec('CREATE DATABASE "' . str_replace('"', '""', $name) . '"');
                $this->info("建库：{$name}");
            } else {
                $this->info("库已存在：{$name}");
            }

            $tdsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                env('DB_HOST', '127.0.0.1'),
                (int) env('DB_PORT', 5432),
                $name
            );
            $tdb = new \PDO($tdsn, (string) env('DB_USERNAME', 'root'), (string) env('DB_PASSWORD', ''));
            $tdb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            for ($t = 0; $t < $tableShards; $t++) {
                $table = sprintf('kode_notices_%02d', $t);
                $tdb->exec(
                    "CREATE TABLE IF NOT EXISTS {$table} (
                        id BIGSERIAL PRIMARY KEY,
                        title VARCHAR(128) NOT NULL,
                        body VARCHAR(2000) NOT NULL,
                        created_by VARCHAR(64) NOT NULL DEFAULT '',
                        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                    )"
                );
                // 表属主收归业务账号（默认 root 自建自用则无操作），避免 root 建表业务账号读不到。
                $owner = (string) env('DB_USERNAME', 'root');
                if (preg_match('/^[a-z_][a-z0-9_]*$/', $owner) && $owner !== 'root' && $owner !== 'postgres') {
                    try {
                        $tdb->exec("ALTER TABLE {$table} OWNER TO \"{$owner}\"");
                    } catch (\Throwable) {
                    }
                }
                $this->info("建表：{$name}.{$table}");
            }
        }

        $this->success('分片初始化完成');

        return 0;
    }
}
