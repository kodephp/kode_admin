<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;

/**
 * 数据库备份核心（控制器 + 定时任务共用）。
 *
 * 备份范围只有一个来源：**各库自己的 pg_tables**。表名与片名一律不写死 ——
 * 扩片、新插件建表会自动进备份，删掉的片也不会留下「备份里有、库里没有」的幽灵表。
 * 旧实现是一份 15 张表的常量清单（含写死的 `kode_audit_logs_00.._03`），而主库实际有
 * 47 张表、分片库里还住着 notices 的全部真身：那份「全库备份」既漏掉九成业务表，
 * 又跟着 table_shards 的取值漂移 —— 改模数之后备出来的东西和库里的东西不再是同一批。
 *
 * 分库（`sharding.enabled`）时每个分片库各成一节，产物用 psql 的 `\connect` 分段，
 * 恢复侧仍是一条 `psql -f`：每节自带 BEGIN/COMMIT，坏一节不会把别的节拖成半套。
 * 库连不上直接抛错并指名 —— 宁可备份失败，也不能交出一份「看着是全量」的缺片产物。
 *
 * 转储带 `--clean --if-exists --no-owner --no-privileges`：不带 clean 的产物恢复进
 * 已有库时每条 CREATE 都撞「已存在」，而 psql 不加 ON_ERROR_STOP 时退出码恒 0，
 * 于是接口回「恢复完成」而实际一行都没落。owner 不进产物是因为属主即连接账号
 * （AGENTS：无任何写死的账号依赖）。
 *
 * 落盘文件恒为 0600：转储内容含密码哈希与业务数据，临时目录里 0644 等于本机可读。
 * 失败不留半截文件；异常带 pg_dump 的 stderr 原文，调用方只写日志、不进响应。
 */
final class DbBackup
{
    /** 单个产物的体积上限（超出说明该走命令行 pg_dump / 物理备份，而不是这个接口） */
    private const MAX_BYTES = 50 * 1024 * 1024;

    /** 库名口径：与 ShardInitCommand 的建库校验同一条（它要进 psql 的 `\connect` 位置） */
    private const DB_NAME = '/^[A-Za-z0-9_\-]{4,63}$/';

    /** 表名口径：只接受小写标识符，用户输入到不了 shell */
    private const TABLE_NAME = '/^[a-z0-9_]{2,64}$/';

    /** 归本应用管辖的表：业务表恒带 kode_ 前缀，migrations 是框架写死的那张 */
    private const OWNED_SQL = "SELECT tablename FROM pg_tables"
        . " WHERE schemaname = 'public'"
        . " AND (tablename LIKE 'kode\\_%' OR tablename = 'migrations')"
        . ' ORDER BY tablename';

    /**
     * 备份范围（唯一口径）：一个库 = 一节。
     *
     * 只出库名 / 连接名 / 表名，凭证不进这个数组 —— 它会被响应层原样回显。
     * 库名取各连接自己报的 `current_database()`，不抄配置：照 `db_name` 模板拼名字时，
     * 名字写错也能「看起来对」，而实际 dump 的是另一座库（甚至主库）。
     * 两座连接报同一个名字即判重失败 —— 那正是「分片连接回落主库配置」的现场。
     * `tables` 是这一节**实际存在**的表（覆盖面的口径）；`exact` 区分两种转储：
     * 全量一节不带 `-t`（视图/函数/序列这类不在 pg_tables 里的对象也一起进产物），
     * 收窄一节只带被点名的表。
     *
     * @return list<array{db: string, conn: string|null, tables: list<string>, exact: bool}>
     */
    public static function scope(): array
    {
        $sections = [self::section(null)];

        if (Shard::enabled()) {
            for ($d = 0; $d < Shard::dbCount(); $d++) {
                $conn = sprintf('shard_%02d', $d);
                // 未注册时 Db::getConfig() 会回落主库配置，那样等于把主库再 dump 一遍：
                // 分片数据依旧不在产物里，而路由看起来一切正常。这条判定与写侧同源。
                Shard::assertRegistered($conn);
                $sections[] = self::section($conn);
            }
        }

        // 两座连接报同一个名字 = 分片连接回落了主库配置。宁可当场失败，也不能产出一份
        // 「三节里两节是主库、分片数据失踪」的产物 —— 那种产物每次校验都看起来正常。
        $seen = [];
        foreach ($sections as $sec) {
            $who = $sec['conn'] ?? 'default';
            if (isset($seen[$sec['db']])) {
                throw new \RuntimeException(
                    "备份范围里 {$sec['db']} 出现了两次（{$seen[$sec['db']]} 与 {$who}）：连接配置串了库"
                );
            }
            $seen[$sec['db']] = $who;
        }

        return $sections;
    }

    /**
     * 一节：这座库叫什么、里面有哪些归应用的表。
     *
     * @return array{db: string, conn: string|null, tables: list<string>, exact: bool}
     */
    private static function section(?string $conn): array
    {
        $row = self::query($conn, 'SELECT current_database() AS db');
        $db = (string) ($row['db'] ?? '');
        if ($db === '') {
            throw new \RuntimeException('无法确定库名（' . ($conn ?? 'default') . ' 没报 current_database()）');
        }
        if (preg_match(self::DB_NAME, $db) !== 1) {
            // 库名要进产物的 `\connect` 位置（psql 侧不加引号），也进 pg_dump 的参数位
            throw new \RuntimeException('库名不合法，无法纳入备份：' . ($conn ?? 'default') . ' => ' . $db);
        }

        $tables = [];
        foreach (self::query($conn, self::OWNED_SQL, true) as $row) {
            $t = (string) ($row['tablename'] ?? '');
            if ($t !== '') {
                $tables[] = $t;
            }
        }

        return ['db' => $db, 'conn' => $conn, 'tables' => $tables, 'exact' => false];
    }

    /**
     * @param list<array{db: string, conn: string|null, tables: list<string>, exact: bool}> $sections
     *
     * @return list<array{db: string, tables: int}> 各节覆盖的库与表数（自述覆盖面用）
     */
    public static function coverage(array $sections): array
    {
        $out = [];
        foreach ($sections as $sec) {
            $out[] = ['db' => (string) $sec['db'], 'tables' => count((array) $sec['tables'])];
        }

        return $out;
    }

    /**
     * @param list<string>|null $tables  null = 全量（范围内每张表、每座库）
     * @param string|null       $destDir 目标目录（如 storage/backups）；null = 系统临时目录，
     *                                   调用方自己负责收尾（下载即删的场景不该往可见备份里塞文件）
     *
     * @return string 备份文件路径
     */
    public static function run(?array $tables = null, ?string $destDir = null): string
    {
        // 空清单不是「全库」（那是 null）：narrowTo() 与它相交每节都空，而 partition() 恒保留
        // 主库那节，于是旧写法照常建文件、照常写注释头、一次 dump 都不跑就回「备份完成」——
        // 一份除表头什么都不含的 .sql，体积和真备份只差几十字节。判定必须落在建文件之前。
        if ($tables !== null && $tables === []) {
            throw new \InvalidArgumentException('没有指定任何要备份的表：省略 tables 才是备份全库');
        }

        $dir = $destDir ?? sys_get_temp_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new \RuntimeException("备份目录不可用：{$dir}");
        }

        $sections = self::scope();
        if ($tables !== null) {
            $sections = self::narrowTo($sections, $tables);
        }

        // 同一秒内两次备份也会各自成文件：随机后缀，避免 rename/覆盖把上一份顶掉
        $file = $dir . '/kode_backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
        // 先以 0600 建好文件：pg_dump 的 `>>` 只追加不改模式，窗口期因此为零
        $handle = @fopen($file, 'xb');
        if ($handle === false) {
            throw new \RuntimeException("无法创建备份文件：{$file}");
        }
        fclose($handle);
        @chmod($file, 0600);

        try {
            [$dumpable, $skipped] = self::partition($sections);
            self::append($file, "-- Kode 备份 v2：" . date('Y-m-d H:i:s') . "\n"
                . '-- 覆盖：' . (self::coverage($dumpable) === []
                    ? '（无）'
                    : implode('、', array_map(
                        static fn(array $s): string => $s['db'] . '（' . count($s['tables']) . ' 张表）',
                        $dumpable
                    ))) . "\n"
                . ($skipped === [] ? '' : '-- 未纳入：' . implode('、', $skipped) . "\n")
                . '-- 恢复：psql -v ON_ERROR_STOP=1 -d <主库> -f 本文件（每节各自成事务）' . "\n");

            foreach ($dumpable as $sec) {
                $db = (string) $sec['db'];
                // psql 侧的库名不带引号：只放行 shard:init 认的那套字符，越界的当场拒掉
                if (preg_match(self::DB_NAME, $db) !== 1) {
                    throw new \RuntimeException("库名不合法，无法写入恢复指令：{$db}");
                }
                $control = "\\connect {$db}\nBEGIN;\nCOMMIT;\n";
                $before = (int) @filesize($file);
                self::append($file, "\\connect {$db}\nBEGIN;\n");
                self::dump($file, $sec);
                self::append($file, "COMMIT;\n");
                clearstatcache(true, $file);
                $now = (int) filesize($file);
                if ($now - $before <= strlen($control)) {
                    throw new \RuntimeException("节 {$db} 没有产出任何转储内容");
                }
                if ($now > self::MAX_BYTES) {
                    throw new \RuntimeException('库过大（>50MB），请用命令行 pg_dump 备份');
                }
            }
        } catch (\Throwable $e) {
            @unlink($file);

            throw $e;
        }

        clearstatcache(true, $file);
        if (!is_file($file) || filesize($file) === 0) {
            @unlink($file);
            throw new \RuntimeException("备份产物为空：{$file}");
        }

        return $file;
    }

    // ─── 内部 ───────────────────────────────────────────────

    /**
     * 在这一节对应的连接上跑一句只读 SQL。
     *
     * 主库那一节必须钉在**写连接**上取：`Db::select()` 在读写分离时走从库，
     * 从库落后一拍就会把「主库有、从库还没有」的表当成不存在而漏备。
     *
     * @return array<string, mixed>|list<array<string, mixed>> $allRows=false 时是首行
     */
    private static function query(?string $conn, string $sql, bool $allRows = false): array
    {
        try {
            $rows = (array) Db::connection($conn ?? Db::getDefaultConnection())->select($sql);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                '无法读取备份范围（' . ($conn ?? 'default') . '）：' . $e->getMessage(), 0, $e
            );
        }

        return $allRows ? $rows : (array) ($rows[0] ?? []);
    }

    /**
     * 空库不进产物。
     *
     * 一座 0 张表的分片库没有任何可恢复的内容，却会让恢复侧的 `\connect` 在一台没跑过
     * `php kode shard:init` 的目标机上当场断掉；跳过并在产物头部如实写明跳过了谁。
     * 主库那节恒保留：它是这套系统的家，未迁移的新环境也需要一份能落地的产物。
     *
     * @param list<array{db: string, conn: string|null, tables: list<string>, exact: bool}> $sections
     *
     * @return array{0: list<array{db: string, conn: string|null, tables: list<string>, exact: bool}>, 1: list<string>}
     */
    private static function partition(array $sections): array
    {
        $keep = [];
        $skipped = [];
        foreach ($sections as $sec) {
            if ($sec['conn'] !== null && $sec['tables'] === []) {
                $skipped[] = $sec['db'] . '（无表）';
                continue;
            }
            $keep[] = $sec;
        }

        return [$keep, $skipped];
    }

    /**
     * 把范围收窄到调用点指定的表（「只备某几张」的入口）。
     *
     * 白名单 = 现场表清单的并集：新插件建表、扩片都自动可备，拼错的名字与 `pg_shadow`
     * 一类不属于本应用的表照样拒。请求里的表在**每座**库里存在就各备一份，
     * 不含该表的节整节跳过（pg_dump 对不存在的 `-t` 只 warning、退出码 0，
     * 逐节带着空清单跑会产出一堆假「已备份」）。
     *
     * @param list<array{db: string, conn: string|null, tables: list<string>, exact: bool}> $sections
     * @param list<string>                                                                   $tables
     *
     * @return list<array{db: string, conn: string|null, tables: list<string>, exact: bool}>
     */
    private static function narrowTo(array $sections, array $tables): array
    {
        $wanted = [];
        foreach ($tables as $t) {
            $t = (string) $t;
            if (preg_match(self::TABLE_NAME, $t) !== 1) {
                throw new \InvalidArgumentException("不允许备份的表：{$t}");
            }
            $wanted[] = $t;
        }

        $known = [];
        foreach ($sections as $sec) {
            foreach ($sec['tables'] as $t) {
                $known[(string) $t] = true;
            }
        }
        foreach ($wanted as $t) {
            if (!isset($known[$t])) {
                throw new \InvalidArgumentException("不允许备份的表：{$t}");
            }
        }

        $out = [];
        foreach ($sections as $sec) {
            $pick = array_values(array_intersect($sec['tables'], $wanted));
            if ($pick !== []) {
                $sec['tables'] = $pick;
                $sec['exact'] = true;
                $out[] = $sec;
            }
        }

        return $out;
    }

    /**
     * 跑一节 pg_dump，产物追加到 $file（stderr 单独收管道，不混进正文）。
     *
     * @param array{db: string, conn: string|null, tables: list<string>, exact: bool} $sec
     */
    private static function dump(string $file, array $sec): void
    {
        $conn = $sec['conn'];
        if ($conn === null) {
            $host = (string) env('DB_HOST', '127.0.0.1');
            $port = (int) env('DB_PORT', 5432);
            $user = (string) env('DB_USERNAME', 'root');
            $pass = (string) env('DB_PASSWORD', '');
        } else {
            $cfg = (array) Db::getConfig($conn);
            $host = (string) ($cfg['host'] ?? env('DB_HOST', '127.0.0.1'));
            $port = (int) ($cfg['port'] ?? env('DB_PORT', 5432));
            $user = (string) ($cfg['username'] ?? env('DB_USERNAME', 'root'));
            $pass = (string) ($cfg['password'] ?? env('DB_PASSWORD', ''));
        }

        // 全量一节不带 `-t`：让 pg_dump 自己决定库里有什么，视图/函数/序列才不会掉队。
        // 收窄一节才逐表点名（表名已过现场白名单，用户输入到不了 shell）。
        $targets = '';
        if ($sec['exact']) {
            foreach ($sec['tables'] as $t) {
                $targets .= ' -t ' . escapeshellarg((string) $t);
            }
        }

        $cmd = sprintf(
            '%s -h %s -p %d -U %s -d %s --clean --if-exists --no-owner --no-privileges%s >> %s',
            escapeshellarg((string) env('PG_DUMP_BIN', 'pg_dump')),
            escapeshellarg($host),
            $port,
            escapeshellarg($user),
            escapeshellarg((string) $sec['db']),
            $targets,
            escapeshellarg($file)
        );
        $env = ['PGPASSWORD' => $pass, 'PATH' => '/usr/bin:/bin:/opt/homebrew/bin'];
        $code = 1;
        $err = '';
        $proc = proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        if (is_resource($proc)) {
            // 先读完再 wait：stderr 攒满管道缓冲会把子进程阻塞在半路
            $err = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[2]);
            $code = proc_close($proc);
        }
        if ($code !== 0) {
            throw new \RuntimeException(
                '备份失败（' . $sec['db'] . ' exit ' . $code . '）：'
                . ($err === '' ? '检查 pg_dump 可用性与数据库权限' : mb_substr($err, 0, 500))
            );
        }
    }

    private static function append(string $file, string $text): void
    {
        if (@file_put_contents($file, $text, FILE_APPEND) === false) {
            throw new \RuntimeException("备份产物写入失败：{$file}");
        }
    }
}
