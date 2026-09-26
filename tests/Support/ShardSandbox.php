<?php

declare(strict_types=1);

namespace app\tests\Support;

use app\support\HotCache;
use app\support\Shard;
use app\support\SysConfig;
use Kode\Database\Db\Db;

/**
 * 分片热配置的沙箱：拨拓扑 → 用 → 恢复原状，几个测试文件共用一份判据。
 *
 * 为什么抽出来：`forceTopology()` 与 `restoreShardConfig()` 此前在
 * `ShardReadDegradedTest`、`ShardColumnAlignTest`、`NoticeSingleTableTest` 里各抄了一份，
 * 而它们唯一的作用是「别把开发库留在伪造的拓扑上」。多抄一份时最容易漏的恰恰是恢复那条：
 * 本机 `kode_sys_configs` 原本**没有** `shard` 这一行，写进去没删干净，后面的用例就按
 * 错模数路由，红的原因和真因隔了两个文件。
 *
 * 基准取「本文件第一次 captureShardBaseline() 读到的值」，不是每次 setUp 各读一遍 ——
 * 后者会把上一条用例没恢复干净的残留当成原状，恢复对照当场变成恒真。
 *
 * 用法：`setUp()` 里 `bootApp()` 之后调 `captureShardBaseline()`；`tearDown()` 里调
 * `restoreShardConfig()`；文件最后一条用例调 `assertSandboxLeftClean()`。
 */
trait ShardSandbox
{
    /** 本文件开始前的 `shard` 行原文（null = 原本没这一行），进程内只认第一次读到的值 */
    private static ?string $pristineShardRaw = null;

    private static bool $pristineShardCaptured = false;

    /** 本文件开始前的分片连接注册数（恢复对照用它，别拿测试自己 addConnection 后的值） */
    private static int $pristineRegisteredShards = 0;

    /** 本次要恢复成的原文；由 captureShardBaseline() 从 pristine 复制 */
    private ?string $shardRawBefore = null;

    /** 读 `shard` 热配置原值并记录进程内基准。必须在 bootApp() 之后调用。 */
    protected function captureShardBaseline(): void
    {
        $rows = Db::select('SELECT cvalue FROM kode_sys_configs WHERE ckey = ? LIMIT 1', ['shard']);
        $now = $rows === [] ? null : (string) ($rows[0]['cvalue'] ?? '');

        if (!self::$pristineShardCaptured) {
            self::$pristineShardRaw = $now;
            self::$pristineRegisteredShards = $this->registeredShardCount();
            self::$pristineShardCaptured = true;
        }
        $this->shardRawBefore = self::$pristineShardRaw;
    }

    /**
     * 把热配置拨到指定拓扑，并断言真的落地。
     *
     * 走生产那条路（SysConfig + 失效热缓存），不反射改私有静态：要测的就是真判定链。
     * db_count 只拨到**已注册连接数**以内：连接是 `app/bootstrap.php` 按静态配置注册的，
     * 拨到外面 = 造出「worker 拓扑没收敛」的现场（那是 ShardReadDegradedTest 的射程）。
     */
    protected function forceTopology(bool $enabled, int $dbCount, int $tableShards): void
    {
        SysConfig::set('shard', (string) json_encode([
            'enabled'      => $enabled,
            'db_count'     => $dbCount,
            'table_shards' => max(1, $tableShards),
            'db_name'      => 'kode_app_%02d',
            'db_hosts'     => '',
        ]));
        HotCache::forget('config:shard');
        Shard::flushCache();
        Shard::resetEnsured();

        self::assertSame($enabled, Shard::enabled(), '分片开关没落地：下面的断言在测另一条分支');
        self::assertSame($dbCount, Shard::dbCount(), '热配置的 db_count 没落地');
        self::assertSame(max(1, $tableShards), Shard::tableShards(), '热配置的 table_shards 没落地');
    }

    /** 原本没有 `shard` 行就删回去，原本有就写回原值；两条都要失效热缓存。 */
    protected function restoreShardConfig(): void
    {
        try {
            if ($this->shardRawBefore === null) {
                Db::delete('DELETE FROM kode_sys_configs WHERE ckey = ?', ['shard']);
            } else {
                Db::update(
                    'UPDATE kode_sys_configs SET cvalue = ?, updated_at = NOW() WHERE ckey = ?',
                    [$this->shardRawBefore, 'shard']
                );
            }
        } catch (\Throwable) {
            // 恢复失败由 assertSandboxLeftClean() 报出来，这里不重复抛、也不盖住用例自己的失败原因
        }
        HotCache::forget('config:shard');
        Shard::flushCache();
        Shard::resetEnsured();
    }

    /** 收尾对照：热配置原文与已注册连接数都得回到本文件开始前。 */
    protected function assertSandboxLeftClean(): void
    {
        $now = Db::select('SELECT cvalue FROM kode_sys_configs WHERE ckey = ? LIMIT 1', ['shard']);
        self::assertSame(
            self::$pristineShardRaw,
            $now === [] ? null : (string) ($now[0]['cvalue'] ?? ''),
            'shard 热配置的原文没恢复：开发库会按本用例的拓扑路由'
        );
        self::assertSame(
            self::$pristineRegisteredShards,
            $this->registeredShardCount(),
            '已注册连接数与本文件开始前不一致：测试往进程里塞了连接没回收'
        );
    }

    /** 已注册的 `shard_NN` 连接数（连接由 bootstrap 按静态配置注册，测试不该自己加）。 */
    protected function registeredShardCount(): int
    {
        $n = 0;
        while ($n < 64 && Db::hasConnection(sprintf('shard_%02d', $n))) {
            $n++;
        }

        return $n;
    }

    /** 第一个「配置里可以存在、但当前 worker 没注册」的连接序号。 */
    protected function firstUnregisteredShardIndex(): ?int
    {
        for ($i = 0; $i < 64; $i++) {
            if (!Db::hasConnection(sprintf('shard_%02d', $i))) {
                return $i;
            }
        }

        return null;
    }

    /**
     * 默认连接上真实存在的片表序号（`_00` 起连续，返回最大存在序号 +1）。
     *
     * 只探到 16 号：拨到 16 片以上没有额外信息量，而每片一次 to_regclass 也要钱。
     *
     * @return list<int> 存在的片表序号
     */
    protected function presentShardIndexes(string $base): array
    {
        $live = [];
        for ($i = 0; $i < 16; $i++) {
            $table = sprintf('%s_%02d', $base, $i);
            $rows = Db::select("SELECT to_regclass('public.{$table}') AS r");
            if (($rows[0]['r'] ?? null) !== null) {
                $live[] = $i;
            }
        }

        return $live;
    }
}
