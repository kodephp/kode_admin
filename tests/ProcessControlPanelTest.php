<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Process\ProcessManager;
use Kode\Framework\Process\Worker;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 进程控制面板（`/api/process/*`）的口径。
 *
 * 修之前它把「谁在跑」这个问题答错了三次，而且三次都朝同一个方向：说没事。
 * 判据指向 `/tmp/kode-daemon.pid` —— `kode process:start` 从来不通这个名写文件
 * （它按槽位写 `sys_get_temp_dir()/kode-worker-<name>:<slot>.pid`），于是：
 *  1. `running` 恒为 false，面板永远显示「未运行」；
 *  2. 「启动」的防重入守卫因此永久放行，每点一次真的叠加一整套守护进程；
 *  3. 「停止/重载」对着不存在的文件，永远失败；而 reload 发的是 SIGHUP ——
 *     Daemon 没装 HUP 处理器，那是按默认处置**直接终止**，不是重载。
 * 另外它自己轮询 pid 文件 5 秒（每次 usleep 200ms）才回「启动超时」，
 * 既违反 AGENTS.md §5 的热路径红线，报的还是假因（进程其实起来了）。
 *
 * 现在全部走框架 v1.12.0 的 `residentSlots()/slotStates()/signalSlots()`：
 * 面板显示的路 = 实际 fork 的路，信号发给谁由返回值说，不再靠猜路径。
 *
 * 本文件不碰真数据库（进程状态与库无关），但会注册一个 `zz-` 前缀的探针 worker
 * 并在临时目录建它自己的 pid 文件，收尾逐个删掉。
 */
final class ProcessControlPanelTest extends TestCase
{
    protected bool $independentApp = true;

    /** @var list<string> 本用例建出来的 pid 文件，tearDown 里删 */
    private array $createdFiles = [];

    /** @var array<string, array{env: string|null, get: string|false}> 动过的 env，tearDown 里还原 */
    private array $envBackup = [];

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

        foreach ($this->envBackup as $key => $was) {
            if ($was['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $was['env'];
            }

            if ($was['get'] === false) {
                putenv($key); // 不带 = 删除
            } else {
                putenv($key . '=' . $was['get']);
            }
        }
        $this->envBackup = [];

        HttpRequest::clear();

        parent::tearDown();
    }

    // ─── 状态读取 ────────────────────────────────────────────

    /**
     * 面板必须逐槽位列出行，而不是一句「未运行」。
     *
     * 旧实现连「有几路」都答不出（只有一个假的 PID_FILE 常量）；两路 worker 挂了
     * 一路时，回「未运行」和回「一切正常」在页面上是一样的。
     */
    public function test_status_lists_every_resident_slot_separately(): void
    {
        $manager = $this->manager();
        $manager->register(new ProbeWorker('zz-two', probeInstances: 2));

        $body = $this->callStatus();
        $rows = (array) ($body['data']['slots'] ?? []);

        $labels = array_map(
            static fn (array $r): string => $r['name'] . ':' . $r['slot'],
            $rows
        );
        self::assertContains('zz-two:0', $labels, '槽位清单里没有 zz-two:0：' . json_encode($labels));
        self::assertContains('zz-two:1', $labels, '两实例的 worker 只列了一路：' . json_encode($labels));

        foreach ($rows as $row) {
            self::assertArrayHasKey('pid', $row, '槽位行没有 pid 键');
            self::assertArrayHasKey('alive', $row, '槽位行没有 alive 键');
        }
    }

    /**
     * 一路在跑时，`running` 必须为真，且 uptime 是**这一路**的时长。
     *
     * 这条是钉住那个假路径的正面判据：旧写法对着不存在的文件，这里恒回 false。
     * uptime 的断言挡住另一支：按 `/proc/{pid}/stat` 第 22 字段算启动时刻，
     * 在 darwin 上根本读不到（面板显示 —），在 Linux 上算出来的是**宿主机开机时长**，
     * 于是「这个 worker 跑了 300 天」其实是「这台机器开了 300 天」。
     */
    public function test_status_reports_a_live_slot_as_live_with_its_own_uptime(): void
    {
        $manager = $this->manager();
        $manager->register(new ProbeWorker('zz-live'));

        $file = $this->pidFileOf($manager, 'zz-live:0');
        $this->seedPid($file, getmypid());

        $data = (array) ($this->callStatus()['data'] ?? []);

        self::assertTrue((bool) ($data['running'] ?? false), '自己刚落的 pid 被判成未运行');
        self::assertSame(1, (int) ($data['alive_count'] ?? 0), '存活路数不对：' . json_encode($data['slots'] ?? []));

        $row = $this->slotRow($data, 'zz-live:0');
        self::assertSame(getmypid(), (int) $row['pid'], '面板报的 pid 不是刚写进去的那个');
        self::assertLessThan(60, (int) $row['uptime'], 'uptime 不是这一路的时长（读到宿主开机时长的话它是个巨数）');
        self::assertStringStartsWith(date('Y-m-d'), (string) $row['start_time']);
    }

    /**
     * pid 文件失效时不得谎报存活，也**不得删掉别人的文件**。
     *
     * 清理责任属于写侧（Daemon 优雅退出时自己 unlink）。读者顺手删会把一次
     * 「正在启动、还没落 pid」的正常拉起抹成没发生过 —— 而运维正在盯着它。
     */
    public function test_status_marks_a_dead_pid_dead_and_leaves_the_file_alone(): void
    {
        $manager = $this->manager();
        $manager->register(new ProbeWorker('zz-stale'));

        $file = $this->pidFileOf($manager, 'zz-stale:0');
        $this->seedPid($file, $this->deadPid());

        $data = (array) ($this->callStatus()['data'] ?? []);
        $row = $this->slotRow($data, 'zz-stale:0');

        self::assertFalse((bool) $row['alive'], '一个已退出的 pid 被判成存活');
        self::assertFileExists($file, '状态读取把不属于它的 pid 文件删掉了');
    }

    // ─── 发信号 ──────────────────────────────────────────────

    /**
     * 「停止」必须点名它发给了哪几路。
     *
     * 旧实现只对着一个假 pid 发 `posix_kill($pid, 15)`，回一句 `stopped:true`。
     * 现在：发出去的、跳过的、失败的是三份清单，标识和状态表同源。
     */
    public function test_stop_names_the_slots_it_actually_signalled(): void
    {
        $manager = $this->manager();
        $manager->register(new ProbeWorker('zz-stop'));

        $proc = $this->spawnSleeper();
        $file = $this->pidFileOf($manager, 'zz-stop:0');
        $this->seedPid($file, (int) $proc['pid']);
        $this->requireNoOtherLiveSlot('zz-stop:0');

        $data = (array) ($this->call('POST', '/api/process/stop', 'stop')['data'] ?? []);

        self::assertSame(['zz-stop:0'], array_values((array) ($data['signalled'] ?? [])),
            '停止没有点名它发过信号的那一路：' . json_encode($data));
        self::assertSame('SIGTERM', $data['signal'] ?? null);
        self::assertSame([], (array) ($data['failed'] ?? []), '不该有失败项：' . json_encode($data['failed'] ?? []));
    }

    /**
     * 没有任何一路在跑时，「停止」不能回成功。
     *
     * 反向也要挡：旧实现在这里回 400，但**理由**是假的（说「未运行」其实是它的
     * 判据指向了不存在的文件）。所以这条同时断言原因里点名了具体槽位。
     */
    public function test_stop_refuses_and_explains_when_nothing_is_running(): void
    {
        $manager = $this->manager();
        $manager->register(new ProbeWorker('zz-absent'));

        $body = $this->call('POST', '/api/process/stop', 'stop');

        self::assertSame(400, (int) ($body['code'] ?? 0), '没在跑却回了成功：' . json_encode($body));
        self::assertStringContainsString('zz-absent', (string) ($body['msg'] ?? ''),
            '失败原因没点名是哪一路不在运行，运维无法和状态表对上');
    }

    /**
     * 「重载」发的是 SIGUSR1。
     *
     * 这是本轮最贵的四条里唯一一条「看起来在工作」的：`posix_kill($pid, 1)` 是 SIGHUP，
     * Daemon 没装处理器 → 系统默认处置 = **终止守护进程**。点完「重载」面板一切如常
     * （因为进程已经没了），再点「停止」又因为进程不在而回「已停止」。
     */
    public function test_reload_sends_usr1_not_sighup(): void
    {
        $manager = $this->manager();
        $manager->register(new ProbeWorker('zz-reload'));

        $proc = $this->spawnSleeper();
        $this->seedPid($this->pidFileOf($manager, 'zz-reload:0'), (int) $proc['pid']);
        $this->requireNoOtherLiveSlot('zz-reload:0');

        $data = (array) ($this->call('POST', '/api/process/reload', 'reload')['data'] ?? []);

        self::assertSame('SIGUSR1', $data['signal'] ?? null,
            '重载发错了信号：SIGHUP 会直接杀掉守护进程，不是重载');
        self::assertSame(['zz-reload:0'], array_values((array) ($data['signalled'] ?? [])));
    }

    // ─── 启动 ────────────────────────────────────────────────

    /**
     * 有槽位在跑时「启动」必须被挡住 —— 这条守卫修之前是永久放行的。
     *
     * 每点一次就 `exec(php kode process:start &)` 叠加一整套守护进程，
     * 而面板照旧显示「未运行」，所以没人知道机器上躺了几代 worker。
     */
    public function test_start_refuses_when_a_slot_is_already_running(): void
    {
        $manager = $this->manager();
        $manager->register(new ProbeWorker('zz-busy'));

        $this->seedPid($this->pidFileOf($manager, 'zz-busy:0'), getmypid());
        $this->neutralizeSpawn();

        $body = $this->call('POST', '/api/process/start', 'start');

        self::assertSame(400, (int) ($body['code'] ?? 0), '已在运行却照样派发启动：' . json_encode($body));
        self::assertStringContainsString('zz-busy:0', (string) ($body['msg'] ?? ''),
            '拒绝理由没点名是哪一路在跑');
    }

    /**
     * 派发启动是「发出去」，不是「等它证明自己活着」。
     *
     * 旧实现在请求里 `usleep(200_000)` 轮询那个永远不会出现的文件，最长 5 秒
     * （AGENTS.md §5：控制器禁止文件 I/O、禁止 sleep），然后回「启动超时：未检测到
     * PID 文件」—— 而守护进程其实好好地在跑。现在恒先回「已派发」，状态去 status 查。
     *
     * 用 `PHP_BINARY=/bin/echo` 把子进程换成一个立刻退出的命令，
     * 这样这一条只验控制器的行为，不会在开发机上真起一套常驻进程。
     */
    public function test_start_dispatches_without_polling_for_a_pid_file(): void
    {
        $manager = $this->manager();
        $manager->register(new ProbeWorker('zz-start'));

        $this->neutralizeSpawn();
        $log = storage_path('logs/daemon.log');
        $created = !is_file($log);

        $body = $this->call('POST', '/api/process/start', 'start');

        if ($created) {
            $this->createdFiles[] = $log;
        }

        self::assertSame(200, (int) ($body['code'] ?? 0), '派发启动失败：' . json_encode($body));
        $data = (array) ($body['data'] ?? []);
        self::assertTrue((bool) ($data['dispatched'] ?? false), '响应没有承认「已派发」：' . json_encode($data));
        self::assertArrayNotHasKey('error', $data, '等不到 pid 文件就被当成失败（那是第二次判据）：' . json_encode($data));
    }

    // ─── worker 清单 ─────────────────────────────────────────

    /**
     * worker 清单只能有一份来源：注册表本身。
     *
     * 旧写法在控制器里把 `config('process.workers')` 又解析一遍，于是声明在类上的
     * `interval()`/`instances()` 在面板上全是空的（配置没写声明键 = null），
     * 而且和实际生效的那份（ConfiguredWorker 覆写后）根本不是一回事。
     */
    public function test_list_reports_the_effective_declarations_not_a_second_config_parse(): void
    {
        $manager = $this->manager();
        $manager->register(new ProbeWorker('zz-list', probeInstances: 3, probeInterval: 0.5));

        $data = (array) ($this->call('GET', '/api/process/list', 'list')['data'] ?? []);

        $row = null;
        foreach ((array) ($data['workers'] ?? []) as $w) {
            if ((string) ($w['name'] ?? '') === 'zz-list') {
                $row = (array) $w;
            }
        }
        self::assertNotNull($row, '清单里没有 zz-list：' . json_encode(array_column((array) ($data['workers'] ?? []), 'name')));
        self::assertSame(3, (int) $row['instances'], 'instances 没有回类上声明的值（面板那一列是空的）');
        self::assertSame(0.5, (float) $row['interval']);
    }

    // ─── 私有 ────────────────────────────────────────────────

    private function manager(): ProcessManager
    {
        $manager = resolve(ProcessManager::class);
        self::assertInstanceOf(ProcessManager::class, $manager);

        return $manager;
    }

    /** 取某个已注册槽位的 pid 文件路径 —— 路径只从框架问，不在测试里另拼一份。 */
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

    /**
     * 发信号前的爆炸半径预检：这台机器上除了本用例自己种的那一路，不许有别的槽位活着。
     *
     * `stop`/`reload` 是对**全部**存活槽位发信号的派发口，用例跑到那一行就真的发出去了，
     * 断言再红也撤不回来 —— 开发机上正在跑的 worker 会被这一轮测试停掉。宁可跳过并说明原因。
     */
    private function requireNoOtherLiveSlot(string $mine): void
    {
        $others = [];
        foreach ($this->manager()->slotStates() as $row) {
            $label = $row['name'] . ':' . $row['slot'];
            if ($row['alive'] && $label !== $mine) {
                $others[] = $label;
            }
        }

        if ($others !== []) {
            self::markTestSkipped('这台机器上还有别的常驻槽位在跑（' . implode('、', $others)
                . '），发信号会停掉真进程；请先停掉它们再跑本文件');
        }
    }

    /**
     * 起一个真的会立刻消失的子进程拿它的 pid。
     *
     * 用 /bin/echo 而不是 /bin/true：macOS 不发行 /bin/true。
     * proc_close 必须调，留着僵尸的话信号 0 照样成功，「已死」就判不出来。
     */
    private function deadPid(): int
    {
        $proc = proc_open('/bin/echo zz-dead', ['1' => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($proc);
        $pid = (int) proc_get_status($proc)['pid'];
        proc_close($proc);

        return $pid;
    }

    /**
     * 起一个活着的 sleep 进程当「假守护进程」：信号真的发给它，用例结束后回收。
     *
     * @return array{pid: int, proc: resource}
     */
    private function spawnSleeper(): array
    {
        $proc = proc_open('/bin/sleep 30', ['1' => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($proc);

        // 注册回收：TERM 之后 sleep 会退出，这里只保证用例失败时也不留孤儿。
        register_shutdown_function(static function () use ($proc): void {
            $status = proc_get_status($proc);
            if ($status['running'] ?? false) {
                proc_terminate($proc);
            }
            proc_close($proc);
        });

        return ['pid' => (int) proc_get_status($proc)['pid'], 'proc' => $proc];
    }

    /** @return array<string, mixed> */
    private function callStatus(): array
    {
        return $this->call('GET', '/api/process/status', 'status');
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, string $action): array
    {
        $request = (new ServerRequest($method, $path))
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => 'admin', 'roles' => ['admin']]);
        HttpRequest::setRequest($request);

        $controller = new \app\admin\controllers\ProcessController();
        $response = $controller->{$action}();
        $decoded = (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function slotRow(array $data, string $label): array
    {
        foreach ((array) ($data['slots'] ?? []) as $row) {
            if ((string) ($row['name'] ?? '') . ':' . (string) ($row['slot'] ?? '') === $label) {
                return (array) $row;
            }
        }

        self::fail('状态响应里没有 ' . $label . ' 这一路：'
            . json_encode(array_map(static fn ($r) => ($r['name'] ?? '?') . ':' . ($r['slot'] ?? '?'), (array) ($data['slots'] ?? []))));
    }

    /**
     * 把「派发启动」的那个子进程换成一个立刻退出的命令。
     *
     * 必须两个通道都设：`env()` 有两份实现（kode/fibers 只读 getenv、framework 读
     * _ENV/_SERVER），谁先被加载谁生效。只设一个的话「无害化」会静默失效，
     * 用例就在开发机上真起一套常驻进程 —— 本轮实测发生过（守卫是坏的，
     * 连「已经在运行所以必须拒绝」那条用例都会走到派发）。
     */
    private function neutralizeSpawn(): void
    {
        $this->envBackup['PHP_BINARY'] = [
            'env' => isset($_ENV['PHP_BINARY']) ? (string) $_ENV['PHP_BINARY'] : null,
            'get' => getenv('PHP_BINARY'),
        ];

        $_ENV['PHP_BINARY'] = '/bin/echo';
        putenv('PHP_BINARY=/bin/echo');
    }
}

/**
 * 面板用例的探针 worker：只声明身份与实例数，不执行任何业务。
 */
final class ProbeWorker extends Worker
{
    public function __construct(
        private readonly string $probeName,
        private readonly int $probeInstances = 1,
        private readonly float $probeInterval = 60.0,
        private readonly bool $probeOnce = false,
    ) {
    }

    public function name(): string
    {
        return $this->probeName;
    }

    public function handle(): void
    {
    }

    public function interval(): float
    {
        return $this->probeInterval;
    }

    public function instances(): int
    {
        return $this->probeInstances;
    }

    public function once(): bool
    {
        return $this->probeOnce;
    }
}
