<?php

declare(strict_types=1);

namespace app\console;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use app\support\Shard;

/**
 * 分片初始化：建分片库 + 逐库建分片表 + 补齐既有片表的列缺口。
 *
 * 用法：php kode shard:init
 * 建库需管理权限（连 postgres 库执行 CREATE DATABASE）；
 * 建表 DDL 恒用 Shard::ddlFor（与运行时按需建表同一口径，不在此复制一份）；
 * 分片基表清单取 Shard::SHARDED_BASES，命令里不另立一份。
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
        // 凭证取主库那份 config：这里不另立账号口径，也不写死角色名（AGENTS：无写死的账号依赖）
        $main = (array) config('database.connections.pgsql', []);
        $user = (string) ($main['username'] ?? '');
        $pass = (string) ($main['password'] ?? '');
        $host = (string) ($main['host'] ?? env('DB_HOST', '127.0.0.1'));
        $port = (int) ($main['port'] ?? 5432);

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=postgres', $host, $port);
        $pdo = new \PDO($dsn, $user, $pass);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        for ($i = 0; $i < $dbCount; $i++) {
            $name = sprintf($dbName, $i);
            // 库名进的是标识符位置（quote 只管值），格式不合法直接跳过而不是拼进 DDL
            if (preg_match('/^[A-Za-z0-9_\-]{4,63}$/', $name) !== 1) {
                $this->warn("库名不合法，跳过：{$name}");
                continue;
            }
            $exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = " . $pdo->quote($name))->fetch();
            if (!$exists) {
                $pdo->exec('CREATE DATABASE "' . str_replace('"', '""', $name) . '"');
                $this->info("建库：{$name}");
            } else {
                $this->info("库已存在：{$name}");
            }

            $tdsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $name);
            $tdb = new \PDO($tdsn, $user, $pass);
            $tdb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            foreach (Shard::SHARDED_BASES as $base) {
                for ($t = 0; $t < $tableShards; $t++) {
                    $table = sprintf('%s_%02d', $base, $t);
                    // DDL 恒用 Shard::ddlFor：运行时建表与这里必须是同一份（建表 + 索引的清单），
                    // 否则命令初始化出来的片会缺列（曾漂到少 status、body 退回 varchar2000）。
                    // 这里**不 catch**：基表读不到、列形状不认识都得让命令红，
                    // 命令回「完成」而片表是错形状，就是假绿。
                    foreach (Shard::ddlFor($base, $table) as $ddl) {
                        $tdb->exec($ddl);
                    }
                    // 表属主收归业务账号（属主即连接账号时这步无操作）
                    $owner = $user;
                    if (preg_match('/^[a-z_][a-z0-9_]*$/', $owner) && $owner !== 'root' && $owner !== 'postgres') {
                        try {
                            $tdb->exec("ALTER TABLE {$table} OWNER TO \"{$owner}\"");
                        } catch (\Throwable) {
                        }
                    }
                    $this->info("建表：{$name}.{$table}");
                }
            }
        }

        // ddlFor 建的是「新片」：对**已存在**的片表那条 CREATE TABLE 什么都不做，
        // 于是「基表后来加了列」这种漂移它修不了（本机就是这么让 kode_notices_* 少了两年 type）。
        // 补齐走运行时那同一个入口（列 + 索引都在那里补），报告如实打出来 ——
        // 命令说「完成」而片表仍缺列，就是假绿。
        $this->reportAlignments();

        $this->success('分片初始化完成');

        return 0;
    }

    /** 触发一次运行时补齐，并把「补了什么 / 还缺什么」打出来。 */
    private function reportAlignments(): void
    {
        foreach (Shard::SHARDED_BASES as $base) {
            Shard::resetEnsured();
            try {
                Shard::ensureTables($base);
            } catch (\Throwable $e) {
                $this->warn("补齐失败（{$base}）：" . $e->getMessage());
                continue;
            }
            foreach (Shard::alignReport() as $row) {
                $this->info("补列：{$row['db']}.{$row['table']} ← " . implode('、', $row['added']));
            }
            foreach (Shard::drift($base) as $row) {
                $this->warn(
                    "仍有缺口：{$row['db']}.{$row['table']} 缺 " . implode('、', $row['missing'])
                    . '（多为当前账号无 ALTER 权限，读写会在这里报错）'
                );
            }
        }
    }
}
