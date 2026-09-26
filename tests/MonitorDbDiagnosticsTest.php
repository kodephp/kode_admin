<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\MonitorController;
use app\support\Shard;
use app\tests\Support\DeadDatabase;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 「数据库监控」那一屏四张表的失败口径（`/api/monitor/db-activity|db-statements|connections|db-locks`）。
 *
 * 修之前这四条各自为政，而且**每一种都把「读不到」渲染成「这台系统没有这些数据」**：
 * 1. `dbStatements()` 一个空 `catch (\Throwable) { return $this->ok([]); }` —— 本机
 *    `kode_app` 就没装 `pg_stat_statements`（实测 `SELECT count(*) FROM pg_extension` 为 0，
 *    直接跑那条 SQL 得 `关系 "pg_stat_statements" 不存在`），于是「慢查询 Top-20」这一页
 *    永远空白，而它唯一被点开的时刻恰恰是有人查慢查询的时候；
 * 2. 另外三条回 `fail('查询失败：' . $e->getMessage(), 500)` —— PDO 原文里带着
 *    `connection to server at "127.0.0.1", port 5432 failed`（内网拓扑，同 `Shard::failureReason()`
 *    点名的那条红线），而前端一律 `.catch(() => [])`，于是 500 又被抹成一张空表格。
 *
 * 本文件锁的是**同一份结论口径**：HTTP 恒 200、`data` 形状恒定、`available` 说明读没读到、
 * `reason` 只给结论和修法（不给原文）。判定只在 `dbDegradeReason()` 里写一份，
 * 异常里的 SQLSTATE 只在 `Shard::sqlState()` 里取一次。
 */
final class MonitorDbDiagnosticsTest extends TestCase
{
    use DeadDatabase;

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

        HttpRequest::clear();

        parent::tearDown();
    }

    /** 本文件的拒连夹具用一个独占名字，避免与其它共用同一夹具的用例互相摘错连接。 */
    protected function deadConnectionName(): string
    {
        return 'zz_monitor_dead';
    }

    /** 拒连现场使用的库名。 */
    protected function deadDatabaseName(): string
    {
        return 'zz_monitor_unreachable';
    }

    // ─── 形状：读没读到必须说得出 ─────────────────────────────

    /**
     * 四个读口都必须是 `{rows|指标…, available, reason}`，且 HTTP 200。
     *
     * 形状恒定是这条线的硬要求：前端图表按固定键画图，缺 `available` 与「后端版本旧」
     * 在客户端无法区分（AGENTS §4 的 `unreadable_shards` 恒在就是这个道理）。
     */
    public function test_every_database_read_declares_whether_it_could_read(): void
    {
        foreach (['dbActivity' => 'rows', 'dbStatements' => 'rows', 'dbLocks' => 'rows', 'connections' => 'by_state'] as $handler => $listKey) {
            $data = $this->call($handler);

            self::assertIsArray($data, $handler . ' 的 data 不是对象形状：列表被直接当成了整个载荷');
            self::assertArrayHasKey($listKey, $data, $handler . ' 没有 ' . $listKey . ' 清单');
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
     * `available` 必须与这座库的真实目录一致，不能是「查询没炸就算可用」。
     *
     * 判据取自 `pg_extension`（扩展装没装）+ 那次 SELECT 的结果，两侧都要对上：
     * 没装却回 `available:true` + 空清单，就是这轮要修的缺陷本身。
     */
    public function test_the_slow_query_panel_agrees_with_the_live_catalog(): void
    {
        $installed = (int) (Db::select(
            "SELECT COUNT(*) AS c FROM pg_extension WHERE extname = 'pg_stat_statements'"
        )[0]['c'] ?? 0);

        $data = $this->call('dbStatements');

        if ($installed === 0) {
            self::assertFalse(
                self::availability($data, 'dbStatements'),
                '这座库没装 pg_stat_statements，面板却报「可读且无慢查询」：两者在页面上长得一样，'
                . '而只有一个需要有人去装扩展'
            );
            self::assertSame([], $data['rows'], '扩展没装却读出了行，说明查询走的不是 pg_stat_statements');
            $reason = (string) $data['reason'];
            self::assertStringContainsString('pg_stat_statements', $reason, '原因必须点名缺的是哪个对象：' . $reason);
            self::assertMatchesRegularExpression(
                '/CREATE EXTENSION|shared_preload_libraries/',
                $reason,
                '光说「读不到」不 actionable：这条面板的唯一处置项就是装扩展，得把修法写出来 → ' . $reason
            );
        } else {
            self::assertTrue((bool) $data['available'], '扩展已装却报不可用：' . $data['reason']);
        }
    }

    // ─── 现场：整库连不上时 ──────────────────────────────────

    /**
     * 库连不上时：四张表全部**自报读不到**，且连接数不许回 0。
     *
     * 这是全文件唯一真跑失败分支的用例 —— 把所有 pgsql 连接指向一个拒连端口，
     * 让四条腿各自撞一次真实的 08006，而不是靠读源码放行。
     * 收尾由 tearDown 归位（原始配置逐条放回 + removeConnection + setDefaultConnection）。
     */
    public function test_an_unreachable_database_is_named_instead_of_emptying_every_table(): void
    {
        $this->pointAtDeadConnection();

        foreach (['dbActivity' => 'rows', 'dbStatements' => 'rows', 'dbLocks' => 'rows'] as $handler => $listKey) {
            $data = $this->call($handler);
            self::assertFalse(self::availability($data, $handler), $handler . ' 连不上库却报可读：' . json_encode($data, JSON_UNESCAPED_UNICODE));
            self::assertSame([], $data[$listKey], $handler . ' 失败时清单必须是空数组而不是缺键');
            self::assertStringContainsString('不可达', (string) $data['reason'], $handler . ' 的原因没归成连接类：' . $data['reason']);
        }

        $conn = $this->call('connections');
        self::assertFalse(self::availability($conn, 'connections'));
        // 0 会被读成「这台系统一个连接都没有」，而那与「查不到」是两种结论（AGENTS §3 聚合口径）。
        // 这里不能用 `$conn['total'] ?? '缺键'`：?? 对 null 同样触发，正好把「回 null」判成「缺键」。
        foreach (['total', 'max', 'usage'] as $metric) {
            self::assertArrayHasKey($metric, $conn, 'connections 缺 ' . $metric . '：形状必须恒定');
            self::assertNull($conn[$metric], '读不到却回 ' . $metric . ':0 —— 把「不知道」渲染成了「一个都没有」');
        }
        self::assertSame([], (array) ($conn['by_state'] ?? ['__缺键__' => 1]));

        // 原文不外发：PDO 串里有 host/port，那是内网拓扑
        $raw = json_encode($conn, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('127.0.0.1', $raw, '诊断响应里带着连接串细节');
        self::assertStringNotContainsString('SQLSTATE', $raw, '诊断响应里回吐了驱动原文');
    }

    // ─── 判据本身：两向都要对上 ──────────────────────────────

    /**
     * 收尾对照：拒连现场不许留在 worker 上（常驻进程里它会把后续用例一起打挂）。
     *
     * 本文件把每条 pgsql 连接都换过一次配置，所以「归位」必须自己证三件事：
     * 配置真回来了、名字清单没变、读路径照旧读得到。只删掉假名字不算。
     * 这里跑的是 tearDown 用的同一条归位路径，跑完 tearDown 再跑一次必须幂等。
     */
    public function test_the_fake_connection_is_left_registered_nowhere(): void
    {
        $this->pointAtDeadConnection();
        $overridden = $this->overriddenConnections();
        self::assertNotEmpty($overridden, '现场构造没覆盖任何连接：下面的对照全会假绿');

        $this->restoreDeadDatabaseScene();

        foreach ($overridden as $name) {
            $original = (int) ($this->originalConnectionConfigs()[$name]['port'] ?? 0);
            self::assertNotSame(1, $original, $name . ' 本来就连端口 1：这条对照没有区分度');
            self::assertSame($original, (int) (Db::getConfig($name)['port'] ?? -1), $name . ' 的配置没归位');
        }

        self::assertSame(
            $this->connectionNamesWhenSnapshotTaken(),
            array_keys(Db::getConnections()),
            '跑完这一轮多留（或少了）连接名：清单必须与进来时逐一致'
        );
        self::assertFalse(Db::hasConnection($this->deadConnectionName()), '拒连连接还注册着');
        self::assertNotSame($this->deadConnectionName(), Db::getDefaultConnection(), '默认连接被留在了假连接上');
        self::assertCount(1, Db::select('SELECT 1 AS one'), '读路径还停在拒连配置上：后续用例全会挂在这里');

        $this->restoreDeadDatabaseScene();
        self::assertSame($this->connectionNamesWhenSnapshotTaken(), array_keys(Db::getConnections()), '归位不幂等：第二次跑动了清单');
    }

    /**
     * 结论口径是一份纯函数，所以「库连不上」这类现场之外的分支也能逐条锁住。
     *
     * 每条都配一个假 host/port/口令在原文里：只要有一档把原文搬进结论，
     * 下面那条「不许出现拓扑细节」就会红。
     */
    public function test_the_reason_is_a_conclusion_and_never_the_driver_text(): void
    {
        self::assertTrue(
            method_exists(MonitorController::class, 'dbDegradeReason'),
            '失败判定没有唯一实现：四个读口会各自 match 一遍 SQLSTATE，迟早只改对一个'
        );

        $leaky = 'SQLSTATE[xxxx] connection to server at "10.255.255.9", port 6432 failed: password=Sup3rSecret';

        $cases = [
            ['08006', null, '数据库不可达'],
            ['HYT00', null, '数据库不可达'],
            ['42501', null, '无权'],
            ['42P01', 'pg_stat_statements', 'pg_stat_statements'],
            ['55000', 'pg_stat_statements', 'shared_preload_libraries'],
            ['', null, '读取失败'],
        ];

        foreach ($cases as [$state, $extension, $needle]) {
            $reason = MonitorController::dbDegradeReason($this->pdoLike($state, $leaky), $extension);
            self::assertStringContainsString($needle, $reason, "SQLSTATE {$state} 归出的结论里没有「{$needle}」：{$reason}");
            self::assertStringNotContainsString('10.255.255.9', $reason, "SQLSTATE {$state} 把内网拓扑带进了结论");
            self::assertStringNotContainsString('Sup3rSecret', $reason, "SQLSTATE {$state} 把原文搬进了结论");
        }

        // 非 SQL 类异常（代码用错、空指针）不许归成「扩展没装」：那是把人往错的方向支使。
        $other = MonitorController::dbDegradeReason(new \RuntimeException('boom'), 'pg_stat_statements');
        self::assertStringNotContainsString('pg_stat_statements', $other, '一次 \Error 被说成「缺扩展」：' . $other);
    }

    /**
     * SQLSTATE 只能从 `errorInfo[0]` 取：pdo_pgsql 把**所有**错误的驱动码回吐成 7。
     *
     * 于是 `(string) $e->getCode()` 在连接类故障上是 `'7'`（实测），
     * `str_starts_with($state, '08')` 永不命中 —— 「分片库不可达」这句结论
     * 在响应里从来没出现过，缺口一律被归成通用的「该片操作失败」。
     * 包内同款判据（`PdoConnection::isConnectionFailure()`）读的就是 errorInfo[0]。
     */
    public function test_a_pgsql_connection_failure_is_still_classified_as_one(): void
    {
        $reason = Shard::failureReason($this->pdoLike('08006', $msg = 'connection refused to 10.255.255.9'));

        self::assertStringContainsString('不可达', $reason, '真·不可达被归成了别的：' . $reason);
        self::assertStringNotContainsString('10.255.255.9', $reason, '结论里带了原文拓扑：' . $reason);

        // 反向对照：不带 errorInfo 时仍按 getCode() 取（老口径不能整体丢掉，
        // 否则 42P01 那一条的行为断言就会失去落点）。
        self::assertStringContainsString(
            '片表不存在',
            Shard::failureReason($this->barePdo('42P01')),
            'getCode() 回落档被改掉了：42P01 必须仍归成「片表不存在」'
        );
    }

    // ─── 静态门禁：原文不得进响应 ────────────────────────────

    /** 四个读口都不许把 `$e->getMessage()` 拼进响应（诊断走日志，响应只给结论）。 */
    public function test_no_database_read_echoes_the_driver_message(): void
    {
        $hits = self::rawEchoes(self::code());

        self::assertSame([], $hits, '诊断响应回吐了驱动原文（内含主机端口）：' . json_encode($hits, JSON_UNESCAPED_UNICODE));

        // 正对照：旧写法必须被同一个判据点名，否则上面的绿只能说明扫描没扫到东西。
        self::assertSame(
            ["return \$this->fail('查询失败：' . \$e->getMessage(), 500);"],
            self::rawEchoes(<<<'OLD'
        } catch (\Throwable $e) {
            return $this->fail('查询失败：' . $e->getMessage(), 500);
        }
OLD),
            '门禁失效：旧写法不再变红'
        );
    }

    /**
     * 抓「把异常原文拼进响应」的那几行（先剥注释，免得文档里引用的旧写法把门禁点亮）。
     *
     * @return list<string>
     */
    private static function rawEchoes(string $code): array
    {
        $stripped = '';
        foreach (token_get_all($code) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $stripped .= "\n";
                continue;
            }
            $stripped .= is_array($t) ? $t[1] : $t;
        }

        $hits = [];
        foreach (preg_split('/\R/', $stripped) ?: [] as $line) {
            if (str_contains($line, '$this->fail(') && str_contains($line, 'getMessage(')) {
                $hits[] = trim($line);
            }
        }

        return $hits;
    }

    private static function code(): string
    {
        $file = dirname(__DIR__) . '/app/admin/controllers/MonitorController.php';
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    // ─── 私有 ───────────────────────────────────────────────

    /**
     * 造一个「像 PDO 那样」的异常：kode/database 会把 PDOException 包一层，
     * 所以判据必须走异常链，这里就按真实现场造（外层 RuntimeException + previous）。
     */
    private function pdoLike(string $state, string $message): \RuntimeException
    {
        $pdo = new \PDOException($message);
        // PDOException::$errorInfo 是 public 属性，真实现场由驱动填。
        $pdo->errorInfo = [$state, 7, $message];

        return new \RuntimeException('查询失败: ' . $message, 0, $pdo);
    }

    /** 只有 getCode() 可用（errorInfo 未填）的形态。 */
    private function barePdo(string $state): \PDOException
    {
        $pdo = new \PDOException('SQLSTATE[' . $state . '] …');
        $pdo->errorInfo = null;
        // 无 errorInfo 时判据回落 getCode()：pgsql 的语句错误里 getCode() 就是 SQLSTATE 串。
        $reflection = new \ReflectionProperty(\PDOException::class, 'code');
        $reflection->setValue($pdo, $state);

        return $pdo;
    }

    /**
     * 先证明 `available` 真的在、真的是布尔，再把它的值交给断言。
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

    /**
     * 直接派发控制器方法并解出 data 键（信封本身也顺手断言状态码）。
     *
     * @return array<string, mixed>
     */
    private function call(string $handler): array
    {
        HttpRequest::setRequest(
            (new ServerRequest('GET', '/api/monitor/' . $handler))
                ->withAttribute('auth', (object) ['uid' => 1, 'username' => 'admin', 'roles' => ['admin']])
        );

        $response = (new MonitorController())->{$handler}();
        $decoded = (array) json_decode((string) $response->getBody(), true);

        self::assertSame(
            200,
            (int) ($decoded['code'] ?? 0),
            $handler . ' 不再回 200：失败状态改由 available 声明，500 会被前端 .catch 抹成空表格'
        );
        self::assertArrayHasKey('data', $decoded, $handler . ' 没有 data：形状随成败变了');

        return (array) $decoded['data'];
    }
}
