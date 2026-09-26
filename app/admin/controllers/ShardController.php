<?php

declare(strict_types=1);

namespace app\admin\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;
use app\concerns\ApiResponse;
use app\support\Shard;
use app\support\SysConfig;

/**
 * 分库分表配置管理：运行时查看/更新分片拓扑，变更后自动刷新路由缓存。
 *
 * 配置存储：kode_sys_configs 表，key = 'shard'，value = JSON。
 * 路由生效：Shard::config() 优先读 DB 热配置，fallback 到 config/sharding.php；
 *          进程内缓存有保鲜期（Shard::CACHE_TTL），多 worker 秒级收敛到同一拓扑。
 * 连接生效：bootstrap 只按 env 注册；本接口在当前 worker 现场补注册，其余 worker
 *          需 reload/重启才拿到新连接 —— 响应里按实测如实标出。
 *
 * 主机白名单（本控制器的核心约束）：db_hosts 会让服务端**带着应用自己的 DB 账号密码**
 * 主动去连对方（PG 握手明文送出 username，对方还可要求明文口令），并在对端执行
 * CREATE DATABASE。所以只接受运维在 .env 里声明过的主机（SHARD_DB_HOSTS ∪ DB_HOST），
 * 不接受请求现编的地址；主机串另过字符集校验，挡住 `127.0.0.1 port=5433` 这类 DSN 参数注入。
 */
final class ShardController extends Controller
{
    use ApiResponse;

    /** 建库用的一次性维护连接名（串行复用，用完即摘，见 withMaintenance） */
    private const MAINT_CONN = 'shard_maint';

    /**
     * 主机字面量合法字符集：域名 / IPv4，或 IPv6 字面量。
     *
     * 不收空格、等号、斜杠、换行：这些是 PG 连接串的关键字分隔与定界符，
     * 主机串最终会拼进 DSN。
     */
    private const HOST_PAT = '/^[A-Za-z0-9._\-]{1,253}$/';

    private const IPV6_PAT = '/^[0-9A-Fa-f:]{2,45}$/';

    /**
     * GET /api/shard/config — 当前分片配置（合并 DB 热配置 + 静态 fallback）。
     */
    public function config()
    {
        $cfg = Shard::config();

        // 注册与否问 Db::hasConnection()：Db::connection() 是惰性的，未知名字不抛错而是
        // 回落全局配置，拿它「探测」永远探测不出失败（曾经 connections_match 恒真）。
        $registeredConns = [];
        for ($i = 0; $i < $cfg['db_count']; $i++) {
            $name = sprintf('shard_%02d', $i);
            if (Db::hasConnection($name)) {
                $registeredConns[] = $name;
            }
        }

        return $this->ok([
            'config' => $cfg,
            'registered_connections' => $registeredConns,
            'connections_registered' => count($registeredConns),
            'connections_expected' => $cfg['db_count'],
            'connections_match' => count($registeredConns) === $cfg['db_count'],
            'total_shards' => $cfg['db_count'] * $cfg['table_shards'],
            // 片表相对基表的列缺口（只读）。放在这里的原因：片表少列时读侧聚合是
            // `catch (\Throwable) continue`，整片数据会从列表里静默消失，页面看不出任何异常
            // —— 结构问题必须有一处能看见它的地方（运行时 ensureTables 会顺带补齐）。
            'column_drift' => $cfg['enabled'] ? $this->columnDrift() : [],
            'source' => $this->configSource(),
            'allowed_hosts' => $this->allowedHosts(),
            'convergence_seconds' => Shard::CACHE_TTL,
        ]);
    }

    /**
     * 逐张分片基表汇报列缺口。
     *
     * @return list<array{base: string, shards: list<array{db: string, table: string, missing: list<string>}>}>
     */
    private function columnDrift(): array
    {
        $out = [];
        foreach (Shard::SHARDED_BASES as $base) {
            try {
                $rows = Shard::drift($base);
            } catch (\Throwable) {
                continue; // 分片库不可达：这里不猜缺口，交给 probeConnections 报连通性
            }
            if ($rows !== []) {
                $out[] = ['base' => $base, 'shards' => $rows];
            }
        }

        return $out;
    }

    /**
     * PUT /api/shard/config — 更新分片配置（存储到 kode_sys_configs）。
     *
     * 请求体：{ enabled: bool, db_count: int, table_shards: int, db_name: string, db_hosts: string }
     *
     * 注意：路由配置立即生效；连接只在当前 worker 生效，其余 worker 需 reload。
     */
    public function update()
    {
        $data = $this->validate(Request::all(), [
            'enabled'      => 'required|boolean',
            'db_count'     => 'required|integer|min:1|max:64',
            'table_shards' => 'required|integer|min:1|max:256',
            'db_name'      => 'required|min:4|max:64',
            'db_hosts'     => 'nullable|max:1024',
        ]);

        // db_name 模板 sprintf 后会被插值进 CREATE DATABASE DDL，必须白名单
        if (!$this->isValidDbName((string) $data['db_name'])) {
            return $this->fail('db_name 非法（仅字母数字下划线短横线与 %d 占位）', 400);
        }

        // 先校验再落库：越界主机一个都不许进 kode_sys_configs
        try {
            $hosts = $this->parseHosts((string) ($data['db_hosts'] ?? ''));
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 400);
        }

        $cfg = [
            'enabled'      => (bool) $data['enabled'],
            'db_count'     => (int) $data['db_count'],
            'table_shards' => (int) $data['table_shards'],
            'db_name'      => (string) $data['db_name'],
            // 回写归一化后的串：库里存的与实际连的必须是同一份
            'db_hosts'     => implode(',', $hosts),
        ];

        $json = json_encode($cfg, JSON_THROW_ON_ERROR);
        SysConfig::set('shard', $json);

        // 刷新 Shard 缓存，使路由立即使用新配置
        Shard::flushCache();

        // 尝试注册新增的分片连接（不重启也能立即生效）
        $registered = $this->registerConnections($cfg);

        // 自动创建缺失的分片库和表
        $dbCreated = $this->createShardDatabases($cfg);
        $tablesReady = $this->createShardTables($cfg);

        // 「注册了」不等于「连得上」：可达性只能实测
        $reachable = $this->probeConnections($cfg);
        $pending = $cfg['enabled'] && $reachable < $cfg['db_count'];

        return $this->ok([
            'updated' => true,
            'config' => $cfg,
            'connections_registered' => $registered,
            'connections_reachable' => $reachable,
            'databases_created' => $dbCreated,
            'tables_ready' => $tablesReady,
            // 前端按这个键决定 warning/success：没收敛到全可达就按警告口径提示
            'restart_required' => $pending,
            'reload_required' => true,
            'convergence_seconds' => Shard::CACHE_TTL,
            'message' => sprintf(
                '配置已更新：%d/%d 分片连接可达，新建 %d 库，%d 分片表就绪%s（路由约 %ds 内全 worker 收敛，新连接需 reload 生效）',
                $reachable,
                $cfg['db_count'],
                $dbCreated,
                $tablesReady,
                $pending ? '（部分连接不可达：确认分片库已建、当前账号可进该库）' : '',
                (int) ceil(Shard::CACHE_TTL)
            ),
        ]);
    }

    /**
     * GET /api/shard/status — 分片状态总览。
     */
    public function status()
    {
        $cfg = Shard::config();
        $tables = [];
        $connectionsOk = 0;

        if ($cfg['enabled']) {
            for ($d = 0; $d < $cfg['db_count']; $d++) {
                $connName = sprintf('shard_%02d', $d);
                $registered = Db::hasConnection($connName);
                $connOk = false;
                $tableList = [];
                // 未注册就不探：那种探法探到的是主库（配置回落），报告会是假的
                if ($registered) {
                    try {
                        $db = Db::connection($connName);
                        $db->select('SELECT 1');
                        $connOk = true;
                        $connectionsOk++;

                        for ($t = 0; $t < $cfg['table_shards']; $t++) {
                            $tableName = sprintf('%s_%02d', 'kode_notices', $t);
                            $exists = $db->select(
                                "SELECT to_regclass('public.{$tableName}') AS r"
                            );
                            $tableList[] = [
                                'name' => $tableName,
                                'exists' => ($exists[0]['r'] ?? null) !== null,
                            ];
                        }
                    } catch (\Throwable) {
                        // 连接不可达
                    }
                }

                $tables[] = [
                    'connection' => $connName,
                    'registered' => $registered,
                    'ok' => $connOk,
                    'tables' => $tableList,
                ];
            }
        }

        return $this->ok([
            'enabled' => $cfg['enabled'],
            'db_count' => $cfg['db_count'],
            'table_shards' => $cfg['table_shards'],
            'total_shards' => $cfg['db_count'] * $cfg['table_shards'],
            'connections_ok' => $connectionsOk,
            'details' => $tables,
        ]);
    }

    /**
     * 自动创建缺失的分片库（PostgreSQL 需连 postgres 维护库执行 CREATE DATABASE）。
     *
     * @return int 实际新建的库数（已存在的不计数）
     */
    private function createShardDatabases(array $cfg): int
    {
        if (!$cfg['enabled']) {
            return 0;
        }
        $created = 0;
        $hosts = $this->parseHosts((string) $cfg['db_hosts']);

        for ($i = 0; $i < $cfg['db_count']; $i++) {
            $dbName = sprintf($cfg['db_name'], $i);
            if (!$this->isValidDbName($dbName)) {
                continue;
            }
            $host = $hosts[$i] ?? $hosts[0] ?? $this->defaultHost();

            try {
                $fresh = $this->withMaintenance($host, static function (object $db) use ($dbName): bool {
                    if ($db->select('SELECT 1 FROM pg_database WHERE datname = ?', [$dbName]) !== []) {
                        return false;
                    }
                    // 库名已过白名单字符集（仅 [A-Za-z0-9_%-]），标识符位置无可注入面
                    $db->statement("CREATE DATABASE {$dbName}");
                    return true;
                });
                $created += $fresh ? 1 : 0;
            } catch (\Throwable $e) {
                // 权限不足/对端不可达：不阻断其余分片，最终由 probeConnections 如实汇报
                logger()->warning('分片库创建失败', ['db' => $dbName, 'reason' => $e->getMessage()]);
            }
        }

        return $created;
    }

    /**
     * 补齐分片表（配置变更后自动开通）。
     *
     * @return int 实测存在的分片表数。原先是「ensureTables 没抛错就把 db_count ×
     *             table_shards 全记成已创建」，而 ensureTables 对每个分片都是
     *             catch-and-skip，等于恒报满值。
     */
    private function createShardTables(array $cfg): int
    {
        if (!$cfg['enabled']) {
            return 0;
        }
        $ready = 0;

        foreach (Shard::SHARDED_BASES as $base) {
            Shard::resetEnsured();
            try {
                Shard::ensureTables($base);
            } catch (\Throwable) {
                // 连接未就绪：下面按实际存在数汇报
            }

            for ($d = 0; $d < $cfg['db_count']; $d++) {
                $conn = sprintf('shard_%02d', $d);
                if (!Db::hasConnection($conn)) {
                    continue;
                }
                try {
                    $db = Db::connection($conn);
                    for ($t = 0; $t < $cfg['table_shards']; $t++) {
                        $table = sprintf('%s_%02d', $base, $t);
                        $exists = $db->select("SELECT to_regclass('public.{$table}') AS r");
                        if (($exists[0]['r'] ?? null) !== null) {
                            $ready++;
                        }
                    }
                } catch (\Throwable) {
                    // 该片不可达：不计入 ready
                }
            }
        }

        return $ready;
    }

    /**
     * 尝试注册分片连接（当前 worker 内动态添加，无需重启）。
     *
     * @return int 已注册的分片连接数
     */
    private function registerConnections(array $cfg): int
    {
        $hosts = $this->parseHosts((string) $cfg['db_hosts']);
        for ($i = 0; $i < $cfg['db_count']; $i++) {
            $name = sprintf('shard_%02d', $i);
            if (Db::hasConnection($name)) {
                continue;
            }
            Db::addConnection($name, $this->connectionConfig(
                $cfg,
                $hosts[$i] ?? $hosts[0] ?? $this->defaultHost(),
                $i
            ));
        }

        $registered = 0;
        for ($i = 0; $i < $cfg['db_count']; $i++) {
            if (Db::hasConnection(sprintf('shard_%02d', $i))) {
                $registered++;
            }
        }

        return $registered;
    }

    /** 已注册连接的实测可达数（逐片一次 SELECT 1，只走管理端点，不在热路径） */
    private function probeConnections(array $cfg): int
    {
        if (!$cfg['enabled']) {
            return $cfg['db_count'];
        }
        $ok = 0;
        for ($i = 0; $i < $cfg['db_count']; $i++) {
            $name = sprintf('shard_%02d', $i);
            if (!Db::hasConnection($name)) {
                continue;
            }
            try {
                Db::connection($name)->select('SELECT 1');
                $ok++;
            } catch (\Throwable) {
                // 不可达：分片库还没建、或当前账号无权进该库
            }
        }

        return $ok;
    }

    /**
     * 开一条到维护库（postgres）的连接执行回调，**无论成败都摘掉**。
     *
     * 摘除不是洁癖：常驻进程里 `Db::addConnection()` 写的是一组 static，以前每次保存
     * 配置往 `$connections` 里塞一批 `shard_create_NN` 就再也没人回收（PDO 句柄 + 池常驻，
     * 配置里还带着库密码）。`Db::removeConnection()` 来自 kode/database v1.22.0，
     * 关池 + 断缓存连接一起做。
     */
    private function withMaintenance(string $host, callable $fn): mixed
    {
        Db::addConnection(
            self::MAINT_CONN,
            array_merge($this->connectionConfig([], $host, -1), ['database' => 'postgres'])
        );

        try {
            return $fn(Db::connection(self::MAINT_CONN));
        } finally {
            Db::removeConnection(self::MAINT_CONN);
        }
    }

    /**
     * 分片库连接配置：凭证一律沿用主库那份 config（不接受请求参数、不写死角色名），
     * 只有 host 与 database 按分片拓扑改。$index 为 -1 时表示维护连接（库名由调用方覆盖）。
     *
     * @return array<string, mixed>
     */
    private function connectionConfig(array $cfg, string $host, int $index): array
    {
        $main = (array) config('database.connections.pgsql', []);

        return [
            'driver' => (string) ($main['driver'] ?? 'pgsql'),
            'host' => $host,
            'port' => (int) ($main['port'] ?? 5432),
            'database' => $index < 0 ? '' : sprintf((string) ($cfg['db_name'] ?? 'kode_app_%02d'), $index),
            'username' => (string) ($main['username'] ?? ''),
            'password' => (string) ($main['password'] ?? ''),
            'charset' => (string) ($main['charset'] ?? 'utf8'),
            'prefix' => '',
        ];
    }

    /**
     * 拆解并校验 db_hosts：格式合法 + 必须在运维声明过的白名单里。
     *
     * 留空 = 全部落主库（与 config/sharding.php 口径一致）。
     *
     * @return list<string>
     * @throws \RuntimeException 消息可直接回给调用方
     */
    private function parseHosts(string $raw): array
    {
        $hosts = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $h): bool => $h !== ''
        )));
        if ($hosts === []) {
            return [];
        }

        $allowed = $this->allowedHosts();
        foreach ($hosts as $host) {
            if (preg_match(self::HOST_PAT, $host) !== 1 && preg_match(self::IPV6_PAT, $host) !== 1) {
                throw new \RuntimeException("db_hosts 含非法主机名：{$host}");
            }
            if (!in_array($host, $allowed, true)) {
                throw new \RuntimeException(
                    "db_hosts 含未授权主机：{$host}。分片库主机须由运维在 .env 的 SHARD_DB_HOSTS 中声明，"
                    . '当前允许：' . implode(',', $allowed)
                );
            }
        }

        return $hosts;
    }

    /**
     * 运维声明过的分片库主机 ∪ 主库主机。
     *
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        $declared = array_map('trim', explode(',', (string) env('SHARD_DB_HOSTS', '')));
        $declared[] = $this->defaultHost();

        return array_values(array_unique(array_filter($declared, static fn(string $h): bool => $h !== '')));
    }

    private function defaultHost(): string
    {
        return trim((string) env('DB_HOST', '127.0.0.1'));
    }

    /** 库名模板：既要能 sprintf 出各片库名，插值后进的又是 CREATE DATABASE 的标识符位置 */
    private function isValidDbName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_%\-]{4,64}$/', $name) === 1
            && preg_match('/%0?[0-9]*[du]/', $name) === 1;
    }

    /**
     * 检测当前配置来源。
     */
    private function configSource(): string
    {
        try {
            $raw = SysConfig::get('shard');
            if (is_string($raw) && $raw !== '') {
                return 'database';
            }
        } catch (\Throwable) {
            // ignore
        }
        return 'static';
    }
}
