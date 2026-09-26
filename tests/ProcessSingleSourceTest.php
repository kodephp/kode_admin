<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Process\ProcessManager;
use Kode\Framework\Process\Worker;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use app\tests\Support\SourceScan;

/**
 * 「常驻进程在不在跑」这个判定全站只许有一份实现，且那份必须是框架的槽位表。
 *
 * 上一轮把 `/api/process/*` 收进了 `ProcessManager::slotStates()`
 * （见 ProcessControlPanelTest），但同一个幻影路径 `/tmp/kode-daemon.pid` 在
 * `SystemController` 里还躺着两份：
 *  - `health()` 用 `posix_kill($pid, 0)` 判存活，回一个恒为 `not_running` 的 `process.status`
 *    （那个名字从来没有守护进程写过 —— 它按槽位写 `kode-worker-<name>:<slot>.pid`）。
 *    同一次调用还从 `/proc/{pid}/stat` 里正则抓**第一个**数字当 `uptime_ticks`：
 *    那份文件形如 `83861 (php) S 1 83861 …`，pid 之后第一个纯数字字段是**父进程号**，
 *    于是这个指标从来不是运行时长，而是不知所云的 1。
 *  - `repair()`「一键修复」的第一项就是 `@unlink()` 那个 pid 文件。清理责任属于写侧
 *    （Daemon 优雅退出时自己删），读者删文件会把一次「正在启动、还没落 pid」的正常拉起
 *    抹成没发生过；而 `kode/process` 的 Daemon **默认** pid 文件名恰好就是 `kode-daemon.pid`，
 *    所以这一刀切下去可能落在别人的进程上。
 *
 * 本文件两头都钉：行为上 health/repair 必须与槽位表同源且不碰别人的文件；
 * 静态上 app/ 与 plugins/ 里不许再出现第二份 pid 路径、`posix_kill(`、`/proc/{pid}` 算式。
 */
final class ProcessSingleSourceTest extends TestCase
{
    protected bool $independentApp = true;

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        $this->createdFiles = [];

        HttpRequest::clear();

        parent::tearDown();
    }

    // ─── 行为：health() 与槽位表同源 ─────────────────────────

    public function test_health_reports_a_live_slot_as_running(): void
    {
        $manager = $this->manager();
        $manager->register(new ShadowWorker('zz-proc'));
        $this->seedPid($this->pidFileOf($manager, 'zz-proc:0'), getmypid());

        $process = (array) ($this->callHealth()['process'] ?? []);

        self::assertSame('running', $process['status'] ?? null,
            '槽位在跑而健康卡说没在跑：' . json_encode($process));
        self::assertSame(getmypid(), (int) ($process['alive']['zz-proc:0'] ?? 0),
            '健康卡报的 pid 不是槽位表里那个：' . json_encode($process));
    }

    /**
     * 失效的 pid 文件要**点名**，不是删掉，也不是当成「一切正常」。
     *
     * 「有文件、进程不在」是守护进程被 SIGKILL 之类留下的现场，运维需要看得见；
     * 而 `absent`（没有文件）和 `stale`（有文件但死了）混成一个 `not_running`
     * 就等于把「没启动过」和「启动过又没了」说成同一件事。
     */
    public function test_health_names_a_dead_pid_file_as_stale_and_leaves_it_alone(): void
    {
        $manager = $this->manager();
        $manager->register(new ShadowWorker('zz-stale-health'));
        // 另一路「从没启动过」：没有 pid 文件 ≠ 有个失效的 pid 文件，两者不能混成一个词
        $manager->register(new ShadowWorker('zz-never-started'));

        $file = $this->pidFileOf($manager, 'zz-stale-health:0');
        $this->seedPid($file, $this->deadPid());

        $process = (array) ($this->callHealth()['process'] ?? []);

        self::assertContains('zz-stale-health:0', (array) ($process['stale'] ?? []),
            '失效 pid 文件没被点名：' . json_encode($process));
        self::assertNotContains('zz-never-started:0', (array) ($process['stale'] ?? []),
            '没启动过的槽位被说成「有个失效 pid 文件」：' . json_encode($process));
        self::assertSame([], (array) ($process['alive'] ?? []), '已退出的进程被判成存活');
        self::assertFileExists($file, '健康检查把不属于它的 pid 文件删掉了');
    }

    /**
     * 「一键修复」不许再删 pid 文件。
     *
     * 两条腿缺一不可。行为那条现在**咬不动**旧代码：`repair()` 判的是幻影路径
     * `/tmp/kode-daemon.pid`，这台机器上没人写它，于是它「什么都没删」地通过 ——
     * 正是「用例看着像绿的、其实前提没建立」的形状。所以补一条源码级断言：
     * repair() 的方法体里根本不许出现 `unlink(`。修复项的口径是「无副作用、可逆、幂等」，
     * 而删别人正在维护的文件三条都不满足（kode/process 的 Daemon **默认** pid 文件名
     * 恰好就是 kode-daemon.pid，这一刀可能落在别人的进程上）。
     *
     * 同时这一条也是「别把功能顺手删空」的对照：热缓存清理仍在做，executed 不能变空数组。
     */
    public function test_repair_does_not_delete_pid_files(): void
    {
        $bodies = SourceScan::methodBodies(
            (string) file_get_contents(dirname(__DIR__) . '/app/admin/controllers/SystemController.php')
        );
        self::assertArrayHasKey('repair', $bodies, '取不到 repair() 的方法体（切片器或方法名变了）');
        self::assertStringNotContainsString('unlink(', $bodies['repair'],
            'repair() 里有删文件的动作：pid 文件的清理责任属于守护进程自己');

        $manager = $this->manager();
        $manager->register(new ShadowWorker('zz-repair'));
        $file = $this->pidFileOf($manager, 'zz-repair:0');
        $this->seedPid($file, $this->deadPid());

        $data = (array) ($this->callSystem('repair')['data'] ?? []);

        self::assertFileExists($file, '一键修复删掉了守护进程的 pid 文件');
        foreach ((array) ($data['executed'] ?? []) as $line) {
            self::assertStringNotContainsString('PID', (string) $line,
                '一键修复把「删 pid 文件」当成自愈动作：' . json_encode($data['executed'] ?? []));
        }
        self::assertNotEmpty($data['executed'] ?? [],
            '修复项被删空了（热缓存清理仍应执行）：' . json_encode($data));
    }

    // ─── 静态：不得有第二份判据 ──────────────────────────────

    /** 正对照：门禁必须认得被它替换掉的三种写法。 */
    public function test_the_gate_names_the_writings_it_replaced(): void
    {
        $phantomPath = <<<'PHP'
        <?php
        $pidFile = '/tmp/kode-daemon.pid';
        PHP;
        $rawSignal = <<<'PHP'
        <?php
        $ok = @posix_kill($pid, 15);
        PHP;
        $procMath = <<<'PHP'
        <?php
        $stat = @file_get_contents("/proc/{$pid}/stat");
        PHP;

        self::assertNotSame([], $this->violations($phantomPath), '幻影 pid 路径必须被点名');
        self::assertNotSame([], $this->violations($rawSignal), '手写 posix_kill 必须被点名');
        self::assertNotSame([], $this->violations($procMath), '/proc/{pid} 算式必须被点名');

        // 反向：合法形状不得误伤（cpuinfo/meminfo 是系统探针，与进程存活无关）。
        self::assertSame([], $this->violations(
            "<?php\nif (is_file('/proc/cpuinfo')) { \$n = 1; }\n"
        ), '系统探针的 /proc 读取被误判了');

        // 反向：本文件自己的正对照必须留在射程内（正则改成「只认单引号」就会漏掉上面那条）。
        self::assertNotSame([], $this->violations(
            "<?php\n\$stat = file_get_contents('/proc/1/stat');\n"
        ), '字符串形式的 /proc/{pid} 也必须被点名');
    }

    public function test_no_second_process_liveness_source_anywhere_in_app_code(): void
    {
        $files = $this->scanSurface();
        $inApp = array_filter($files, static fn (string $f): bool => str_contains($f, '/app/'));
        $inPlugins = array_filter($files, static fn (string $f): bool => str_contains($f, '/plugins/'));

        self::assertGreaterThan(100, count($files), '扫描面异常小（目录取错就变成「什么都没看见」的绿）：' . count($files));
        self::assertNotEmpty($inApp, '扫描面里没有 app/ 的文件');
        self::assertNotEmpty($inPlugins, '扫描面里没有 plugins/ 的文件');

        $hits = [];
        $withSlotStates = 0;
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            foreach ($this->violations($src) as $line) {
                $hits[] = str_replace(dirname(__DIR__) . '/', '', $file) . ' → ' . $line;
            }
            if (str_contains($src, 'slotStates(') || str_contains($src, 'signalSlots(')) {
                $withSlotStates++;
            }
        }

        self::assertGreaterThan(0, $withSlotStates,
            '扫描面里没有任何一处走框架的槽位表 —— 说明真正的判据已经不在射程内');

        self::assertSame([], $hits, '出现了第二份「进程在不在跑」的判据：' . implode(' | ', $hits));
    }

    // ─── 私有 ────────────────────────────────────────────────

    /**
     * 一份源码里出现的「第二份进程存活判据」。
     *
     * 注释先剥掉（`SourceScan::stripComments`）：AGENTS.md 与本文件的 docblock 都要写清
     * 「被替换掉的旧写法长什么样」，不剥注释就会逼人删掉解释才能让门禁变绿。
     *
     * @return list<string>
     */
    private function violations(string $src): array
    {
        $code = SourceScan::stripComments($src);
        $hits = [];

        // 1. 自己拼 worker 的 pid 文件路径 —— 唯一来源必须是 residentSlots()/slotStates()。
        if (preg_match('/kode-(?:daemon|worker)[^\'"]*\.pid/', $code, $m) === 1) {
            $hits[] = '手写 pid 文件路径 ' . $m[0];
        }

        // 2. 直接对 pid 发信号 —— 目标集合必须由 signalSlots() 给出（它保证与状态表同源）。
        if (str_contains($code, 'posix_kill(')) {
            $hits[] = '手写 posix_kill 调用';
        }

        // 3. 按 /proc/{pid} 算运行时长 —— darwin 没有 /proc，Linux 上那个字段是宿主机开机时刻。
        //    /proc 下的**系统探针**文件（cpuinfo/meminfo…）与此无关，白名单放行。
        if (preg_match_all('#/proc/(?!cpuinfo\b|meminfo\b|uptime\b|loadavg\b|version\b)\S#', $code, $mm) >= 1) {
            $hits[] = '/proc/{pid} 读取 ' . $mm[0][0];
        }

        return $hits;
    }

    /** @return list<string> app/ 与 plugins/ 下全部 PHP 文件 */
    private function scanSurface(): array
    {
        $root = dirname(__DIR__);
        $files = [];

        foreach (['/app', '/plugins'] as $dir) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function manager(): ProcessManager
    {
        $manager = resolve(ProcessManager::class);
        self::assertInstanceOf(ProcessManager::class, $manager);

        return $manager;
    }

    /** 路径只从框架问，测试里不另拼一份（拼一份就是第二份判据）。 */
    private function pidFileOf(ProcessManager $manager, string $label): string
    {
        foreach ($manager->residentSlots() as $row) {
            if ($row['name'] . ':' . $row['slot'] === $label) {
                return $row['pid_file'];
            }
        }

        self::fail('框架的槽位清单里没有 ' . $label);
    }

    private function seedPid(string $file, int $pid): void
    {
        $this->createdFiles[] = $file;
        file_put_contents($file, (string) $pid);
    }

    /** 一个立刻退出且已被回收的子进程 → 它的 pid 必然不存活（不留僵尸，否则信号 0 照样成功）。 */
    private function deadPid(): int
    {
        $proc = proc_open('/bin/echo zz-dead', ['1' => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($proc);
        $pid = (int) proc_get_status($proc)['pid'];
        proc_close($proc);

        return $pid;
    }

    /** @return array<string, mixed> health() 的 data */
    private function callHealth(): array
    {
        $body = $this->callSystem('health');
        self::assertSame(200, (int) ($body['code'] ?? 0), 'health 请求失败：' . json_encode($body));

        return (array) ($body['data'] ?? []);
    }

    /** @return array<string, mixed> */
    private function callSystem(string $action): array
    {
        $request = (new ServerRequest('GET', '/api/system/' . $action))
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => 'admin', 'roles' => ['admin']]);
        HttpRequest::setRequest($request);

        $controller = new \app\admin\controllers\SystemController();
        $response = $controller->{$action}();

        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}

/**
 * 健康卡用例的探针 worker：只声明身份，不执行任何业务。
 */
final class ShadowWorker extends Worker
{
    public function __construct(private readonly string $shadowName)
    {
    }

    public function name(): string
    {
        return $this->shadowName;
    }

    public function handle(): void
    {
    }
}
