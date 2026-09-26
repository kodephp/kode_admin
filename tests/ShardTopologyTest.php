<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Database\Db\Connection;
use Kode\Database\Db\Db;
use Kode\Database\Pool\PoolManager;
use Kode\Framework\Testing\TestCase;
use app\admin\controllers\ShardController;
use app\support\Shard;
use ReflectionMethod;

/**
 * 分片拓扑变更的收口（凭证外泄 / DSN 注入 / 连接泄漏 / 模数为 0）。
 *
 * 修复前的形状：PUT /api/shard/config 把请求里的 `db_hosts` 直接拆开，逐个
 * `Db::addConnection('shard_create_NN', [host => 请求值, username/password => 应用凭证])`
 * 并在对端执行 `CREATE DATABASE`。于是：
 *  1. 管理端一次保存 = 让服务端带着**库账号密码**去连任意地址（PG 握手明文送 username，
 *     对端还可要求明文口令）并在对方库里建库 —— 主机只能由运维在 .env 声明，不能由请求现编；
 *  2. 主机串没有字符集约束，`127.0.0.1 port=5433` 这类连接串关键字会整体落进 host 配置；
 *  3. 临时连接名（shard_create_NN）在常驻进程里没人回收（`Db::removeConnection` 当时不存在），
 *     `$connections` 与连接池每次保存堆一批；
 *  4. 已注册连接的「探测」用 `Db::connection()`：它对未知名字不抛错，而是回落全局配置，
 *     所以 connections_match 恒为 true，报告是假的；
 *  5. 热配置里 db_count/table_shards 落成 0 或非数字，路由的 `crc32 % $mod` 就在每次分片写入时
 *     抛 DivisionByZeroError；而未注册的分片连接名会让写入**静默落到主库的分片表**上。
 *
 * 本用例只碰纯函数与静态状态，不对真实 PG 执行任何 DDL：一个测试都不该建库。
 */
final class ShardTopologyTest extends TestCase
{
    protected bool $independentApp = true;

    /** @var array<string, mixed> 被测静态属性快照（键 = 类::属性） */
    private array $snapshot = [];

    /** @var array<string, mixed> 测试前 SHARD_DB_HOSTS 的进程环境值 */
    private string|false $declaredHosts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));

        $this->snapshot = $this->capture([
            Db::class . '::connections',
            Db::class . '::connectionCache',
            Db::class . '::defaultConnection',
            Db::class . '::config',
            PoolManager::class . '::pools',
            PoolManager::class . '::poolTypes',
            PoolManager::class . '::driver',
            PoolManager::class . '::poolType',
            Shard::class . '::configCache',
            Shard::class . '::cachedAt',
            Shard::class . '::cacheValid',
            Shard::class . '::ensured',
        ]);
        $this->declaredHosts = getenv('SHARD_DB_HOSTS');
    }

    protected function tearDown(): void
    {
        $this->restore($this->snapshot);
        if ($this->declaredHosts === false) {
            putenv('SHARD_DB_HOSTS');
        } else {
            putenv('SHARD_DB_HOSTS=' . $this->declaredHosts);
        }

        parent::tearDown();
    }

    /**
     * 主机只能取运维声明过的：请求现编的地址一律拒绝（连不出去，也就送不出凭证）。
     */
    public function test_request_cannot_name_an_undeclared_shard_host(): void
    {
        $ctrl = new ShardController();

        // 前提自证：白名单确实只有 .env 声明的那些，否则下面的断言什么都没挡住
        $allowed = $this->invoke($ctrl, 'allowedHosts');
        self::assertNotContains('evil.example.com', $allowed);
        self::assertNotContains('attacker.internal', $allowed);

        foreach (['evil.example.com', 'attacker.internal', '10.9.9.9'] as $host) {
            $this->assertRejected($ctrl, $host, '未授权');
        }

        // 混在合法地址里也一样拒：不能「第一个合法就整串放行」
        $this->assertRejected($ctrl, $allowed[0] . ',evil.example.com', '未授权');
    }

    /**
     * 连接串分隔符不能活着穿过校验：host 最终拼进 DSN。
     */
    public function test_dsn_separators_are_rejected_on_format_not_only_allowlist(): void
    {
        $ctrl = new ShardController();
        $allowed = $this->invoke($ctrl, 'allowedHosts');
        $main = $allowed[count($allowed) - 1];

        // 追加非法字符的「变形主机」要先被字符集拦下（报非法主机名，而不是「未授权」）
        foreach ([$main . ' port=5433', $main . "/etc/passwd", "127.0.0.1\x0aX"] as $host) {
            $this->assertRejected($ctrl, $host, '非法主机名');
        }
    }

    /**
     * 声明过的多主机照常可用（收口不能把正常拓扑管理一起关掉）。
     */
    public function test_hosts_declared_by_the_operator_pass(): void
    {
        putenv('SHARD_DB_HOSTS=10.0.0.7,10.0.0.8');
        $ctrl = new ShardController();

        self::assertSame(['10.0.0.8'], $this->invoke($ctrl, 'parseHosts', '10.0.0.8'));
        self::assertSame(
            ['10.0.0.7', '10.0.0.8'],
            $this->invoke($ctrl, 'parseHosts', ' 10.0.0.7 , 10.0.0.8 ,,10.0.0.7')
        );
        // 留空 = 全部落主库（与 config/sharding.php 口径一致）
        self::assertSame([], $this->invoke($ctrl, 'parseHosts', ''));
    }

    /**
     * 库名模板：既要是能 sprintf 的模板，也要只含标识符安全字符。
     */
    public function test_db_name_template_must_be_a_usable_identifier(): void
    {
        $ctrl = new ShardController();

        self::assertTrue($this->invoke($ctrl, 'isValidDbName', 'kode_app_%02d'));
        self::assertTrue($this->invoke($ctrl, 'isValidDbName', 'kode_app_%d'));

        // 没有占位符：sprintf 直接抛 ValueError，每次建库都红
        self::assertFalse($this->invoke($ctrl, 'isValidDbName', 'kode_app'));
        // 标识符位置的注入面
        self::assertFalse($this->invoke($ctrl, 'isValidDbName', 'app_%02d; drop database x'));
        self::assertFalse($this->invoke($ctrl, 'isValidDbName', 'a'));
    }

    /**
     * 分片模数不得为 0：路由是 `crc32($key) % db_count`，读侧必须自己兜住脏配置。
     */
    public function test_hot_config_values_that_would_become_modulo_by_zero_are_clamped(): void
    {
        $shard = new Shard();

        // 缺失/为 null：回落默认模数
        $bad = $this->invoke($shard, 'normalize', [
            'enabled' => true,
            'db_count' => 0,
            'table_shards' => null,
            'db_name' => '',
        ]);
        self::assertSame(1, $bad['db_count'], '显式 0 必须抬到 1，否则取模即除零');
        self::assertSame(2, $bad['table_shards'], 'null 走默认模数');
        self::assertSame('kode_app_%02d', $bad['db_name']);

        // 上限按管理端口径收敛；非数字串（转 int 为 0）同样抬到 1
        $big = $this->invoke($shard, 'normalize', ['db_count' => 9999, 'table_shards' => 'abc']);
        self::assertSame(64, $big['db_count']);
        self::assertSame(1, $big['table_shards']);

        // 收敛后取模恒可进行
        self::assertGreaterThanOrEqual(0, Shard::index('zz_owner_key', $big['db_count']));
    }

    /**
     * 未注册的分片连接名必须报错，不能静默回落主库。
     *
     * `Db::connection('shard_03')` 在只有两条连接的进程里不抛错，而是拿全局配置连主库：
     * 分片键于是按新模数落到**主库的分片表**，路由看起来一切正常（静默数据分裂）。
     */
    public function test_route_refuses_an_unregistered_shard_connection(): void
    {
        $this->seedShardConfig(['enabled' => true, 'db_count' => 4, 'table_shards' => 2]);

        // 找一个必然落到 shard_03 的归属键（库序号 = 高位 16 位 mod db_count）
        $key = '';
        for ($n = 0; $n < 500; $n++) {
            $candidate = 'zz_owner_' . $n;
            if (((abs(crc32($candidate)) >> 16) % 4) === 3) {
                $key = $candidate;
                break;
            }
        }
        self::assertNotSame('', $key, '探针键构造失败，用例前提失效');
        self::assertFalse(Db::hasConnection('shard_03'), '本机若真注册了 shard_03，这条用例探不到未注册分支');

        try {
            Shard::route('kode_notices', $key);
            self::fail('未注册的分片连接被放行了：写入会静默落到主库');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('未注册', $e->getMessage());
            self::assertStringContainsString('shard_03', $e->getMessage());
        }
    }

    /**
     * 维护连接用完即摘，且异常路径也摘（常驻进程里没人回收就是永久泄漏）。
     */
    public function test_maintenance_connection_is_released_even_when_the_callback_throws(): void
    {
        $ctrl = new ShardController();
        self::assertFalse(Db::hasConnection('shard_maint'));

        // 成功路径：回调里连接必须在，返回后必须不在
        $seenInside = $this->invoke(
            $ctrl,
            'withMaintenance',
            '127.0.0.1',
            static fn(): bool => Db::hasConnection('shard_maint')
        );
        self::assertTrue($seenInside, '维护连接没建起来，后面的断言就只是空转');
        self::assertFalse(Db::hasConnection('shard_maint'), '维护连接没回收');

        // 异常路径：finally 也要摘
        try {
            $this->invoke($ctrl, 'withMaintenance', '127.0.0.1', static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('回调异常被吞掉了');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertFalse(Db::hasConnection('shard_maint'), '异常路径没回收维护连接');
    }

    /**
     * 分片写入漏斗（route → assertRegistered → 目标片连接 → 片表）跑通一次真实往返。
     *
     * 前面几条用例都在纯函数和静态状态上打转，碰不到真正的落点：路由返回的连接名/表名
     * 只要有一个字对不上，`Db::connection()` 的惰性回落就会把写入悄悄按到主库的片表上，
     * 单元断言全绿、数据却分裂。这里插一行探针并回读，最后按 id + 归属键精确清掉。
     *
     * 探针**不写 `type` 列**：该列目前只存在于迁移/插件钩子里，库里可能还没对齐
     * （见 2026_09_23_000041_notice_type）。写入路径不依赖它，探针也就不该依赖它。
     */
    public function test_a_real_sharded_write_lands_on_the_shard_not_the_main_db(): void
    {
        if (!$this->seedRegisteredShardTopology()) {
            self::markTestSkipped('本环境未注册分片连接，写入探针无处可落');
        }

        // 归属键唯一：清理只可能命中本用例自己插的行
        $owner = 'zz_shard_it_' . getmypid() . '_' . bin2hex(random_bytes(4));
        Shard::ensureTables('kode_notices');
        [$conn, $table] = Shard::route('kode_notices', $owner);
        self::assertMatchesRegularExpression('/^kode_notices_\d\d$/', $table);
        self::assertTrue(Db::hasConnection((string) $conn), '路由给出的连接名没注册，写入会回落主库');

        // 表名来自本文件的基名 + 路由自己的 %02d 编号，非用户输入，可安全拼接
        $db = Shard::db($conn);

        // 注册名对还不够：连接配置里的库名若拼错（模板没插值、或照抄了主库），
        // 写入会落在**主库的同名片表**上，看起来一切正常。用 current_database() 直接验落点。
        $shardDb = (string) ($db->select('SELECT current_database() AS db')[0]['db'] ?? '');
        $mainDb = (string) (Db::select('SELECT current_database() AS db')[0]['db'] ?? '');
        self::assertNotSame('', $shardDb);
        self::assertNotSame($mainDb, $shardDb, "分片连接 {$conn} 指向了主库 {$mainDb}");

        $rowId = 0;
        try {
            $rowId = (int) $db->insert(
                "INSERT INTO {$table} (title, body, created_by) VALUES (?, ?, ?)",
                ['分片写入探针', 'probe', $owner]
            );
            self::assertGreaterThan(0, $rowId, '片表插入没拿到 id');

            $rows = $db->select("SELECT id, title, status FROM {$table} WHERE id = ?", [$rowId]);
            self::assertCount(1, $rows, '片上写进去的行回读不到');
            self::assertSame('分片写入探针', $rows[0]['title']);
            self::assertSame(1, (int) $rows[0]['status'], 'status 默认值没生效');

            // 同一行绝不能同时出现在主库基表里 —— 那正是「回落全局配置」的现场
            $leaked = Db::select('SELECT id FROM kode_notices WHERE created_by = ?', [$owner]);
            self::assertCount(0, $leaked, '分片写入落到了主库基表：路由的连接名没生效');
        } finally {
            if ($rowId > 0) {
                $db->delete("DELETE FROM {$table} WHERE id = ? AND created_by = ?", [$rowId, $owner]);
            }
        }
        self::assertCount(
            0,
            $db->select("SELECT id FROM {$table} WHERE created_by = ?", [$owner]),
            '探针行没清干净'
        );
    }

    /**
     * 「连接在不在」要用注册表判断，不能用 `Db::connection()` 探。
     */
    public function test_registration_is_probed_by_the_registry_not_the_lazy_accessor(): void
    {
        // 前提自证：这正是老报告的失效方式 —— 未知名字照样「拿得到」连接对象，
        // 于是 connections_match 恒为 true（Connection 只是名字包装器，构造不碰网络）。
        self::assertFalse(Db::hasConnection('shard_99'));
        self::assertInstanceOf(Connection::class, Db::connection('shard_99'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shard_99');
        Shard::assertRegistered('shard_99');
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * 按「本机真实注册了哪几条分片连接」设好热配置，返回是否有可用片。
     *
     * 不能照 .env 的 db_count 硬写：落到未注册的片上会先被 assertRegistered 拦掉，
     * 探不到写入本身。
     */
    private function seedRegisteredShardTopology(): bool
    {
        $count = 0;
        while ($count < 64 && Db::hasConnection(sprintf('shard_%02d', $count))) {
            $count++;
        }
        if ($count === 0) {
            return false;
        }
        $this->seedShardConfig([
            'enabled' => true,
            'db_count' => $count,
            'table_shards' => 2,
            'db_name' => 'kode_app_%02d',
            'db_hosts' => '',
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function seedShardConfig(array $cfg): void
    {
        $ref = new \ReflectionProperty(Shard::class, 'configCache');
        $ref->setAccessible(true);
        $ref->setValue(null, $cfg);

        $at = new \ReflectionProperty(Shard::class, 'cachedAt');
        $at->setAccessible(true);
        $at->setValue(null, microtime(true) + 60);

        $valid = new \ReflectionProperty(Shard::class, 'cacheValid');
        $valid->setAccessible(true);
        $valid->setValue(null, true);
    }

    /**
     * @param mixed ...$args
     */
    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invokeArgs($target, $args);
    }

    private function assertRejected(ShardController $ctrl, string $hosts, string $needle): void
    {
        try {
            $this->invoke($ctrl, 'parseHosts', $hosts);
            self::fail("db_hosts 未被拒绝：{$hosts}");
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function capture(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            [$class, $prop] = explode('::', $key);
            $ref = new \ReflectionProperty($class, $prop);
            $ref->setAccessible(true);
            $out[$key] = $ref->getValue();
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function restore(array $values): void
    {
        foreach ($values as $key => $value) {
            [$class, $prop] = explode('::', $key);
            $ref = new \ReflectionProperty($class, $prop);
            $ref->setAccessible(true);
            $ref->setValue(null, $value);
        }
    }
}
