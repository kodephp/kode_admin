<?php

declare(strict_types=1);

namespace app\tests\Support;

use app\support\Shard;
use Kode\Database\Db\Db;

/**
 * 把「这座库连不上」造出来，用完归位。
 *
 * 这个夹具的**全部难度在于把现场做真**，而不是做出来：本项目 `.env` 开着
 * `DB_READ_WRITE_SPLIT=true`（`app/bootstrap.php` 因此调
 * `Db::enableReadWriteSplit('pgsql_read')`），于是 `Db::select()` 走的是**读库连接**。
 * 只把默认连接换成拒连配置时，四条腿照样读得到（本机实测）——一整类「读不到」断言
 * 会全绿而什么都没测到。所以这里按「所有已注册的 pgsql 连接」逐条覆盖，
 * 既不写死连接名，也不管开关怎么配，并在收尾前自查 `Db::select('SELECT 1')` 真的抛了、
 * 异常里真的有 SQLSTATE（前提不成立直接红，而不是假绿）。
 *
 * 归位的顺序同样有讲究：`Db::addConnection()` 会把 `PoolManager::$driver`
 * （不带连接名调用时的默认键）改成**最后注册的那个名字**，所以最后一条必须放回默认连接；
 * 而 `removeConnection()` 对「当前默认连接」直接返回 false，得先摘掉默认名字才删得掉。
 */
trait DeadDatabase
{
    /** @var string|null 被本用例改过的默认连接，归位时必须放回去 */
    private ?string $originalConnection = null;

    /** @var array<string, array> 连接名 => 被覆盖前的配置，逐条原样放回 */
    private array $originalConfigs = [];

    /** @var string[] 被指向拒连配置的连接名（含读库），归位时逐个放回 */
    private array $overridden = [];

    /** @var string[] 跑之前已注册的连接名清单，用来证明没有留下新名字 */
    private array $connectionNamesBefore = [];

    private bool $deadConnectionAdded = false;

    /** 记下来时的连接清单；使用方在 bootApp 之后调一次。 */
    protected function snapshotConnections(): void
    {
        $this->connectionNamesBefore = array_keys(Db::getConnections());
    }

    /** 本夹具注册的拒连连接名（多个测试类共用时各自取名）。 */
    protected function deadConnectionName(): string
    {
        return 'zz_probe_dead';
    }

    /** 拒连现场使用的库名（不存在，且端口 1 无人监听）。 */
    protected function deadDatabaseName(): string
    {
        return 'zz_probe_unreachable';
    }

    /** @return string[] 被本现场覆盖过的连接名 */
    protected function overriddenConnections(): array
    {
        return $this->overridden;
    }

    /** @return array<string, array> 连接名 => 原始配置 */
    protected function originalConnectionConfigs(): array
    {
        return $this->originalConfigs;
    }

    /** @return string[] 进入本轮时的连接名清单 */
    protected function connectionNamesWhenSnapshotTaken(): array
    {
        return $this->connectionNamesBefore;
    }

    /**
     * 把**读路径真的会落到的那些连接**指向一个必然拒连的端口，让每一条腿真的读不到。
     *
     * 端口 1（tcpmux）在本机无人监听 → connect 立刻 ECONNREFUSED，不占时间。
     */
    protected function pointAtDeadConnection(): void
    {
        $this->originalConnection = Db::getDefaultConnection();

        $this->overridden = [];
        foreach (array_keys(Db::getConnections()) as $name) {
            if ((string) (Db::getConfig($name)['driver'] ?? '') !== 'pgsql') {
                continue;
            }

            $cfg = Db::getConfig($name);

            $this->originalConfigs[$name] = $cfg;
            $cfg['host'] = '127.0.0.1';
            $cfg['port'] = 1;
            $cfg['database'] = $this->deadDatabaseName();
            unset($cfg['pool'], $cfg['connection_name']);
            Db::addConnection($name, $cfg);
            $this->overridden[] = $name;
        }

        self::assertNotEmpty($this->overridden, '本机没有任何已注册的 pgsql 连接：读路径落点无从覆盖');

        if (!in_array($this->originalConnection, $this->overridden, true)) {
            // 默认连接没在覆盖清单里（读写分离关闭时读路径就是它）：单独注册一条拒连配置并切过去。
            $cfg = Db::getConfig($this->originalConnection);
            if (($cfg['driver'] ?? '') !== 'pgsql') {
                $this->originalConnection = null;
                self::markTestSkipped('默认连接不是 pgsql（' . var_export($cfg['driver'] ?? null, true) . '）：本用例断的是 pgsql 的 SQLSTATE 形状');
            }
            $cfg['host'] = '127.0.0.1';
            $cfg['port'] = 1;
            $cfg['database'] = $this->deadDatabaseName();
            unset($cfg['pool'], $cfg['connection_name']);

            Db::addConnection($this->deadConnectionName(), $cfg);
            $this->deadConnectionAdded = true;
            Db::setDefaultConnection($this->deadConnectionName());
        }

        // 前提自查：覆盖必须真的换掉了读路径，否则下面每一条「读不到」全是假现场。
        $threw = null;
        try {
            Db::select('SELECT 1 AS one');
        } catch (\Throwable $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, '前提不成立：pgsql 连接已全部指向拒连配置，Db::select 却照样读得到（'
            . 'default=' . Db::getDefaultConnection() . ' 覆盖=' . implode(',', $this->overridden)
            . ' 分离开关=' . var_export((bool) config('database.read_write_split', false), true) . '）');
        self::assertNotEmpty(Shard::sqlState($threw), '现场异常里没有 SQLSTATE，归因无从谈起：' . get_class($threw));
    }

    /**
     * 把现场动过的连接状态全部归位（tearDown 与收尾对照用例共用同一条路径）。
     */
    protected function restoreDeadDatabaseScene(): void
    {
        if ($this->originalConnection !== null) {
            Db::setDefaultConnection($this->originalConnection);
            $this->originalConnection = null;
        }
        if ($this->overridden !== []) {
            $this->restoreOverriddenConnections();
        }
        if ($this->deadConnectionAdded) {
            self::assertTrue(Db::removeConnection($this->deadConnectionName()), '本轮注册的拒连连接没被摘掉');
            $this->deadConnectionAdded = false;
        }
    }

    /**
     * 原样放回被覆盖的连接配置；默认连接排在最后（见 trait 头的顺序说明）。
     */
    private function restoreOverriddenConnections(): void
    {
        $names = $this->overridden;
        $default = Db::getDefaultConnection();
        if (in_array($default, $names, true)) {
            unset($names[array_search($default, $names, true)]);
            $names[] = $default;
        }

        foreach ($names as $name) {
            $original = $this->originalConfigs[$name] ?? null;
            self::assertNotNull($original, $name . ' 的原始配置没记下来：无法归位');
            Db::addConnection($name, (array) $original);
        }

        $this->overridden = [];
    }
}
