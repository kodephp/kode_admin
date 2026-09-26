<?php

declare(strict_types=1);

namespace app\tests;

use app\support\DbBackup;
use app\support\Shard;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Request as HttpRequest;

/**
 * 备份范围的唯一口径 = 各库自己的 pg_tables；一座都不许漏。
 *
 * 修的是三层各自成立的问题：
 *  1. 清单写死 —— 旧的 `DbBackup::TABLES` 是一份 15 项常量，既含写死的
 *     `kode_audit_logs_00.._03`（table_shards 一改就漏片），又只覆盖主库 47 张表里的 15 张
 *     （插件建的全部表、登录日志、媒体、工作流账本一律不在产物里）；
 *  2. 库写死 —— 只 dump `DB_DATABASE` 那一座。而分库分片开着的机器上，公告的**全部真身**
 *     住在 kode_app_00/01（实测主库 kode_notices* 全 0 行、分片库里 3 行）：
 *     所谓「全库备份」把这三行漏得干干净净，恢复侧同样只回主库；
 *  3. 半套产物 —— 任何一节失败都必须整份作废：一份「主库齐、分片库缺」的备份
 *     比一份明确失败的备份危险得多，它会被人当成完整备份拿去恢复。
 *
 * 因此这里的判据是「现场存在的东西必须在产物里」，而不是「常量清单和文档对得上」：
 * 新建一张表不改任何清单就该进备份，写进分片库的一行必须出现在产物里那个库的那一节之后。
 *
 * 第 3 条用**真的 pg_dump**（不是假脚本）跑一遍，假脚本只能证明我们调了它，
 * 证不了产物里有什么。其余用例仍走假 dump，避免依赖本机二进制。
 */
final class BackupScopeTest extends TestCase
{
    protected bool $independentApp = true;

    /** 探针表名：带 kode_ 前缀才会进「归应用管辖」的口径，zz 段保证不与真表同名。 */
    private const PROBE_TABLE = 'kode_zz_backup_scope_probe';

    private string $tmp = '';

    /** @var list<string> */
    private array $trash = [];

    /** @var array<string, string|false> */
    private array $envBackup = [];

    /** 本用例自己插进分片库的公告行：[连接名, 表名, id, 归属键] */
    private array $probeRow = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));

        $this->tmp = sys_get_temp_dir() . '/kode_backup_scope_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->dropProbeRow();
        $this->dropProbeTable();

        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key . '=' . $value);
            }
        }
        $this->envBackup = [];

        foreach ($this->trash as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->trash = [];
        foreach (glob($this->tmp . '/*') ?: [] as $left) {
            @unlink($left);
        }
        @rmdir($this->tmp);

        HttpRequest::clear();

        parent::tearDown();
    }

    // ─── 范围：库 ───────────────────────────────────────────

    public function test_the_scope_names_every_database_the_app_actually_writes_to(): void
    {
        $sections = DbBackup::scope();
        self::assertNotEmpty($sections);
        $dbs = array_column($sections, 'db');
        self::assertSame((string) env('DB_DATABASE', ''), $dbs[0], '第一节必须是主库');
        self::assertCount(count($dbs), array_unique($dbs), '同一座库不该出现两次');

        $registered = $this->registeredShardCount();
        if (!Shard::enabled() || $registered === 0) {
            self::assertCount(1, $sections, '未开分片时范围就只有主库，不该凭 .env 猜出两节');

            return;
        }
        foreach ($sections as $i => $sec) {
            if ($i === 0) {
                continue;
            }
            $conn = (string) $sec['conn'];
            // 库名以那座连接**自己报的** current_database() 为准：照模板猜的话，
            // 配置里 db_name 写错也会「看起来对」，而 dump 出来的其实是主库。
            $actual = (string) (Db::connection($conn)->select('SELECT current_database() AS db')[0]['db'] ?? '');
            self::assertSame($actual, $sec['db'], "{$conn} 的库名来自配置而不是现查");
            self::assertNotSame($dbs[0], $actual, '分片连接指向了主库');
        }
        self::assertCount($registered, array_slice($sections, 1), '注册了几座分片库就该有几节');
    }

    // ─── 范围：表 ───────────────────────────────────────────

    public function test_a_table_nobody_registered_is_still_in_the_scope(): void
    {
        if (!$this->createProbeTable()) {
            self::markTestSkipped('本环境建不出探针表，测不到「清单不再写死」这件事');
        }

        $tables = DbBackup::scope()[0]['tables'];
        self::assertContains(self::PROBE_TABLE, $tables, '现场新增的表没进备份范围：清单仍是写死的');

        // 反向对照：不属于本应用的对象不该被裹进来（前缀口径挡着）
        self::assertNotContains('pg_tables', $tables);
    }

    public function test_an_unregistered_name_is_refused_against_the_live_catalog(): void
    {
        foreach (['pg_shadow', 'kode_zz_no_such_table', 'Kode_Users'] as $bad) {
            try {
                DbBackup::run([$bad], $this->tmp);
                self::fail("现场不存在的表不该放行：{$bad}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($bad, $e->getMessage());
            }
        }
        self::assertSame([], glob($this->tmp . '/*'), '被拒的备份连文件都不该建出来');
    }

    /**
     * 「一张表都没点到」不是「备份全库」，也不是一份成功产物。
     *
     * `null` 才是全库的口径；空数组会走 `narrowTo()`，与现场清单相交之后每节都是空，
     * 而 `partition()` 恒保留主库那节（它是这套系统的家）—— 于是旧实现照常建文件、
     * 照常写那 5 行注释头、循环里一次 dump 都没跑，最后回「备份完成」。
     * 页面拿到的是一份**除了注释什么都不含**的 .sql，体积和「全库备份成功」只差几十字节，
     * 而它恢复不出任何东西。所以这条判定必须落在建文件之前。
     */
    public function test_an_empty_narrowing_is_refused_instead_of_becoming_a_header_only_artifact(): void
    {
        try {
            $file = DbBackup::run([], $this->tmp);
            self::fail('空表清单必须被拒，实际产出了 ' . basename($file)
                . '（' . filesize($file) . ' 字节）');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('全库', $e->getMessage(),
                '拒了要说清「省略 tables 才是全库」，否则操作者会以为这张口坏了');
        }
        self::assertSame([], glob($this->tmp . '/*'), '被拒的备份连文件都不该建出来');
    }

    // ─── 产物内容 ───────────────────────────────────────────

    public function test_a_row_living_only_in_a_shard_database_reaches_the_artifact(): void
    {
        $bin = (string) env('PG_DUMP_BIN', 'pg_dump');
        if (!$this->haveRealDump($bin)) {
            self::markTestSkipped("没有可用的 pg_dump（{$bin}），这条判据必须用真转储");
        }

        $probe = $this->insertProbeRow();
        if ($probe === null) {
            self::markTestSkipped('没有可用的分片库，测不到跨库备份');
        }
        [$conn, $table, $title] = $probe;

        $file = DbBackup::run(null, $this->tmp);
        $this->trash[] = $file;
        $body = (string) file_get_contents($file);

        // 库名从**配置**里读（和被测代码的现查口径是两条来源）：产物那一节的标题
        // 必须就是这行数据真正住着的库，否则「分片库的那一节」只是名字像。
        $cfg = (array) Db::getConfig($conn);
        $db = (string) ($cfg['database'] ?? '');
        self::assertNotSame('', $db, "{$conn} 的配置里没有库名");
        $marker = "\\connect {$db}\n";
        $at = strpos($body, $marker);
        self::assertIsInt($at, "产物里没有分片库 {$db} 的那一节：跨库数据根本没进备份");

        // 只认这一节之后的内容 —— 主库的同名片表里没这行，命中就只能是分片库带出来的
        $section = substr($body, $at);
        $next = strpos($section, "\\connect ", 1);
        if ($next !== false) {
            $section = substr($section, 0, $next);
        }
        self::assertStringContainsString($title, $section, "写在 {$db}.{$table} 的行没进产物那一节");
        self::assertStringContainsString($table, $section);
    }

    public function test_a_narrowed_backup_carries_only_the_sections_holding_the_named_table(): void
    {
        $this->setEnv('PG_DUMP_BIN', $this->writeScript('dump_ok.sh', "select 'fake dump';", 0));

        $file = DbBackup::run(['kode_users'], $this->tmp);
        $this->trash[] = $file;
        $body = (string) file_get_contents($file);

        self::assertSame(1, substr_count($body, "\\connect "), '只点了一张主库的表，产物里不该再有别库的节');
        self::assertStringContainsString("\\connect " . (string) env('DB_DATABASE', '') . "\nBEGIN;\n", $body);
        // escapeshellarg 对纯小写标识符不加引号，所以断言「只有一个 -t 且点名的是它」
        // 而不是去猜引号形状（猜引号会把一条本来正确的产物判成失败）。
        $cmd = $this->lastCmd();
        self::assertSame(1, substr_count($cmd, ' -t '), "收窄一节只该点一张表：{$cmd}");
        self::assertMatchesRegularExpression('/ -t kode_users( |$)/', $cmd);
        self::assertStringNotContainsString('kode_users_0', $cmd, '-t 必须精确到表名');
    }

    public function test_a_later_section_failing_invalidates_the_whole_artifact(): void
    {
        // 第一次成功、之后失败：旧写法只跑一库所以不存在这种形状，跨库之后
        // 「主库齐、分片库缺」的半份产物会被当成完整备份拿去恢复
        if (!Shard::enabled() || $this->registeredShardCount() === 0) {
            self::markTestSkipped('本环境只有一节，测不到「后一节失败」');
        }

        $state = $this->tmp . '/calls';
        $bin = $this->tmp . '/dump_count.sh';
        // 假 dump 自带计数器：第一次成功，之后一律失败
        file_put_contents($bin, implode("\n", [
            '#!/bin/sh',
            'n=$(cat ' . $state . ' 2>/dev/null || echo 0)',
            'n=$((n+1))',
            'echo $n > ' . $state,
            'if [ $n -gt 1 ]; then',
            "    echo 'dump blew up' >&2",
            '    exit 7',
            'fi',
            "echo 'select 1'",
        ]) . "\n");
        chmod($bin, 0o755);
        $this->setEnv('PG_DUMP_BIN', $bin);

        try {
            $file = DbBackup::run(null, $this->tmp);
            $this->trash[] = $file;
            self::fail('后一节失败时必须抛错');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('exit 7', $e->getMessage());
        }
        self::assertSame([], glob($this->tmp . '/kode_backup_*'), '半套产物不能留在盘上');
    }

    public function test_the_artifact_says_what_it_covered(): void
    {
        $this->setEnv('PG_DUMP_BIN', $this->writeScript('dump_ok.sh', "select 'fake dump';", 0));

        $file = DbBackup::run(null, $this->tmp);
        $this->trash[] = $file;
        $head = implode("\n", array_slice(explode("\n", (string) file_get_contents($file)), 0, 6));

        $coverage = DbBackup::coverage(DbBackup::scope());
        self::assertStringContainsString('覆盖：', $head);
        foreach ($coverage as $c) {
            self::assertStringContainsString($c['db'] . '（' . $c['tables'] . ' 张表）', $head);
        }
        self::assertStringContainsString('ON_ERROR_STOP', $head, '产物得告诉恢复侧怎么跑');
    }

    public function test_an_empty_shard_database_is_skipped_and_named_in_the_header(): void
    {
        $sections = [
            ['db' => 'kode_app', 'conn' => null, 'tables' => ['kode_users'], 'exact' => false],
            ['db' => 'kode_app_00', 'conn' => 'shard_00', 'tables' => [], 'exact' => false],
            ['db' => 'kode_app_01', 'conn' => 'shard_01', 'tables' => ['kode_notices_00'], 'exact' => false],
        ];
        $ref = new \ReflectionMethod(DbBackup::class, 'partition');
        $ref->setAccessible(true);
        [$keep, $skipped] = $ref->invoke(null, $sections);

        self::assertSame(['kode_app', 'kode_app_01'], array_column($keep, 'db'), '有内容的节该留下');
        self::assertSame(['kode_app_00（无表）'], $skipped, '空库该被点名，而不是悄悄少一节');
    }

    // ─── 私有辅助 ───────────────────────────────────────────

    private function registeredShardCount(): int
    {
        $count = 0;
        while ($count < 64 && Db::hasConnection(sprintf('shard_%02d', $count))) {
            $count++;
        }

        return $count;
    }

    private function haveRealDump(string $bin): bool
    {
        if ($bin === '' || $bin === 'pg_dump') {
            return $this->which('pg_dump') !== '';
        }

        return is_executable($bin);
    }

    private function which(string $cmd): string
    {
        $out = trim((string) shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null'));

        return $out === '' ? '' : $out;
    }

    private function createProbeTable(): bool
    {
        try {
            Db::statement('CREATE TABLE IF NOT EXISTS ' . self::PROBE_TABLE . ' (id INTEGER PRIMARY KEY, note VARCHAR(32))');

            return Db::connection(Db::getDefaultConnection())->select(
                "SELECT to_regclass('public." . self::PROBE_TABLE . "') AS r"
            )[0]['r'] !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function dropProbeTable(): void
    {
        try {
            Db::statement('DROP TABLE IF EXISTS ' . self::PROBE_TABLE);
        } catch (\Throwable) {
            // 探针表本来就可能没建成
        }
    }

    /**
     * 往某座分片库的公告片里插一行探针。
     *
     * @return array{0: string, 1: string, 2: string}|null [连接名, 表名, 标题]
     */
    private function insertProbeRow(): ?array
    {
        if (!Shard::enabled()) {
            return null;
        }
        for ($d = 0; $d < Shard::dbCount(); $d++) {
            $conn = sprintf('shard_%02d', $d);
            if (!Db::hasConnection($conn)) {
                continue;
            }
            Shard::ensureTables('kode_notices');
            $db = Db::connection($conn);
            $tables = (array) $db->select(
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'kode_notices%' ORDER BY tablename"
            );
            if ($tables === []) {
                continue;
            }
            $table = (string) $tables[array_rand($tables)]['tablename'];
            $owner = 'zz_backup_scope_' . bin2hex(random_bytes(6));
            $title = '跨库备份探针 ' . $owner;
            $id = (int) $db->insert(
                "INSERT INTO {$table} (title, body, created_by) VALUES (?, ?, ?)",
                [$title, 'probe', $owner]
            );
            $this->probeRow = [$conn, $table, $id, $owner];

            return [$conn, $table, $title];
        }

        return null;
    }

    private function dropProbeRow(): void
    {
        if ($this->probeRow === []) {
            return;
        }
        [$conn, $table, $id, $owner] = $this->probeRow;
        $this->probeRow = [];
        try {
            // 只按自己那行的 id + 归属键删：归属键是随机串，命中不了别人的数据
            Db::connection($conn)->delete("DELETE FROM {$table} WHERE id = ? AND created_by = ?", [$id, $owner]);
        } catch (\Throwable) {
        }
    }

    /** 造一个假 pg_dump：忽略参数本身，把整条命令落到 last_cmd 供断言。 */
    private function writeScript(string $name, string $out, int $code): string
    {
        $body = implode("\n", [
            '#!/bin/sh',
            'echo "$*" > ' . $this->tmp . '/last_cmd',
            "echo '" . $out . "'",
            'exit ' . $code,
        ]) . "\n";

        $path = $this->tmp . '/' . $name;
        file_put_contents($path, $body);
        chmod($path, 0o755);

        return $path;
    }

    private function lastCmd(): string
    {
        return trim((string) @file_get_contents($this->tmp . '/last_cmd'));
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->envBackup)) {
            $raw = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            $this->envBackup[$key] = ($raw === false || $raw === null) ? false : (string) $raw;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}
