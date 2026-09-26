<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\ScheduleController;
use app\support\DbReadFace;
use app\tests\Support\DeadDatabase;
use Kode\Database\Db\Db;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 「任务调度」这一屏的读入口，在 framework 把「读不到」改成抛异常之后必须自己接住。
 *
 * framework v1.14.0 起 `stats()` 会抛、v1.15.0 起 `runHistory()` 也抛（旧写法
 * `catch (\Throwable) { logger(); return []; }` 把断链压成「这个任务从没跑过」）。
 * 抛到管理端还不够 —— 这一页原先是这样消费它的：
 *
 *  - `health()` / `history()` **没有** try/catch，异常出控制器 → 500，而 `msg` 里带着
 *    PDO 原文（`connection to server at "127.0.0.1", port 5432 failed`，内网拓扑）；
 *  - 前端两条腿都写成 `catch { /* 忽略 *\/ }`，于是那个 500 又被抹成一张空表格。
 *
 * 两头合起来把「一座库没读到」渲染成「这台系统没有任务跑过」，而这张页唯一被点开的时刻
 * 恰恰是有人来查任务为什么没跑的时候。所以口径与 `MonitorController` 那四条数据库腿一致：
 * **HTTP 恒 200 + `available`/`reason` 同级键、出参形状不随成败变**，`reason` 只给结论
 * 和修法、驱动原文与 host/port 永不出参（AGENTS §6「读不到与没有数据必须是两件事」）。
 *
 * 判定（SQLSTATE → 结论）只在 `app\support\DbReadFace` 里写一份；`MonitorController`
 * 与 `ScheduleController` 都走它。本文件既验现场（把每条 pgsql 连接指向拒连端口，
 * 让每个入口真撞一次 08xxx），也验形状、验不外漏、验§6 的正负对照。
 */
final class ScheduleReadFaceTest extends TestCase
{
    use DeadDatabase;

    private const TASK = 'zz-sched-readface';

    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        $this->snapshotConnections();
    }

    protected function tearDown(): void
    {
        $this->restoreDeadDatabaseScene();
        $this->deleteProbeRows();
        HttpRequest::clear();

        parent::tearDown();
    }

    /**
     * 每个读入口都必须是 `{清单键, available, reason}`，且 HTTP 200。
     *
     * 形状恒定是硬要求：前端按固定键画图，缺 `available` 与「后端版本旧」在客户端
     * 无法区分（同 `unreadable_shards` 恒在的道理）。
     */
    public function test_every_schedule_read_face_declares_whether_it_could_read(): void
    {
        foreach (self::FACES as $handler => $listKey) {
            [$query, $params] = self::argsFor($handler);
            $data = $this->data($handler, $query, $params);

            self::assertIsArray($data, $handler . ' 的 data 不是对象形状：清单被直接当成了整个载荷');
            self::assertArrayHasKey($listKey, $data, $handler . ' 没有 ' . $listKey . ' 键');
            self::assertIsArray($data[$listKey], $handler . ' 的 ' . $listKey . ' 不是数组（形状随成败变了）');
            self::assertArrayHasKey('available', $data, $handler . ' 没声明读没读到：空清单会被渲染成「这台系统没有这些数据」');
            self::assertIsBool($data['available'], $handler . ' 的 available 不是布尔：' . var_export($data['available'], true));
            self::assertArrayHasKey('reason', $data, $handler . ' 缺 reason 键（正常时应为 null，不能缺键）');
            self::assertTrue(
                $data['reason'] === null || is_string($data['reason']),
                $handler . ' 的 reason 类型不对：' . var_export($data['reason'], true)
            );
            if (!$data['available']) {
                self::assertNotSame('', trim((string) $data['reason']), $handler . ' 说读不到却没给原因');
            }
        }
    }

    /**
     * 库连不上时：每个入口**自报读不到**，而不是交回一张看着很干净的空表。
     *
     * 这里跑的是真实失败分支 —— 把所有 pgsql 连接指向拒连端口（夹具会先自查
     * `Db::select('SELECT 1')` 真的抛了，否则整个现场是假的），让每个入口各自撞一次 08xxx。
     */
    public function test_an_unreachable_database_is_named_instead_of_emptying_the_schedule_page(): void
    {
        $this->pointAtDeadConnection();

        foreach (self::FACES as $handler => $listKey) {
            [$query, $params] = self::argsFor($handler);
            $data = $this->data($handler, $query, $params);

            self::assertFalse(
                self::availability($data, $handler),
                $handler . ' 连不上库却报可读：' . json_encode($data, JSON_UNESCAPED_UNICODE)
            );
            self::assertSame([], $data[$listKey], $handler . ' 失败时清单必须是空数组而不是缺键');
            self::assertStringContainsString('不可达', (string) $data['reason'], $handler . ' 的原因没归成连接类：' . $data['reason']);

            $raw = json_encode($data, JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString('127.0.0.1', $raw, $handler . ' 的响应里带着连接串细节（内网拓扑）');
            self::assertStringNotContainsString('SQLSTATE', $raw, $handler . ' 的响应里回吐了驱动原文');
        }
    }

    /**
     * 租户统计的数字在读不到时必须是 null，不是 0。
     *
     * `total: 0` 会被读成「这台系统一次都没跑过」，而那是另一种泄漏（AGENTS §3 聚合口径：
     * 收口径而不是回 0）。这里不能用 `?? '缺键'` 兜底 —— `??` 对 null 同样触发，
     * 正好把「回 null」判成「缺键」，所以逐键 assertArrayHasKey + assertNull。
     */
    public function test_unreachable_tenant_stats_answer_null_not_zero(): void
    {
        $ok = $this->data('tenantStats', [], ['task' => self::TASK]);
        self::assertTrue(self::availability($ok, 'tenantStats'));

        $this->pointAtDeadConnection();

        $data = $this->data('tenantStats', [], ['task' => self::TASK]);
        self::assertFalse(self::availability($data, 'tenantStats'), json_encode($data, JSON_UNESCAPED_UNICODE));
        foreach (['total', 'success', 'failed', 'last_duration_ms'] as $metric) {
            self::assertArrayHasKey($metric, $data, 'tenantStats 缺 ' . $metric . '：形状必须恒定');
            self::assertNull($data[$metric], '读不到却回 ' . $metric . ':0 —— 把「不知道」渲染成了「这台系统一次都没跑过」');
        }
        self::assertArrayHasKey('last_run', $data);
        self::assertNull($data['last_run']);
        self::assertSame(self::TASK, $data['task'], 'task 键必须回显问的是哪条任务');

        $raw = json_encode($data, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('127.0.0.1', $raw, '统计响应里带着连接串细节');
    }

    /**
     * `prune()` 也是同一族：它调的 `pruneRunHistory()` 从 framework v1.11.1 起就会抛，
     * 而控制器现在没接 —— 于是 500 的 `msg` 里是驱动原文（内含 host/port）。
     *
     * 破坏性入口的失败必须是「明确的 500 + 结论」，既不能吞成 `{deleted:0}`
     * （那会被念成「清理完成，没有过期数据」），也不能把原文发出去。
     *
     * 本用例自己先做爆破半径核对：现场是拒连配置，DELETE 根本跑不到库里，
     * 但收尾仍要证明真实表的行数一格没少。
     */
    public function test_prune_reports_a_conclusion_and_not_the_driver_text(): void
    {
        $before = (int) (Db::select('SELECT COUNT(*) AS c FROM kode_schedule_runs')[0]['c'] ?? -1);
        self::assertGreaterThanOrEqual(0, $before, '取不到基线行数：后面的对照没有意义');

        $this->pointAtDeadConnection();

        $result = $this->send('prune', ['days' => '7']);

        self::assertSame(500, $result['status'], '清理失败被吞成了成功：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $body = json_encode($result['body'], JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('SQLSTATE', $body, '清理失败把驱动原文发给了客户端');
        self::assertStringNotContainsString('127.0.0.1', $body, '清理失败把内网拓扑发给了客户端');
        self::assertStringContainsString('不可达', (string) ($result['body']['msg'] ?? ''), '失败原因没归成结论：' . $body);
        self::assertArrayNotHasKey('deleted', (array) ($result['body']['data'] ?? []), '失败响应里不许带 deleted 计数');

        $this->restoreDeadDatabaseScene();
        $after = (int) (Db::select('SELECT COUNT(*) AS c FROM kode_schedule_runs')[0]['c'] ?? -1);
        self::assertSame($before, $after, '本用例动了真实执行历史：清理必须在库不可达时就停下');
    }

    /**
     * AGENTS §6：这类调用点必须有「真数据 → 查得到 → 删掉 → 查不到」的正负对照。
     *
     * 只测「读不到时 available 是 false」是不够的 —— 把修复版写成恒回 `available:false`
     * 也能全绿。这一条钉的是：真跑过一次的任务，健康度和抽屉都得说得出它。
     */
    public function test_a_recorded_run_is_readable_and_gone_after_it_is_deleted(): void
    {
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);
        self::assertTrue($dispatcher->logRun(self::TASK, 'success', null, 42), '夹具没能写入执行记录：后面的断言全会假绿');

        $history = $this->data('history', ['limit' => '10'], ['name' => self::TASK]);
        self::assertTrue(self::availability($history, 'history'));
        self::assertCount(1, $history['rows'], '刚记上的那次执行没出现在抽屉里：' . json_encode($history, JSON_UNESCAPED_UNICODE));
        self::assertSame(42, (int) ($history['rows'][0]['duration_ms'] ?? -1));
        self::assertSame('success', (string) ($history['rows'][0]['status'] ?? ''));

        $health = $this->data('health');
        self::assertTrue(self::availability($health, 'health'));
        self::assertArrayHasKey(self::TASK, (array) $health['tasks'], '健康度里没有刚记上的那条任务');
        self::assertSame(1, (int) $health['tasks'][self::TASK]['total']);
        self::assertSame(100.0, (float) $health['tasks'][self::TASK]['success_rate']);

        $this->deleteProbeRows();

        $after = $this->data('history', ['limit' => '10'], ['name' => self::TASK]);
        self::assertTrue(self::availability($after, 'history'), '表还在、只是没记录，不该报成读不到');
        self::assertSame([], $after['rows']);
        self::assertArrayNotHasKey(self::TASK, (array) $this->data('health')['tasks'], '删掉的执行记录还留在健康度里');
    }

    /**
     * 一份判定只许有一处实现。
     *
     * `MonitorController` 那四条腿已经有自己的 SQLSTATE → 结论映射了；这一轮新增的
     * 六个入口如果各自再 match 一遍，迟早只改对一个（本仓这条线反复踩过）。
     *
     * 指纹取「`数据库不可达` 这句结论 + 一个 match 分支」：它是诊断面独有的措辞
     * （`Shard::failureReason()` 的另一份 map 说的是「分片库不可达 / 片表不存在」，
     * 词汇与处置项都不同，那是有意分开的第二份口径，不在本门禁射程内）。
     * 取措辞而不是取 `'55000'` 字面量：扩展那两档带 `&& $extension !== null` 的前置条件，
     * 合法的写法里 `'55000'` 后面跟的不是 `=>`，用字面量当指纹会漏掉真实现。
     */
    public function test_the_sqlstate_conclusion_is_implemented_exactly_once(): void
    {
        $files = self::appPhpFiles();
        self::assertGreaterThan(50, count($files), '扫描面异常小（' . count($files) . ' 个文件）：门禁会空转');

        $owners = [];
        foreach ($files as $file) {
            if (self::conclusionArms(self::stripComments((string) file_get_contents($file))) !== []) {
                $owners[] = str_replace(dirname(__DIR__) . '/', '', $file);
            }
        }

        self::assertSame(
            ['app/support/DbReadFace.php'],
            $owners,
            'SQLSTATE → 结论 的判定不止一处：' . json_encode($owners, JSON_UNESCAPED_UNICODE)
        );

        // 正对照：第二份实现必须被同一个判据点名，否则上面的绿只说明扫描没扫到东西。
        self::assertSame(
            ["str_starts_with(\$state, '08') => '数据库不可达',"],
            self::conclusionArms(<<<'CODE'
    return match (true) {
        str_starts_with($state, '08') => '数据库不可达',
        default => '读取失败',
    };
CODE)
        );

        // 反向对照：分片侧那份**有意分开**的词汇表不许被算成第二份实现
        // （否则门禁会在它每次被读到时变红，最后被人改成豁免清单而失去意义）。
        self::assertSame(
            [],
            self::conclusionArms(self::stripComments((string) file_get_contents(dirname(__DIR__) . '/app/support/Shard.php'))),
            'Shard 那份逐片词汇表被误判成诊断面判定的复制品'
        );
    }

    /**
     * 自报「读不到」的入口，必须走那份共用判定，不许自己拼一句 reason。
     *
     * 上一门禁管的是「映射只有一份」，这一条管的是「谁在用它」：
     * 一个入口写成 `'available' => false, 'reason' => '出错了'` 照样全绿，
     * 而那句没有归因的话术正是这一轮的原始缺陷形状。
     */
    public function test_every_face_that_reports_unavailable_asks_the_shared_face_why(): void
    {
        $rogue = [];
        $home = dirname(__DIR__) . '/app/support/DbReadFace.php';
        foreach (self::appPhpFiles() as $file) {
            if ($file === $home) {
                continue;
            }
            $code = self::stripComments((string) file_get_contents($file));
            $relative = str_replace(dirname(__DIR__) . '/', '', $file);

            if (str_contains($code, "'available' => false") && !str_contains($code, 'DbReadFace::')) {
                $rogue[] = $relative;
            }
        }

        self::assertSame([], $rogue, '这些入口自己写了「读不到」的结论：' . json_encode($rogue, JSON_UNESCAPED_UNICODE));

        // 正对照 + 射程自查：本轮的六个入口必须真的在扫描面上（否则绿得没有意义）。
        $schedule = self::stripComments((string) file_get_contents(dirname(__DIR__) . '/app/admin/controllers/ScheduleController.php'));
        self::assertStringContainsString('DbReadFace::', $schedule, 'ScheduleController 没走共用判定');
        self::assertStringNotContainsString(
            "'available' => false",
            $schedule,
            '控制器还在自己拼 available：那个键只应由 DbReadFace 产出'
        );
    }

    /** `MonitorController` 必须只是转发，不是又一份实现。 */
    public function test_the_monitor_delegate_and_the_shared_face_agree(): void
    {
        self::assertTrue(method_exists(DbReadFace::class, 'reason'), '共用的失败判定没有实现：六个入口会各写一遍');
        self::assertTrue(
            method_exists(\app\admin\controllers\MonitorController::class, 'dbDegradeReason'),
            'MonitorController 的入口被删掉了：它自己的回归（含文档口径）会失去落点'
        );

        $pdo = new \PDOException('SQLSTATE[42501] permission denied for view pg_stat_statements');
        $pdo->errorInfo = ['42501', 7, 'permission denied'];
        $wrapped = new \RuntimeException('查询失败', 0, $pdo);

        self::assertSame(
            DbReadFace::reason($wrapped),
            \app\admin\controllers\MonitorController::dbDegradeReason($wrapped),
            '两处结论分岔了：转发被改成了第二份实现'
        );
    }

    // ─── 私有 ───────────────────────────────────────────────

    /** @var array<string, string> 入口 => 清单键 */
    private const FACES = [
        'health' => 'tasks',
        'history' => 'rows',
        'runs' => 'rows',
        'tenants' => 'rows',
        'tenantRuns' => 'rows',
    ];

    /**
     * 各入口需要的查询串与路由参数（`history`/`tenantStats` 走 `{name}`/`{task}`）。
     *
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private static function argsFor(string $handler): array
    {
        return match ($handler) {
            'history' => [[], ['name' => self::TASK]],
            'tenantStats' => [[], ['task' => self::TASK]],
            default => [[], []],
        };
    }

    /**
     * 只属于本用例的数据：三张表里以 `zz-sched-readface` 为任务名的行。
     */
    private function deleteProbeRows(): void
    {
        foreach (['kode_schedule_runs' => 'task_name', 'kode_tenant_schedule_runs' => 'task_name', 'kode_cron_runs' => 'task_name'] as $table => $column) {
            if (Db::tableExists($table)) {
                Db::table($table)->where($column, self::TASK)->delete();
            }
        }
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function data(string $handler, array $query = [], array $params = []): array
    {
        $result = $this->send($handler, $query, $params);

        self::assertSame(
            200,
            $result['status'],
            $handler . ' 不再回 200：读失败改由 available 声明，500 会被前端 .catch 抹成空表格 —— '
            . json_encode($result['body'], JSON_UNESCAPED_UNICODE)
        );
        self::assertArrayHasKey('data', $result['body'], $handler . ' 没有 data：形状随成败变了');

        return (array) $result['body']['data'];
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $params
     *
     * @return array{status:int, body:array<string,mixed>}
     */
    private function send(string $handler, array $query = [], array $params = []): array
    {
        $request = (new ServerRequest('GET', '/api/schedules/' . $handler))
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => 'admin', 'roles' => ['admin']])
            ->withQueryParams($query);

        if ($params !== []) {
            $request = $request->withAttribute('_route_params', $params);
        }

        HttpRequest::setRequest($request);
        $response = (new ScheduleController())->{$handler}();
        HttpRequest::clear();

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }

    /**
     * 先证明 `available` 真的在、真的是布尔，再把值交给断言。
     *
     * 不这么做的话 `(bool) $data['available']` 在缺键时是 false，
     * 「assertFalse(不可用)」就会因为**根本没声明**而绿 —— 那是自证的假绿。
     *
     * @param array<string, mixed> $data
     */
    private static function availability(array $data, string $handler): bool
    {
        self::assertArrayHasKey('available', $data, $handler . ' 没声明 available：下面的断言会因缺键而假绿');
        self::assertIsBool($data['available'], $handler . ' 的 available 不是布尔');

        return (bool) $data['available'];
    }

    /** @return list<string> */
    private static function appPhpFiles(): array
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            dirname(__DIR__) . '/app',
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    /** 先剥注释再扫：文档里那句「旧写法长什么样」不是实现。 */
    private static function stripComments(string $code): string
    {
        $stripped = '';
        foreach (token_get_all($code) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $stripped .= "\n";
                continue;
            }
            $stripped .= is_array($t) ? $t[1] : $t;
        }

        return $stripped;
    }

    /**
     * 取「诊断面那条连接类结论的 match 分支」。
     *
     * 措辞是 `数据库不可达`（分片侧那份写的是 `分片库不可达`，两个字符串在 `str_contains`
     * 上不会互相命中：完整的 `数据库` 三字只出现在诊断面那份里）。
     *
     * @return list<string>
     */
    private static function conclusionArms(string $code): array
    {
        $hits = [];
        foreach (preg_split('/\R/', $code) ?: [] as $line) {
            if (str_contains($line, '数据库不可达') && str_contains($line, '=>')) {
                $hits[] = trim($line);
            }
        }

        return $hits;
    }
}
