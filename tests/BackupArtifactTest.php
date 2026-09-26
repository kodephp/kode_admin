<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\BackupController;
use app\admin\controllers\SystemController;
use app\support\DbBackup;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Framework\Validation\ValidationException;

/**
 * 备份产物必须落在有人看管的地方，且危险参数不许替用户做决定。
 *
 * 修的是三类各自成立的问题：
 *  1. 产物错位 —— `run()` 把转储写进系统临时目录，而列表/下载/清理三个接口只认
 *     `storage/backups`：手动备份当场成功、回头就找不到文件，等于没备份；
 *  2. 权限外泄 —— 转储含密码哈希，落盘若是 0644 本机任意用户可读；先以 0600 建好
 *     文件再让 pg_dump 覆盖（`>` 只截断不改模式），窗口期才为零。同一秒两次备份
 *     也不能互相顶掉（旧命名到秒，第二份直接盖第一份）；
 *  3. 破坏性入参静默夹取 —— `clean()` 把 days 夹进 1..3650：传 0 / 漏传 / 传负数
 *     一律变成「删掉 1 天前的全部备份」，一次手误就是一次无告警的批量删除。
 *
 * 另两条口径：失败原因（pg_dump 的 stderr）只进日志不进响应，且不再混进 sql 正文；
 * 失败时不留半截文件。
 *
 * 全程用假 pg_dump 脚本（覆盖 PG_DUMP_BIN），不碰真库也不依赖本机二进制。
 */
final class BackupArtifactTest extends TestCase
{
    protected bool $independentApp = true;

    /** 与 BackupController::BACKUP_DIR 同口径（那边是 private const，此处只能对齐字面量）。 */
    private const DIR = 'storage/backups';

    /** 假 dump 失败时打到 stderr 的标记，用来断言它不会外泄进响应。 */
    private const LEAK_MARK = 'SECRET_CONNECT_DETAIL_HOST_10_0_0_7';

    private string $tmp = '';

    private string $okBin = '';

    private string $badBin = '';

    private string $warnBin = '';

    /** @var list<string> 待清理的绝对路径 */
    private array $trash = [];

    /** @var list<string> 测试开始前 storage/backups 里已有的文件，用于识别本次新增 */
    private array $dirBefore = [];

    /** @var list<array{0: string, 1: string}> 被临时改名的既有备份（[现名, 原名]），收尾复原 */
    private array $held = [];

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));

        $this->tmp = sys_get_temp_dir() . '/kode_backup_test_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
        $this->dirBefore = glob(base_path(self::DIR) . '/*.sql') ?: [];
        $this->okBin = $this->writeScript('dump_ok.sh', "select 'fake dump';", '', 0);
        $this->badBin = $this->writeScript('dump_bad.sh', '', self::LEAK_MARK . ' connection failed', 3);
        $this->warnBin = $this->writeScript('dump_warn.sh', "select 'fake dump';", self::LEAK_MARK . ' terminated at end of copy', 0);

        $this->setEnv('PG_DUMP_BIN', $this->okBin);
    }

    protected function tearDown(): void
    {
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

        // 断言中途失败时也要收尾：本次测试期间新增的转储/探针文件一律清掉，
        // 开测前就存在的（真备份）一个都不碰。
        foreach (glob(base_path(self::DIR) . '/*.sql') ?: [] as $f) {
            if (in_array($f, $this->dirBefore, true)) {
                continue;
            }
            $b = basename($f);
            if (str_starts_with($b, 'zz-backup-artifact-') || preg_match('#^kode_backup_\d{8}_\d{6}_[0-9a-f]{8}\.sql$#', $b)) {
                @unlink($f);
            }
        }
        $this->dirBefore = [];

        // 既有备份先改名挪出 clean 的管辖范围，收尾原样放回
        foreach ($this->held as [$now, $was]) {
            @rename($now, $was);
        }
        $this->held = [];

        foreach (glob($this->tmp . '/*') ?: [] as $left) {
            @unlink($left);
        }
        @rmdir($this->tmp);

        HttpRequest::clear();

        parent::tearDown();
    }

    // ─── DbBackup：落盘目录 / 权限 / 同秒唯一 / 失败口径 ─────

    public function test_the_dump_lands_in_the_directory_it_was_asked_for(): void
    {
        $dir = $this->tmp . '/picked';
        $file = DbBackup::run(['kode_users'], $dir);
        $this->trash[] = $file;

        self::assertSame(realpath($dir), dirname((string) realpath($file)), '产物必须落在指定目录，否则列表/下载/清理都找不到它');
        self::assertFileExists($file);
        self::assertStringContainsString('fake dump', (string) file_get_contents($file));
    }

    public function test_the_dump_name_carries_a_uniquifier_so_a_second_run_cannot_clobber_the_first(): void
    {
        $dir = $this->tmp . '/names';
        $first = DbBackup::run(['kode_users'], $dir);
        $second = DbBackup::run(['kode_users'], $dir);
        $this->trash[] = $first;
        $this->trash[] = $second;

        self::assertMatchesRegularExpression('#^kode_backup_\d{8}_\d{6}_[0-9a-f]{8}\.sql$#', basename($first));
        self::assertNotSame($first, $second, '命名到秒的话，同秒第二份会顶掉第一份');
        self::assertFileExists($first);
        self::assertFileExists($second);
    }

    public function test_the_dump_is_not_group_or_world_readable(): void
    {
        $dir = $this->tmp . '/modes';
        $file = DbBackup::run(['kode_users'], $dir);
        $this->trash[] = $file;

        clearstatcache(true, $file);
        self::assertSame(0o600, fileperms($file) & 0o777, '转储含密码哈希，同机任何账号都不该读得到');
    }

    public function test_an_unusable_target_directory_fails_instead_of_writing_elsewhere(): void
    {
        $blocker = $this->tmp . '/a-file';
        file_put_contents($blocker, 'x');
        $this->trash[] = $blocker;

        try {
            DbBackup::run(['kode_users'], $blocker . '/nested');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('备份目录不可用', $e->getMessage());

            return;
        }
        self::fail('目录建不出来时必须抛错，不能悄悄改投别处');
    }

    public function test_a_failed_dump_reports_the_real_reason_and_leaves_nothing_behind(): void
    {
        $dir = $this->tmp . '/failed';
        $this->setEnv('PG_DUMP_BIN', $this->badBin);

        try {
            DbBackup::run(['kode_users'], $dir);
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(self::LEAK_MARK, $e->getMessage(), '失败原因得能进日志，别只剩一句通用文案');
            self::assertSame([], glob($dir . '/*'), '半截转储不能留在盘上');

            return;
        }
        self::fail('dump 失败必须抛错');
    }

    public function test_stderr_never_ends_up_inside_the_sql_body(): void
    {
        // 旧写法 `> file 2>&1`：pg_dump 的告警混进转储正文，恢复时喂给 psql 就是语法错误
        $dir = $this->tmp . '/clean-body';
        $this->setEnv('PG_DUMP_BIN', $this->warnBin);
        $file = DbBackup::run(['kode_users'], $dir);
        $this->trash[] = $file;

        $body = (string) file_get_contents($file);
        self::assertStringContainsString('fake dump', $body);
        self::assertStringNotContainsString(self::LEAK_MARK, $body);
    }

    // ─── BackupController：目录归辖 / 危险参数 / 不外泄 ───────

    public function test_a_manual_backup_lands_where_the_list_and_download_can_find_it(): void
    {
        $run = $this->call('run', ['tables' => ['kode_users']]);
        self::assertSame(200, $run['status'], $run['msg']);
        $name = (string) ($run['data']['file'] ?? '');
        self::assertNotSame('', $name);
        $abs = base_path(self::DIR) . '/' . $name;
        $this->trash[] = $abs;

        $real = realpath($abs);
        self::assertIsString($real, '备份写进了列表看不见的地方，等于没备份');
        self::assertSame(realpath(base_path(self::DIR)), dirname($real));

        $index = $this->call('index');
        self::assertSame(200, $index['status']);
        $names = array_column((array) ($index['data']['files'] ?? []), 'name');
        self::assertContains($name, $names);

        clearstatcache(true, $real);
        self::assertSame(0o600, fileperms($real) & 0o777, '0600 要在整条链路上都成立');
    }

    public function test_clean_refuses_out_of_range_days_instead_of_clamping_it(): void
    {
        $dir = $this->ensureDir();
        $this->holdAside($dir);
        // 一份「很久以前」的备份：days=0 若被夹成 1，它当场就没了
        $old = $dir . '/zz-backup-artifact-old.sql';
        file_put_contents($old, "-- probe\n");
        $this->trash[] = $old;
        touch($old, time() - 400 * 86400);

        foreach ([0, -1, 4000] as $days) {
            $res = $this->call('clean', ['days' => $days, 'confirm' => 'I_UNDERSTAND_THE_RISK']);
            self::assertSame(400, $res['status'], "days={$days} 不能放行");
            self::assertStringContainsString('1..3650', $res['msg']);
            self::assertFileExists($old, "days={$days} 被夹取后删掉了备份");
            self::assertSame([], (array) ($res['data']['deleted'] ?? []), '拒掉的请求一个文件都不许动');
        }

        // 漏传 days 同样不许当 0 用
        $thrown = false;
        try {
            $this->call('clean', ['confirm' => 'I_UNDERSTAND_THE_RISK']);
        } catch (ValidationException) {
            $thrown = true;
        }
        self::assertTrue($thrown, '缺 days 必须走校验失败，而不是回落到某个默认值');
        self::assertFileExists($old);
    }

    public function test_clean_deletes_only_beyond_the_window_and_still_needs_confirm_below_90_days(): void
    {
        $dir = $this->ensureDir();
        $this->holdAside($dir);
        $recent = $dir . '/zz-backup-artifact-recent.sql';
        $ancient = $dir . '/zz-backup-artifact-ancient.sql';
        file_put_contents($recent, "-- probe\n");
        file_put_contents($ancient, "-- probe\n");
        touch($recent, time() - 10 * 86400);
        touch($ancient, time() - 200 * 86400);

        $noConfirm = $this->call('clean', ['days' => 30]);
        self::assertSame(400, $noConfirm['status']);
        self::assertFileExists($recent);
        self::assertFileExists($ancient, '未确认时一个都不许删');

        $res = $this->call('clean', ['days' => 90]);
        self::assertSame(200, $res['status'], $res['msg']);
        self::assertContains(basename($ancient), (array) ($res['data']['deleted'] ?? []));
        self::assertFileDoesNotExist($ancient, '超出窗口的该删');
        self::assertFileExists($recent, '窗口内的一个都不许动');
    }

    public function test_a_failed_backup_keeps_the_diagnostics_out_of_the_response(): void
    {
        $this->setEnv('PG_DUMP_BIN', $this->badBin);

        $res = $this->call('run', ['tables' => ['kode_users']]);

        self::assertSame(500, $res['status']);
        self::assertStringNotContainsString(self::LEAK_MARK, $res['msg']);
        self::assertStringNotContainsString($this->tmp, $res['msg'], '绝对路径不外泄');
        self::assertArrayNotHasKey('file', $res['data']);
    }

    public function test_the_table_whitelist_still_answers_with_a_usable_400(): void
    {
        $res = $this->call('run', ['tables' => ['pg_shadow']]);

        self::assertSame(400, $res['status']);
        self::assertStringContainsString('pg_shadow', $res['msg'], '白名单拒了哪张表要说清楚（入参是用户自己给的）');
    }

    /**
     * 空/畸形白名单既不进 DbBackup，也不许在控制器里被「归一」成全库。
     *
     * 旧写法是 `if (is_array($tables) && $tables !== [])` —— 三种形状都会静默落回
     * `$tableList = null`（全库）或 `[]`（只出文件头的产物）：
     *  - `[]` / `[""]` / `[" ", ""]`：想备一张都没有，实际把**整座库**备了（范围被放大）；
     *  - `"kode_users"`（写成字符串）：`is_array` 不成立，同样静默变全库；
     *  - `[]` 归一后为空、`[""]` 归一后为空：进 `DbBackup::run([])` 出假产物。
     * 省略 `tables` 才是全库的口径，这两件事不能靠猜。
     */
    public function test_a_blank_or_malformed_table_list_is_refused_instead_of_widening_to_everything(): void
    {
        $before = glob($this->ensureDir() . '/*.sql') ?: [];

        foreach ([['tables' => []], ['tables' => ['']], ['tables' => ['', ' ']], ['tables' => 'kode_users']] as $body) {
            $res = $this->call('run', $body);
            self::assertSame(400, $res['status'], json_encode($body) . ' 不能放行：' . $res['msg']);
            self::assertArrayNotHasKey('file', $res['data'], json_encode($body) . ' 不许回一个产物文件名');
        }

        self::assertSame($before, glob($this->ensureDir() . '/*.sql') ?: [],
            '被拒的备份一个文件都不该落在 storage/backups 里（那里是列表/下载/清理的管辖面）');
    }

    /**
     * 第二条派发口（`GET /api/system/backup`，下载即删）不能吃空清单。
     *
     * 它收的是查询串 `tables=a,b`，`,,` 归一后是空数组 —— 与 JSON 的 `[]` 是同一种入参，
     * 只是这里的产物直接进响应体：旧写法会把一份除注释头什么都不含的 .sql 当「备份」
     * 发给浏览器，HTTP 200、Content-Length 一百多字节，看起来完全正常。
     * 判定与上一条共用 DbBackup 那一处（调用方各写一份空判 = 迟早只改对一个）。
     */
    public function test_the_download_endpoint_refuses_a_blank_table_list_too(): void
    {
        HttpRequest::setRequest(
            (new ServerRequest('GET', '/api/system/backup'))->withQueryParams(['tables' => ',,'])
        );

        $decoded = (array) json_decode((string) (new SystemController())->backup()->getBody(), true);

        self::assertSame(400, (int) ($decoded['code'] ?? 0),
            '空清单不能回 200 + 一份假产物：' . json_encode($decoded, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('全库', (string) ($decoded['msg'] ?? ''));
    }

    // ─── 私有辅助 ───────────────────────────────────────────

    private function ensureDir(): string
    {
        $dir = base_path(self::DIR);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * 把目录里既有的备份改名挪走：clean 是「按 mtime 批量删文件」的接口，
     * 测试不许碰任何真实备份，只许碰自己造的那两份。
     */
    private function holdAside(string $dir): void
    {
        foreach (glob($dir . '/*.sql') ?: [] as $f) {
            $now = $f . '.held';
            if (@rename($f, $now)) {
                $this->held[] = [$now, $f];
            }
        }
    }

    /** 造一个假 pg_dump：忽略所有参数，按剧本往 stdout/stderr 吐字并按码退出。 */
    private function writeScript(string $name, string $out, string $err, int $code): string
    {
        $body = "#!/bin/sh\n";
        if ($out !== '') {
            $body .= "echo '" . $out . "'\n";
        }
        if ($err !== '') {
            $body .= "echo '" . $err . "' >&2\n";
        }
        $body .= 'exit ' . $code . "\n";

        $path = $this->tmp . '/' . $name;
        file_put_contents($path, $body);
        chmod($path, 0o755);

        return $path;
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

    /**
     * @param array<string, mixed> $body
     *
     * @return array{status: int, msg: string, data: array<string, mixed>}
     */
    private function call(string $action, array $body = []): array
    {
        HttpRequest::setRequest(new ServerRequest(
            'POST',
            '/api/system/backups/' . $action,
            [],
            ['content-type' => 'application/json'],
            (string) json_encode($body)
        ));

        $response = (new BackupController())->{$action}();
        $decoded = (array) json_decode((string) $response->getBody(), true);

        return [
            'status' => (int) ($decoded['code'] ?? $response->getStatusCode()),
            'msg' => (string) ($decoded['msg'] ?? ''),
            'data' => (array) ($decoded['data'] ?? []),
        ];
    }
}
