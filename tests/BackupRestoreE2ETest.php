<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\BackupController;
use app\support\DbBackup;
use app\support\DbRestore;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 端到端：v2 产物必须在真服务器上真的恢复得回来。
 *
 * 为什么单独一条测试文件：其余备份用例证的都是「产物里有什么」（假 dump，或只读产物字节），
 * 而这次改动同时改了**产物形状**（逐节 `\connect` + BEGIN/COMMIT）和**恢复口径**
 * （`-v ON_ERROR_STOP=1`）。形状对不对，只有真 psql 恢复一遍才知道 —— 静态门禁与语法检查
 * 都看不见「psql 在 `\connect` 处断掉」或「第一条错误之后照样 exit 0」。
 *
 * 两条判据分别挡住两种半套：
 *  1. 全量能落：产物里每一节的表集合，恢复后必须与源库一模一样；
 *  2. 坏一节只回滚那一节：最后一节里塞一条报错，恢复必须**回非零码**、那一节整体不落地，
 *     而前面已提交的节不受牵连。
 *     反向对照同样重要：同一条产物、同一个错误，**去掉 ON_ERROR_STOP** 再跑一遍退出码照样 0 ——
 *     这正是旧接口「一行都没恢复却回恢复完成」的成因。它是实测行为，不是推测，
 *     所以写成断言：哪天这条不成立了，说明 psql 改了语义，那就连带改上面的理由与文案。
 *
 * 只往 **scratch 库**里恢复：产物里每节的 `\connect <源库>` 整段换成 `kode_zz_bkp_*` 临时库名，
 * 用完即 DROP。真实库（kode_app / kode_app_00..）全程只读，一行都不写。
 * 没有真 pg_dump 或没有建库权限时 skip 并写明原因 —— 这类判据不能靠假脚本凑绿。
 *
 * 恢复一律走 `app\support\DbRestore`（产品侧那一条路），测试不另抄一份命令：
 * 上一轮正是「控制器里自己抄了一份 proc_open」把 fd2 写成 `['stream','php://stdout']`
 * 而 php:// 字符串不是资源 → proc_open 恒返回 false → 恢复接口对谁都固定 500，
 * 且没有任何一条测试看得见（退出码判据全在下游，进程根本没启动）。
 */
final class BackupRestoreE2ETest extends TestCase
{
    protected bool $independentApp = true;

    /** scratch 库名前缀：与真实库一眼可分，且过 DbBackup 的库名口径。 */
    private const SCRATCH = 'kode_zz_bkp';

    private string $tmp = '';

    /** @var list<string> 本次建的 scratch 库，tearDown 逐个 DROP */
    private array $createdDbs = [];

    /** @var list<string> 本次落盘的产物文件 */
    private array $trash = [];

    /** @var array<string, string|false> */
    private array $envBackup = [];

    /** 本次真产物路径 */
    private string $artifact = '';

    /** 恢复前从源库抓的内容快照：[源库, 表, 标题列表] */
    private array $contentBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));

        $this->tmp = sys_get_temp_dir() . '/kode_backup_e2e_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDbs as $db) {
            $this->admin('DROP DATABASE IF EXISTS ' . $db);
        }
        $this->createdDbs = [];

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

        HttpRequest::clear();

        parent::tearDown();
    }

    public function test_every_section_of_a_real_artifact_lands_in_a_real_server(): void
    {
        $dbs = $this->requireRealArtifact();
        [$file, $map] = $this->remapToScratch($dbs);

        $run = $this->runSqlFile((string) reset($map), $file);
        self::assertSame(0, $run['code'], "真恢复没跑通（exit {$run['code']}）：\n" . $this->tail($run['out']));

        foreach ($dbs as $db) {
            $scratch = (string) $map[$db];
            self::assertSame(
                $this->tablesOf($db),
                $this->tablesOf($scratch),
                "节 {$db} 恢复后的表集合与源库不一致（缺 = 半套，多 = 串了别库的对象）"
            );
        }

        // 数据真的跨过库边界：产物自称覆盖的表必须真在，且恢复前的源库内容必须在恢复后的库里
        foreach ($this->contentBefore as [$db, $table, $titles]) {
            if ($titles === []) {
                continue;
            }
            $scratch = (string) $map[$db];
            $after = $this->rows($scratch, 'SELECT title FROM ' . $table . ' ORDER BY id');
            foreach ($titles as $t) {
                self::assertContains(
                    $t,
                    $after,
                    "写在 {$db}.{$table} 的行没被这次恢复带进 {$scratch}：跨库数据只存在于产物文本里"
                );
            }
        }
    }

    public function test_a_broken_section_rolls_back_only_itself_and_the_exit_code_lied_before(): void
    {
        $dbs = $this->requireRealArtifact();
        if (count($dbs) < 2) {
            self::markTestSkipped('本环境产物只有一节，测不到「坏一节不牵连别节」');
        }

        [$file, $map] = $this->remapToScratch($dbs);
        $last = (string) end($dbs);
        $poisoned = $this->poisonBeforeLastCommit($file, (string) $map[$last]);
        $scratchLast = (string) $map[$last];

        // 让最后一节的库先处于「有内容、但与产物无关」的状态：
        // 标记行证明这座库确实是落点；恢复后它还在，而产物里那批表一张都不该在。
        $this->admin('DROP DATABASE IF EXISTS ' . $scratchLast);
        $this->admin('CREATE DATABASE ' . $scratchLast);
        $this->adminSql($scratchLast, 'CREATE TABLE kode_zz_marker (id INT)');
        $this->adminSql($scratchLast, 'INSERT INTO kode_zz_marker VALUES (1)');

        $run = $this->runSqlFile((string) reset($map), $poisoned);
        self::assertNotSame(
            0,
            $run['code'],
            "带错误的产物恢复居然 exit 0：恢复接口会在「什么都没恢复」时回「恢复完成」（输出：\n"
            . $this->tail($run['out']) . '）'
        );

        $leftover = array_intersect($this->tablesOf($last), $this->tablesOf($scratchLast));
        self::assertSame(
            [],
            array_values($leftover),
            "坏掉那一节没有整体回滚，{$scratchLast} 里留下了半套对象：" . implode(', ', $leftover)
        );
        self::assertSame(
            ['1'],
            $this->rows($scratchLast, 'SELECT id FROM kode_zz_marker'),
            "恢复失败连带动了别的东西：{$scratchLast} 里原有的标记行不见了"
        );

        // 第一节必须照常落地：证明「一节一事务」不是「一处失败全站回滚」
        $first = (string) $dbs[0];
        self::assertContains(
            'kode_users',
            $this->tablesOf((string) $map[$first]),
            '最后一节失败，把已经提交的第一节也拖没了：节与节之间不该互相牵连'
        );

        // 反向对照：同一份产物、同一个错误，不加 ON_ERROR_STOP 时退出码是 0。
        $naive = $this->runSqlFileNaive((string) reset($map), $poisoned);
        self::assertSame(
            0,
            $naive['code'],
            'psql 不加 ON_ERROR_STOP 时居然会回非零：这条收口的理由已经不成立，'
            . '请把本用例与 BackupController 里那段注释一起改掉（输出：' . $this->tail($naive['out']) . '）'
        );
    }

    /**
     * DbRestore 必须真的把子进程跑起来、真的读到它的话。
     *
     * 挡的是上一轮自己引入的坏法：fd2 写成 `['stream', 'php://stdout']` —— PHP 的 stream
     * 描述符只认「stream + 已打开的资源」，字符串路径让 proc_open **直接返回 false**，
     * 于是恢复接口对任何人都固定回 500「恢复进程启动失败」。这类坏法在「退出码非零」上
     * 与真失败长得一样，所以两条都得验：非零码 + **读到了 psql 的报错原文**
     * （报错走 stderr，没并管就什么都读不到）。
     */
    public function test_run_actually_starts_the_process_and_reads_its_output(): void
    {
        $scratch = $this->scratchDb('e');

        $bad = $this->tmp . '/one_bad.sql';
        file_put_contents($bad, "SELECT 1 / 0;\n");
        $run = DbRestore::run($bad, $scratch);
        self::assertNotSame(0, $run['code'], '失败没回非零码');
        // 报文字面量随 LC_MESSAGES 变（本机中文：「除以零」），只认 psql 自己加的
        // 「psql:<文件>:<行>:」前缀 —— 它只可能来自 stderr，正好证明并管生效。
        self::assertStringContainsString(
            'one_bad.sql:1:',
            $run['out'],
            "没读到子进程的报错原文（exit {$run['code']}，out=" . $this->tail($run['out']) . '）：'
            . 'proc_open 没起来、或 stderr 被丢进了 /dev/null —— 恢复失败会变成「无原因可查」'
        );

        $good = $this->tmp . '/one_ok.sql';
        file_put_contents($good, "CREATE TABLE kode_zz_ran (id INT);\n");
        $ok = DbRestore::run($good, $scratch);
        self::assertSame(0, $ok['code'], '正常恢复被判失败：' . $this->tail($ok['out']));
        self::assertSame(['kode_zz_ran'], $this->rows($scratch, "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"));
    }

    /**
     * 恢复接口这一层：响应必须说得出子进程的话，门禁必须挡在起进程之前。
     *
     * `DbRestore` 绿不代表接口绿 —— 上一轮的坏法就发生在调用方（fd 描述符写错，
     * 进程根本没启动，接口固定 500）。这里把接口侧的四条口径各自钉住：
     * 确认串、文件名白名单、成功要把数据真的落进库、失败要带着原因回来。
     * 目标库通过 `DB_DATABASE` 指向 scratch：恢复的 sql 里不含 `\connect`，
     * 所以真实库一行都收不到。
     */
    public function test_the_restore_endpoint_says_what_the_subprocess_actually_said(): void
    {
        $scratch = $this->scratchDb('p');
        $dir = base_path('storage/backups');
        if (!is_dir($dir)) {
            self::markTestSkipped('没有 storage/backups 目录，接口侧的白名单解析无从谈起');
        }
        $this->setEnv('DB_DATABASE', $scratch);

        $good = 'kodezz_good_' . bin2hex(random_bytes(4)) . '.sql';
        file_put_contents($dir . '/' . $good, "CREATE TABLE kode_zz_ep (id INT);\nINSERT INTO kode_zz_ep VALUES (7);\n");
        $this->trash[] = $dir . '/' . $good;
        $bad = 'kodezz_bad_' . bin2hex(random_bytes(4)) . '.sql';
        file_put_contents($dir . '/' . $bad, "SELECT 1 / 0;\n");
        $this->trash[] = $dir . '/' . $bad;

        // 门禁在起进程之前：没确认串、文件名越界都不该动库
        $noConfirm = $this->callRestore(['file' => $good]);
        self::assertSame(400, $noConfirm['status'], '确认门禁没挡住');
        self::assertSame([], $this->rows($scratch, "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"));
        $traversal = $this->callRestore(['file' => '../../.env', 'confirm' => 'I_UNDERSTAND_THE_RISK']);
        self::assertSame(404, $traversal['status'], '文件名白名单没挡住穿越');

        $ok = $this->callRestore(['file' => $good, 'confirm' => 'I_UNDERSTAND_THE_RISK']);
        self::assertSame(200, $ok['status'], '恢复成功却回失败：' . $ok['msg']);
        self::assertSame(
            ['7'],
            $this->rows($scratch, 'SELECT id FROM kode_zz_ep'),
            '接口回了恢复完成，库里却没有那行 —— 「成功」必须是数据真的在'
        );

        $fail = $this->callRestore(['file' => $bad, 'confirm' => 'I_UNDERSTAND_THE_RISK']);
        self::assertSame(500, $fail['status'], '恢复失败居然回成功');
        self::assertStringContainsString('exit', $fail['msg'], '失败响应没带退出码：' . $fail['msg']);
        self::assertStringContainsString(
            $bad . ':1:',
            $fail['msg'],
            "失败响应里没有子进程的原因（{$fail['msg']}）：「进程没起来」与「SQL 报错」在响应上必须分得开"
        );
    }

    // ─── 私有：环境与产物 ───────────────────────────────────

    /** 建一座本次专用的 scratch 库；环境不具备时 skip 并写明缺哪一样。 */
    private function scratchDb(string $tag): string
    {
        if ($this->admin('SELECT 1')['code'] !== 0) {
            self::markTestSkipped('连不上 postgres 管理库：本判据只能恢复进 scratch 库');
        }
        $db = self::SCRATCH . '_' . $tag . substr(md5((string) (microtime(true) . random_bytes(4))), 0, 8);
        $create = $this->admin('CREATE DATABASE ' . $db);
        if ($create['code'] !== 0) {
            self::markTestSkipped('本环境没有建库权限（' . $this->tail($create['out']) . '）：测不到真恢复');
        }
        $this->createdDbs[] = $db;

        return $db;
    }

    /**
     * 产一份真产物，回来自它自己声明的节（`\connect` 落点）。
     *
     * 以产物文本为准而不是以 scope() 为准：空库那节根本不会进产物（partition 会跳过并
     * 在头部点名），照 scope 建 scratch 库就会去断言一节根本不存在的内容。
     *
     * @return list<string> 产物里出现的源库名（按出现顺序）
     */
    private function requireRealArtifact(): array
    {
        $bin = (string) env('PG_DUMP_BIN', 'pg_dump');
        $real = $bin === 'pg_dump' ? trim((string) shell_exec('command -v pg_dump 2>/dev/null')) : $bin;
        if ($real === '' || !is_executable($real)) {
            self::markTestSkipped("没有可用的 pg_dump（{$bin}）：端到端恢复必须用真转储");
        }
        if ($this->admin('SELECT 1')['code'] !== 0) {
            $why = $this->admin('SELECT 1');
            self::markTestSkipped(
                '连不上 postgres 管理库（exit ' . $why['code'] . '：' . $this->tail($why['out']) . '），'
                . '建不出 scratch 库：本判据只能恢复进 scratch 库'
            );
        }
        $probe = self::SCRATCH . '_p' . substr(md5((string) microtime(true)), 0, 8);
        $create = $this->admin('CREATE DATABASE ' . $probe);
        if ($create['code'] !== 0) {
            self::markTestSkipped('本环境没有建库权限（' . $this->tail($create['out']) . '）：测不到真恢复');
        }
        $this->admin('DROP DATABASE IF EXISTS ' . $probe);

        $this->artifact = DbBackup::run(null, $this->tmp);
        $this->trash[] = $this->artifact;
        $body = (string) file_get_contents($this->artifact);

        preg_match_all('/^\\\\connect (\S+)$/m', $body, $m);
        $dbs = array_values(array_unique($m[1]));
        self::assertNotEmpty($dbs, '产物里一条 \connect 都没有：分段形状已经不是这版口径');

        // 内容快照（只读源库）：恢复之后再对比，证明数据真跨过了库边界
        foreach ($dbs as $db) {
            foreach ($this->tablesOf($db) as $t) {
                if (strncmp($t, 'kode_notices', 12) !== 0) {
                    continue;
                }
                $this->contentBefore[] = [
                    $db,
                    $t,
                    $this->rows($db, 'SELECT title FROM ' . $t . ' ORDER BY id LIMIT 5'),
                ];
            }
        }

        return $dbs;
    }

    /**
     * 把产物里每节的 `\connect <源库>` 换成一座新建的 scratch 库。
     *
     * @param list<string> $dbs
     *
     * @return array{0: string, 1: array<string, string>} [改写后的产物, 源库 => scratch 库]
     */
    private function remapToScratch(array $dbs): array
    {
        $map = [];
        foreach ($dbs as $db) {
            $scratch = self::SCRATCH . '_' . substr(md5($db . '|' . $this->artifact), 0, 10);
            $create = $this->admin('CREATE DATABASE ' . $scratch);
            self::assertSame(0, $create['code'], "建不出 scratch 库 {$scratch}：" . $this->tail($create['out']));
            $this->createdDbs[] = $scratch;
            $map[$db] = $scratch;
        }

        $body = (string) file_get_contents($this->artifact);
        $rewritten = preg_replace_callback(
            '/^\\\\connect (\S+)$/m',
            static function (array $m) use ($map): string {
                self::assertArrayHasKey($m[1], $map, "产物里出现没打算恢复的库 {$m[1]}：拒绝往它身上写");

                return "\\connect " . $map[$m[1]];
            },
            $body
        );
        self::assertIsString($rewritten);
        self::assertNotSame($body, $rewritten, '一条 \connect 都没换成：scratch 库根本没被指向');
        foreach ($dbs as $db) {
            self::assertStringNotContainsString(
                "\\connect {$db}\n",
                $rewritten,
                "改写后仍留着指向真实库 {$db} 的那一节：这条测试绝不允许往真库恢复"
            );
        }

        $out = $this->tmp . '/restore_' . bin2hex(random_bytes(4)) . '.sql';
        $this->trash[] = $out;
        file_put_contents($out, $rewritten);

        return [$out, $map];
    }

    /** 在最后一节的收尾 COMMIT 之前插一条必错语句，用来验「坏一节只回滚自己」。 */
    private function poisonBeforeLastCommit(string $file, string $scratchLast): string
    {
        $body = (string) file_get_contents($file);
        $at = strrpos($body, "\\connect {$scratchLast}\n");
        self::assertIsInt($at, '产物里没有最后一节的落点');
        $end = strrpos($body, "COMMIT;\n");
        self::assertIsInt($end, '产物里没有收尾的 COMMIT：逐节事务的形状已经不是这版口径');
        self::assertGreaterThan($at, $end, '最后一节的 COMMIT 跑到了落点之前');

        $out = $this->tmp . '/poisoned_' . bin2hex(random_bytes(4)) . '.sql';
        $this->trash[] = $out;
        file_put_contents($out, substr($body, 0, $end) . "SELECT 1 / 0;\n" . substr($body, $end));

        return $out;
    }

    /** @return list<string> 该库 public 下的表名（排序后） */
    private function tablesOf(string $db): array
    {
        $rows = $this->rows($db, "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");

        return array_values(array_map(static fn(string $r): string => trim($r), $rows));
    }

    // ─── 私有：psql ─────────────────────────────────────────

    private function cred(string $key, string $default): string
    {
        return (string) env($key, $default);
    }

    /** 连 postgres 管理库执行一句（建库/删库）。 */
    private function admin(string $sql): array
    {
        return $this->psql('postgres', $sql);
    }

    private function adminSql(string $db, string $sql): array
    {
        return $this->psql($db, $sql);
    }

    /** @return list<string> 单列原文 */
    private function rows(string $db, string $sql): array
    {
        $run = $this->psql($db, $sql);
        if ($run['code'] !== 0) {
            self::fail("查询失败（{$db}）：{$sql}\n" . $this->tail($run['out']));
        }
        $out = trim($run['out']);

        return $out === '' ? [] : explode("\n", $out);
    }

    private function psql(string $db, string $sql): array
    {
        return $this->spawn(sprintf(
            'psql -h %s -p %d -U %s -d %s -X -q -tA -v ON_ERROR_STOP=1 -c %s 2>&1',
            escapeshellarg($this->cred('DB_HOST', '127.0.0.1')),
            (int) $this->cred('DB_PORT', '5432'),
            escapeshellarg($this->cred('DB_USERNAME', 'root')),
            escapeshellarg($db),
            escapeshellarg($sql)
        ));
    }

    /** 产品侧那一条：恢复只走 DbRestore 这个派发口（测试不再抄一份命令）。 */
    private function runSqlFile(string $initialDb, string $file): array
    {
        return DbRestore::run($file, $initialDb);
    }

    /** 反向对照专用：故意不加 ON_ERROR_STOP，用来证明「退出码 0 ≠ 恢复成功」。 */
    private function runSqlFileNaive(string $initialDb, string $file): array
    {
        return DbRestore::run($file, $initialDb, false);
    }

    /** @return array{code: int, out: string} */
    private function spawn(string $cmd): array
    {
        $env = [
            'PGPASSWORD' => $this->cred('DB_PASSWORD', ''),
            'PATH' => '/usr/bin:/bin:/opt/homebrew/bin',
            'LANG' => 'en_US.UTF-8',
        ];
        $out = '';
        $code = 1;
        // fd2 用 /dev/null 而不是 ['stream','php://stdout']：后者第二元必须是已打开的资源，
        // 传字符串路径会让 proc_open 直接返回 false（命令里已有 2>&1，报错本来并进 fd1）
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        if (is_resource($proc)) {
            $out = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($proc);
        }

        return ['code' => $code, 'out' => $out];
    }

    private function tail(string $text, int $limit = 1200): string
    {
        $text = trim($text);

        return strlen($text) <= $limit ? $text : '…' . substr($text, -$limit);
    }

    // ─── 私有：接口侧 ───────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     *
     * @return array{status: int, msg: string, data: array<string, mixed>}
     */
    private function callRestore(array $body): array
    {
        HttpRequest::setRequest(new ServerRequest(
            'POST',
            '/api/system/backups/restore',
            [],
            ['content-type' => 'application/json'],
            (string) json_encode($body)
        ));

        $response = (new BackupController())->restore();
        $decoded = (array) json_decode((string) $response->getBody(), true);

        return [
            'status' => (int) ($decoded['code'] ?? $response->getStatusCode()),
            'msg' => (string) ($decoded['msg'] ?? ''),
            'data' => (array) ($decoded['data'] ?? []),
        ];
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
