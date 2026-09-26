<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;

/**
 * 水平分片路由（亿级架构）：库 + 表双维路由，错误自带片标识。
 *
 *  - 关闭（sharding.enabled=false）：全部走默认连接 + 基表名，零回归；
 *  - 开启：$h = crc32($key)，库序号取高 16 位 mod 库数，表序号取低位 mod 片数；
 *  - 同一归属键（owner/user_id）恒落同一库表（归属亲和），跨片查询走聚合；
 *  - select/execute 包装器：PDO 异常重抛时前缀 `[shard:库.表]`，结合 trace_id
 *    即可定位故障片（见 audit 日志 + 错误响应 trace_id）。
 *
 * 配置来源优先级：ShardConfig（DB 热配置）> config/sharding.php（静态）> 默认值。
 */
final class Shard
{
    /** @var array|null 配置缓存（单 worker 生命周期内复用） */
    private static ?array $configCache = null;

    /** @var float 缓存写入时刻（秒，微秒精度） */
    private static float $cachedAt = 0.0;

    /** @var bool 配置缓存是否有效（配置变更后置 false 刷新） */
    private static bool $cacheValid = true;

    /**
     * 进程内缓存的保鲜期（秒）。
     *
     * 热配置存在 DB（并经 redis 热缓存共享），可这份 cache 是每个 worker 自己的：
     * 永不过期就只有「处理这次 PUT 的那个 worker」立刻换拓扑，其余 worker 要等重启，
     * 同一个分片键会按新旧两套模数落到不同库表（静默数据分裂）。
     * 5s 是把收敛窗口压到秒级、又不让每次路由都去读 redis 的折中。
     */
    public const CACHE_TTL = 5.0;

    /**
     * 获取当前分片配置（支持热加载）。
     *
     * @return array{enabled: bool, db_count: int, table_shards: int, db_name: string, db_hosts: string}
     */
    public static function config(): array
    {
        if (!self::$cacheValid) {
            self::$configCache = null;
            self::$cacheValid = true;
        }
        if (self::$configCache !== null && (microtime(true) - self::$cachedAt) < self::CACHE_TTL) {
            return self::$configCache;
        }

        // 优先从 ShardConfig（DB 热配置）读取
        try {
            $raw = SysConfig::get('shard');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    self::$configCache = self::normalize(array_merge([
                        'enabled'      => false,
                        'db_count'     => 2,
                        'table_shards' => 2,
                        'db_name'      => 'kode_app_%02d',
                        'db_hosts'     => '',
                    ], $decoded));
                    self::$cachedAt = microtime(true);

                    return self::$configCache;
                }
            }
        } catch (\Throwable) {
            // DB 不可用，fallback
        }

        // fallback: config/sharding.php
        self::$configCache = self::normalize((array) config('sharding', []));
        self::$cachedAt = microtime(true);

        return self::$configCache;
    }

    /**
     * 归一化 + 取值域收敛：路由用的是 `crc32 % db_count` 与 `crc32 % table_shards`，
     * 任何一侧落成 0/null/非数字都会让每次分片写入抛 DivisionByZeroError（整条链路全红），
     * 而这份配置可能来自更早写入的 DB 行或写错的 env，读侧必须自己兜住。
     * 上限与管理端校验同口径（见 ShardController::update）：超上限按上限收敛，
     * 宁可少路由到几个片，也不能拿 0 去取模。
     *
     * @param array<string, mixed> $cfg
     * @return array{enabled: bool, db_count: int, table_shards: int, db_name: string, db_hosts: string}
     */
    private static function normalize(array $cfg): array
    {
        $out = [
            'enabled'      => (bool) ($cfg['enabled'] ?? false),
            'db_count'     => max(1, min(64, (int) ($cfg['db_count'] ?? 2))),
            'table_shards' => max(1, min(256, (int) ($cfg['table_shards'] ?? 2))),
            'db_name'      => (string) ($cfg['db_name'] ?? 'kode_app_%02d'),
            'db_hosts'     => (string) ($cfg['db_hosts'] ?? ''),
        ];
        // 名字模板要交给 sprintf() 插库序号：空串、或压根没有占位符都会抛 ValueError
        // （`kode_app` 这种漏写 `%02d` 的配置会让每次建库都红）。
        if (trim($out['db_name']) === '' || preg_match('/%0?\d*[du]/', $out['db_name']) !== 1) {
            $out['db_name'] = 'kode_app_%02d';
        }

        return $out;
    }


    /** 清除配置缓存（配置变更后调用） */
    public static function flushCache(): void
    {
        self::$cacheValid = false;
        self::$configCache = null;
    }

    /** 重置已建表标记（配置变更/新连接注册后调用，强制重新检查） */
    public static function resetEnsured(): void
    {
        self::$ensured = [];
        self::$baseColumns = [];
    }

    public static function enabled(): bool
    {
        return self::config()['enabled'];
    }

    /** @return int 库分片数 */
    public static function dbCount(): int
    {
        return self::config()['db_count'];
    }

    /** @return int 表分片数 */
    public static function tableShards(): int
    {
        return self::config()['table_shards'];
    }

    /**
     * 计算分片索引（基于分片键）。
     *
     * 默认使用 table_shards 作为分片模数；可传 db_count 做库级路由。
     */
    public static function index(string $key, ?int $mod = null): int
    {
        $mod ??= self::tableShards();
        return abs(crc32($key)) % $mod;
    }

    public static function table(string $base, string $key): string
    {
        return sprintf('%s_%02d', $base, self::index($key));
    }

    /** @return list<string> 全部分片表名（聚合查询用） */
    public static function tables(string $base): array
    {
        $tables = [];
        for ($i = 0; $i < self::tableShards(); $i++) {
            $tables[] = sprintf('%s_%02d', $base, $i);
        }

        return $tables;
    }

    /**
     * 读侧的片清单：这张基表在当前拓扑下要扫的全部片。
     *
     * 每片是 `[连接名|null, 表名, 库序号, 表序号]`：后两位是**坐标**，
     * 对外 eid 三元组（`HashId::encIds()`）与 `locate()` 的解码口径要靠它对齐，
     * 所以在派发口里原样传给回调，而不是让调用方去反解 `shard_02` / `_01` 这种名字。
     *
     * `$crossDb=true` = 库×表双维（真分库分表的业务表，如 notices）；
     * `false` = 只在默认连接上按表分片（如 kode_audit_logs，片表都在主库里）。
     * 两处循环此前各写一遍 `sprintf('shard_%02d', $d)`，模数来源也就各自挑一份
     * —— 片数只从 Shard::config() 取这条口径收敛到这里。
     *
     * `$baseWhenDisabled` 回答的是另一件事：**开关拨到关闭时，写侧落在哪张表**。
     * notices 落基表（`locate()` 的未分片分支），而 kode_audit_logs 恒落 `_00.._NN`
     * （`AuditTrail::tableFor()` 走 `Shard::table()`，与开关无关），所以它的读侧
     * 关掉开关也必须继续枚举片表 —— 传 false。这里必须跟着写侧走：
     * 两边各自决定一次，就会出现「写进片表、从基表读」的永久隐身。
     *
     * @return list<array{0: string|null, 1: string, 2: int, 3: int}>
     */
    public static function readUnits(string $base, bool $crossDb = true, bool $baseWhenDisabled = true): array
    {
        if (!self::enabled()) {
            return $baseWhenDisabled
                ? [[null, $base, 0, 0]]
                : self::tableUnits($base);
        }
        $out = [];
        $dbs = $crossDb ? self::dbCount() : 1;
        for ($d = 0; $d < $dbs; $d++) {
            $conn = $crossDb ? sprintf('shard_%02d', $d) : null;
            foreach (self::tables($base) as $t => $table) {
                $out[] = [$conn, $table, $d, $t];
            }
        }

        return $out;
    }

    /**
     * 默认连接上的片表清单（不查开关）：`[null, 表名, 0, 表序号]`。
     *
     * @return list<array{0: string|null, 1: string, 2: int, 3: int}>
     */
    private static function tableUnits(string $base): array
    {
        $out = [];
        foreach (self::tables($base) as $t => $table) {
            $out[] = [null, $table, 0, $t];
        }

        return $out;
    }

    /** 对外汇报的失败片上限（再多只是把响应撑大，前若干片足以定位拓扑问题）。 */
    public const MAX_REPORTED_SHARDS = 20;

    /**
     * 跨片读取的唯一派发口：逐片执行 $fn，**读不出来的片如实带回**，绝不吃成「没有数据」。
     *
     * 旧写法是每片一个 `try { ... } catch (\Throwable) { continue; }`：缺片、库不可达、
     * 片表少列都只让列表少一截、total 跟着偏小，页面上与「这一片真的没有数据」一模一样
     * （AGENTS：禁止把结构错误渲染成没有数据）。
     * 另一件事：未注册的连接**根本不该碰** —— `Db::connection()` 对未知名字不抛错，
     * 而是回落全局配置，于是拿着分片表名去查**主库**；主库残留过同名片表时（本机就有，
     * 是历史上「未注册分支在主库建片」留下的）读到的是别处的数据，还会把 total 乘重复。
     * 写侧有 `assertRegistered()` 挡着，读侧此前没有 —— 实测一枚探针行就能混进公告列表。
     *
     * @template T
     *
     * @param  list<array{0: string|null, 1: string, 2?: int, 3?: int}> $units 片清单（来自 readUnits()，不是请求参数）
     * @param  callable(string|null, string, int, int): T               $fn    单片读取，返回值按片序收集
     * @return array{results: list<T>, unreadable: list<array{shard: string, reason: string, code: string}>}
     */
    public static function scan(array $units, callable $fn): array
    {
        $results = [];
        $unreadable = [];
        $perDb = max(1, self::tableShards());
        foreach ($units as $i => $unit) {
            $conn = $unit[0] ?? null;
            $conn = ($conn === null || $conn === '') ? null : (string) $conn;
            $table = (string) ($unit[1] ?? '');
            $tag = ($conn ?? 'default') . '.' . $table;

            if ($conn !== null && !Db::hasConnection($conn)) {
                $unreadable[] = [
                    'shard'  => $tag,
                    'reason' => '分片连接未注册（当前 worker 的拓扑与热配置不一致，需 php kode reload 或对齐 SHARD_DB_COUNT）',
                    'code'   => '',
                ];
                continue;
            }
            try {
                // 坐标缺省时按片序推回（readUnits 恒带上这两维；手搓的清单也要能派发）
                $db = (int) ($unit[2] ?? intdiv((int) $i, $perDb));
                $t = (int) ($unit[3] ?? ((int) $i % $perDb));
                $results[] = $fn($conn, $table, $db, $t);
            } catch (\Throwable $e) {
                $unreadable[] = [
                    'shard'  => $tag,
                    'reason' => self::failureReason($e),
                    'code'   => self::sqlState($e),
                ];
            }
        }

        return ['results' => $results, 'unreadable' => $unreadable];
    }

    /**
     * 整理成出参口径：截断 + 一条「还有 N 片」的尾巴。
     *
     * @param  list<array{shard: string, reason: string, code: string}> $unreadable
     * @return list<array{shard: string, reason: string, code: string}>
     */
    public static function unreadableKey(array $unreadable): array
    {
        $out = array_slice(array_values($unreadable), 0, self::MAX_REPORTED_SHARDS);
        $rest = count($unreadable) - count($out);
        if ($rest > 0) {
            $out[] = [
                'shard'  => '…',
                'reason' => "另有 {$rest} 片未能读取，逐片连通性见 GET /api/shard/config",
                'code'   => '',
            ];
        }

        return $out;
    }

    /**
     * 失败原因只给结论口径：PDO 原文里带着分片库主机名与端口，那是内网拓扑，
     * 不该跟着列表响应发给任何一枚有效 token（诊断走 /api/shard/config）。
     *
     * 公开是为了让读侧之外的派发口（如按表清理）复用同一份分类，不再各写一遍 match。
     */
    public static function failureReason(\Throwable $e): string
    {
        $state = self::sqlState($e);

        return match (true) {
            str_starts_with($state, '08')  => '分片库不可达',
            $state === '42P01'             => '片表不存在（跨库基表跑 php kode shard:init 建片，其余补迁移）',
            $state === '42703'             => '片表缺列（跑 php kode shard:init 补列）',
            $state === '42501'             => '当前库账号无权访问该片',
            default                        => '该片操作失败',
        };
    }

    /**
     * 沿异常链找 SQLSTATE（kode/database 可能把 PDOException 包一层）。
     *
     * 公开是为了让分片之外的读口（如监控的数据库诊断）复用同一份取码，不再各写一遍链遍历。
     *
     * 取值只能优先 `errorInfo[0]`，不能只信 `getCode()`：pdo_pgsql 把**所有**错误的驱动码
     * 回吐成 7，于是连接类故障的 `getCode()` 是 int 7 而不是 `'08006'`（实测），
     * 上面那条 `str_starts_with($state, '08')` 就永远不命中 —— 「分片库不可达」这句结论
     * 从来没出现在响应里，缺口一律被归成通用的「该片操作失败」。
     * 包内同款判据（`PdoConnection::isConnectionFailure()`）读的也是 `errorInfo[0]`；
     * 语句类错误两者同值，所以 `getCode()` 只当回落档留着。
     */
    public static function sqlState(\Throwable $e): string
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof \PDOException) {
                $info = (array) ($cur->errorInfo ?? []);

                return (string) ($info[0] ?? $cur->getCode());
            }
        }

        return '';
    }

    /**
     * 分片键 → [连接名, 表名]。关闭时返回 [默认连接, 基表名]。
     *
     * @return array{0: string|null, 1: string} [connection|null, table]
     */
    public static function route(string $base, string $key): array
    {
        if (!self::enabled()) {
            return [null, $base];
        }

        $h = abs(crc32($key));
        $dbIndex = (int) (($h >> 16) % self::dbCount());
        $tableIndex = (int) ($h % self::tableShards());
        $conn = sprintf('shard_%02d', $dbIndex);

        self::assertRegistered($conn);

        return [$conn, sprintf('%s_%02d', $base, $tableIndex)];
    }

    /**
     * 确认本 worker 真的注册了这条连接。
     *
     * `Db::connection()` 是惰性的、`Db::getConfig()` 对未知名字直接回落到全局配置，
     * 所以名字打错或「只改了热配置、这个 worker 还没建连」时不会抛错，而是**带着分片表名
     * 连到主库**：数据静默落到主库的 kode_notices_00 上，路由看起来一切正常。
     * 这里把这种错配变成一次明确的失败。
     */
    public static function assertRegistered(?string $conn): void
    {
        if ($conn !== null && !Db::hasConnection($conn)) {
            throw new \RuntimeException(
                "分片连接 {$conn} 未注册：sharding 已开启但当前进程没有这条连接，"
                . "请配好 SHARD_DB_HOSTS/SHARD_DB_COUNT 后执行 php kode shard:init 并重启（或 reload）"
            );
        }
    }

    /**
     * 在目标分片上执行回调（连接缺失/失败时异常带片标识）。
     *
     * @template T
     * @param callable(?string, string): T $fn
     * @return T
     */
    public static function onShard(string $base, string $key, callable $fn): mixed
    {
        [$conn, $table] = self::route($base, $key);
        try {
            return $fn($conn, $table);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                '[shard:' . ($conn ?? 'default') . ".{$table}] " . $e->getMessage(), 0, $e
            );
        }
    }

    /** @var array<string, true> worker 内已确保建表的基名 */
    private static array $ensured = [];

    /**
     * @var array<string, list<array{num: int, name: string, decl: string, dflt: string, notnull: bool, pk: bool}>>
     *      基表列定义缓存（按基名，含空结果）
     */
    private static array $baseColumns = [];

    /** @var list<array{db: string, table: string, added: list<string>}> 最近一次 ensureTables 的补列记录 */
    private static array $alignReport = [];

    /**
     * 参与分片的基表（唯一清单）。
     *
     * `shard:init` 与 `/api/shard/config` 原先各写了一份 `['kode_notices']` 字面量，
     * 于是「按需开通只建了 notices 的片、诊断面却按别的口径找」这类漂移没人看得见。
     */
    public const SHARDED_BASES = ['kode_notices'];

    /**
     * 能接 `nextval(...)` 默认的整型（`format_type` 口径）。
     *
     * 基表里 default 是 nextval 的列，片表必须换成 IDENTITY 而不是照抄默认值
     * （序列不跨库）；类型不在这三份里就说不通，`tableDdlFor()` 直接抛而不是猜。
     */
    private const SERIAL_TYPES = ['smallint', 'integer', 'bigint'];

    /**
     * 按需开通分片表（后台开启分片后自动转移，无需预建）。
     *
     * 对**已存在**的片表还会补列、补索引：见 `alignColumns()` 的说明——基表加列而片表没加，
     * 是 install()/upgrade()/迁移三条路都不负责的空档。索引同理：`kode_notices` 基表上那条
     * `(type, status)` 在四张片表上都不存在，读侧每片一次顺序扫。
     *
     * 建的是「分片库里的片」：调用方必须是**读写两侧都按 `shard_NN` 路由**的那类基表
     * （现在是 `SHARDED_BASES` 那一份清单）。`kode_audit_logs` 不是 —— 它的写侧用 `Db::`、
     * 读侧枚举的是默认连接的 `_00.._NN`，给它建片等于往分片库里建一张谁都不看的表。
     * 实测本机 `kode_app_00` 里没有 `kode_audit_logs`。
     */
    public static function ensureTables(string $base): void
    {
        if (!self::enabled() || isset(self::$ensured[$base])) {
            return;
        }
        self::$ensured[$base] = true;
        self::$alignReport = [];

        for ($d = 0; $d < self::dbCount(); $d++) {
            $conn = sprintf('shard_%02d', $d);
            // 未注册就跳过：Db::connection() 对未知名字回落全局配置，
            // 那样等于在**主库**里建 kode_notices_00，主库凭空多出一张分片表还无人报错。
            if (!Db::hasConnection($conn)) {
                continue;
            }
            for ($t = 0; $t < self::tableShards(); $t++) {
                $table = sprintf('%s_%02d', $base, $t);
                try {
                    $db = Db::connection($conn);
                    $exists = $db->select(
                        "SELECT to_regclass('public.{$table}') AS r"
                    );
                    if (($exists[0]['r'] ?? null) === null) {
                        foreach (self::ddlFor($base, $table) as $stmt) {
                            $db->statement($stmt);
                        }
                        continue;
                    }
                    $added = self::alignColumns($conn, $table, $base);
                    if ($added !== []) {
                        self::$alignReport[] = ['db' => $conn, 'table' => $table, 'added' => $added];
                    }
                    // 补列在补索引**之前**：索引可能建在刚补出来的那列上，反了会 42703 打断这一轮。
                    // 语句恒带 IF NOT EXISTS，所以每张片表每次都能安全重跑。
                    foreach (self::indexDdlFor($base, $table) as $stmt) {
                        $db->statement($stmt);
                    }
                } catch (\Throwable) {
                    // 连接不可达、无权 ALTER、基表读不到（`ddlFor()` 会抛）时静默跳过：
                    // 按需建表不能让一次结构问题打断业务请求。缺口由 `drift()` 与
                    // `php kode shard:init` 如实汇报（那条命令不 catch，这里吞掉的东西在那里必须露出来）。
                }
            }
        }
    }

    /** 最近一次 ensureTables 实际补了哪些列（诊断/命令汇报用，请求路径不写日志）。 */
    public static function alignReport(): array
    {
        return self::$alignReport;
    }

    /**
     * 片表相对基表的列缺口（只读：不建表、不补列）。
     *
     * 存在的意义是「让结构错误不被渲染成没有数据」：片表少列时读侧抛 42703，
     * 而 `NoticeController::index()` 那类聚合是 `catch (\Throwable) continue`，
     * 于是整片数据从列表里静默消失、total 跟着偏小 —— 页面上和「真的一条没有」长得一模一样。
     *
     * @return list<array{db: string, table: string, missing: list<string>}>
     */
    public static function drift(string $base): array
    {
        $want = self::baseColumns($base);
        if ($want === []) {
            return [];
        }
        $out = [];
        for ($d = 0; $d < self::dbCount(); $d++) {
            $conn = sprintf('shard_%02d', $d);
            if (!Db::hasConnection($conn)) {
                continue;
            }
            for ($t = 0; $t < self::tableShards(); $t++) {
                $table = sprintf('%s_%02d', $base, $t);
                try {
                    $have = self::tableColumns($conn, $table);
                } catch (\Throwable) {
                    continue;
                }
                if ($have === null) {
                    continue; // 表还没有：那是建表的活，不算漂移
                }
                $missing = array_values(array_diff(array_column($want, 'name'), $have));
                if ($missing !== []) {
                    $out[] = ['db' => $conn, 'table' => $table, 'missing' => $missing];
                }
            }
        }

        return $out;
    }

    /**
     * 把一张已存在的片表补齐到基表的列集合（只加列：不改类型、不删列）。
     *
     * 为什么要补：基表加列有三条路——迁移（只跑主库，迁移内切连接会打断迁移事务，
     * 见 `2026_09_23_000041_notice_type` 的注释）、插件 install()（只在装插件那一刻跑）、
     * 插件 upgrade()（只在点升级那一刻跑）。三条都不碰**已经存在**的片表，
     * 于是「基表有 type、片表没有」长期没人负责。本机实测：kode_app_00/01 的
     * 四张 kode_notices_* 至今少 `type`，公告列表因此一片都读不出（读侧 42703 被吞）、
     * 新建直接 500。
     *
     * 判据只认主库基表的真实列集合（`baseColumns()`），所以建表那份口径再漂一次，
     * 运行时也会自己长回来。
     *
     * @return list<string> 实际补上的列名
     */
    private static function alignColumns(string $conn, string $table, string $base): array
    {
        $want = self::baseColumns($base);
        if ($want === []) {
            // 读不到基表（不存在或主库不可达）就没有判据，一个字节都不动
            return [];
        }
        $have = self::tableColumns($conn, $table) ?? [];
        $db = Db::connection($conn);
        $added = [];
        foreach ($want as $col) {
            if (in_array($col['name'], $have, true)) {
                continue;
            }
            // 列名与类型串最终进 DDL。它们来自自家目录（不是请求参数），但形状不认识的一律不搬
            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/i', $col['name']) !== 1
                || !self::isSafeColumnDecl($col['decl'])) {
                continue;
            }
            $fragment = self::quoteIdent($table) . ' ADD COLUMN IF NOT EXISTS '
                . self::quoteIdent($col['name']) . ' ' . $col['decl'];
            if (self::isSafeDefaultExpr($col['dflt'])) {
                $fragment .= ' DEFAULT ' . $col['dflt'];
            }
            // 不带 NOT NULL：非空表上 `ADD COLUMN x NOT NULL`（无默认值）必失败，
            // 而这里要修的是「列不存在把读写打成 500」，不是约束级复刻基表。
            try {
                $db->statement('ALTER TABLE ' . $fragment);
                $added[] = $col['name'];
            } catch (\Throwable) {
                // 无权/并发已补：留给下一次（resetEnsured 后会再试）
            }
        }

        return $added;
    }

    /**
     * 默认值表达式能否安全进 DDL。
     *
     * 串原样搬自 `pg_get_expr`（形如 `'announcement'::character varying`、`now()`）。
     * 只认这一小撮字面量与函数调用字符：出现分号、引号不闭合或 `--` 就当不认识。
     * 建表（`tableDdlFor()`）认不出就**抛**，补列（`alignColumns()`）认不出就**不搬默认值** ——
     * 前者在建一张没人用的错表，后者只是少一个默认值，读写仍走得通。
     */
    private static function isSafeDefaultExpr(string $dflt): bool
    {
        return $dflt !== '' && preg_match("/^[\w'().,:*+\/ -]{1,256}$/", $dflt) === 1
            && substr_count($dflt, "'") % 2 === 0 && !str_contains($dflt, '--');
    }

    /**
     * 列类型串能否安全进 DDL：`format_type` 的产出只会是标识符、数字、括号、逗号与空格
     * （`numeric(10,2)`、`timestamp with time zone[]` 都在里面），分号/引号/破折号一律不算。
     */
    private static function isSafeColumnDecl(string $decl): bool
    {
        return preg_match('/^[A-Za-z0-9_(),\[\] ]{1,128}$/', $decl) === 1;
    }

    /**
     * 基表列定义（读主库目录，含空结果缓存：读不到就别每张片表再问一遍）。
     *
     * 这是「一张片表该长什么样」的**唯一判据**：建片（`ddlFor()`）与补列（`alignColumns()`）
     * 都从这里读，代码里不留第二份表定义。
     *
     * @return list<array{num: int, name: string, decl: string, dflt: string, notnull: bool, pk: bool}>
     */
    private static function baseColumns(string $base): array
    {
        if (isset(self::$baseColumns[$base])) {
            return self::$baseColumns[$base];
        }
        $rows = [];
        try {
            $rows = Db::select(
                'SELECT a.attnum AS num, a.attname AS name,
                        pg_catalog.format_type(a.atttypid, a.atttypmod) AS decl,
                        CASE WHEN a.attnotnull THEN 1 ELSE 0 END AS notnull,
                        CASE WHEN EXISTS (SELECT 1 FROM pg_catalog.pg_index i
                                           WHERE i.indrelid = a.attrelid AND i.indisprimary
                                             AND a.attnum = ANY(i.indkey)) THEN 1 ELSE 0 END AS pk,
                        COALESCE((SELECT pg_catalog.pg_get_expr(d.adbin, d.adrelid)
                                    FROM pg_catalog.pg_attrdef d
                                   WHERE d.adrelid = a.attrelid AND d.adnum = a.attnum), \'\') AS dflt
                   FROM pg_catalog.pg_attribute a
                  WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped
                  ORDER BY a.attnum',
                ['public.' . $base]
            );
        } catch (\Throwable) {
            $rows = [];
        }
        $cols = [];
        foreach ($rows as $row) {
            $cols[] = [
                'num' => (int) ($row['num'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'decl' => (string) ($row['decl'] ?? ''),
                'dflt' => (string) ($row['dflt'] ?? ''),
                'notnull' => ((int) ($row['notnull'] ?? 0)) === 1,
                'pk' => ((int) ($row['pk'] ?? 0)) === 1,
            ];
        }
        self::$baseColumns[$base] = $cols;

        return $cols;
    }

    /**
     * 基表上「片表应当照抄」的索引。
     *
     * 只取普通 btree、非唯一、非表达式、非部分索引的那几条：
     *  - 唯一索引**故意不抄**：片表的唯一约束只管单片，跨片的重复它挡不住（分片键才是归属判定），
     *    抄过去却会把基表合法的写入在片上拒掉；
     *  - 表达式/部分索引（`indexprs`/`indpred` 非空）与排他约束的定义串不在这份机制的重建范围里，
     *    跳过而不是猜一份。
     *
     * 目录查询**不吞异常**：这里出错等于给每张新片少建一条索引，而症状是「查询变慢」，
     * 排障方向会被彻底带偏（PG18 的 `pg_index` 就没有 `indisexpression` 这一列，
     * 当初写成 catch (\Throwable) return [] 时，本机四条索引一条都没建出来，测试才是唯一的声音）。
     *
     * @return list<array{name: string, cols: list<string>}>
     */
    private static function baseIndexes(string $base): array
    {
        $byNum = [];
        foreach (self::baseColumns($base) as $col) {
            $byNum[$col['num']] = $col['name'];
        }
        if ($byNum === []) {
            return []; // 基表读不到：没有判据，交给 ddlFor() 报
        }
        $rows = Db::select(
            "SELECT c.relname AS name, ix.indkey::text AS key
               FROM pg_catalog.pg_index ix
               JOIN pg_catalog.pg_class c ON c.oid = ix.indexrelid
               JOIN pg_catalog.pg_am am ON am.oid = c.relam
              WHERE ix.indrelid = to_regclass(?)
                AND am.amname = 'btree'
                AND NOT ix.indisprimary AND NOT ix.indisunique AND NOT ix.indisexclusion
                AND ix.indexprs IS NULL AND ix.indpred IS NULL
              ORDER BY c.relname",
            ['public.' . $base]
        );
        $out = [];
        foreach ($rows as $row) {
            $names = [];
            foreach (preg_split('/\s+/', trim((string) ($row['key'] ?? ''))) ?: [] as $attnum) {
                $names[] = $byNum[(int) $attnum] ?? null;
            }
            if ($names === [] || in_array(null, $names, true)) {
                continue; // 含已删除列或目录里认不出的序号：不搬
            }
            $out[] = ['name' => (string) ($row['name'] ?? ''), 'cols' => $names];
        }

        return $out;
    }

    /**
     * 某张表的列名集合。
     *
     * @return list<string>|null null = 表不存在（to_regclass 为空）
     */
    private static function tableColumns(string $conn, string $table): ?array
    {
        $reg = Db::connection($conn)->select('SELECT to_regclass(?) AS r', ['public.' . $table]);
        if (($reg[0]['r'] ?? null) === null) {
            return null;
        }
        $rows = Db::connection($conn)->select(
            'SELECT attname FROM pg_catalog.pg_attribute
              WHERE attrelid = to_regclass(?) AND attnum > 0 AND NOT attisdropped
              ORDER BY attnum',
            ['public.' . $table]
        );

        return array_values(array_map(static fn(array $r): string => (string) ($r['attname'] ?? ''), $rows));
    }

    /** 标识符引号（库名/表名列名都可能带大写或保留字，一律双引号 + 转义内嵌双引号）。 */
    private static function quoteIdent(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    /**
     * 分片表 DDL（唯一口径）：一条 CREATE TABLE 加若干条 CREATE INDEX，按落地顺序返回。
     *
     * 运行时按需建表（`ensureTables()`）与 `php kode shard:init` 都走这里。
     * 返回**清单**而不是一条语句：索引只能由 CREATE INDEX 建，而框架的 `Db::statement()`
     * 走 prepare（pgsql 的预处理不接受多语句），拿分号拼成一条会当场报错。
     *
     * 形状恒从**主库基表的真实目录**派生（`baseColumns()` / `baseIndexes()`），代码里不留
     * 第二份表定义。这里原先写死了一份 `kode_notices` 的 schema，而它与基表的双向偏离都是实测过的：
     * 基表 `title`/`body`/`created_at`/`updated_at` 可空、且两个时间列没有默认值，副本写的是
     * `NOT NULL` + `DEFAULT NOW()` —— 于是「基表合法的写入」到片上会被拒，同一条不带时间的
     * INSERT 还会因落点不同得出不同结果。
     *
     * 另一支 `CREATE TABLE … (LIKE {$base} INCLUDING ALL)` 是**必然失败**的：分片库里没有基表，
     * 42P01 被 `ensureTables()` 的 catch 吞掉，于是除 `kode_notices` 之外没有任何基表建得出片
     * （实测在 kode_app_00 里执行 `LIKE zz_like_probe INCLUDING ALL` 直接报关系不存在）。
     *
     * @return list<string>
     * @throws \RuntimeException 基表读不到、或列定义无法安全表达 —— 宁可失败也不建一张错形状的片
     */
    public static function ddlFor(string $base, string $table): array
    {
        $out = [self::tableDdlFor($base, $table)];
        foreach (self::indexDdlFor($base, $table) as $stmt) {
            $out[] = $stmt;
        }

        return $out;
    }

    /** 建表那一条：列、默认值、非空与主键全部照抄基表目录。 */
    private static function tableDdlFor(string $base, string $table): string
    {
        $cols = self::baseColumns($base);
        if ($cols === []) {
            throw new \RuntimeException(
                "基表 {$base} 在主库读不到列定义（表不存在或主库不可达），拒绝建片表 {$table}"
            );
        }
        $parts = [];
        $pk = [];
        foreach ($cols as $col) {
            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/i', $col['name']) !== 1
                || !self::isSafeColumnDecl($col['decl'])) {
                throw new \RuntimeException("基表 {$base} 的列「{$col['name']}」形状不认识，无法搬进 {$table}");
            }
            $serial = str_starts_with($col['dflt'], 'nextval(');
            if ($serial && !in_array($col['decl'], self::SERIAL_TYPES, true)) {
                throw new \RuntimeException("基表 {$base} 的列「{$col['name']}」默认值来自序列，类型却不是整型");
            }
            if (!$serial && !self::isSafeDefaultExpr($col['dflt']) && $col['dflt'] !== '') {
                throw new \RuntimeException("基表 {$base} 的列「{$col['name']}」默认值无法安全搬进 {$table}");
            }
            $fragment = self::quoteIdent($col['name']) . ' ' . $col['decl'];
            if ($serial) {
                // 序列不跨库：主库那条 kode_notices_id_seq 在分片库里根本不存在，直抄 nextval
                // 会让建表当场失败。IDENTITY 让每张片表拿到属于自己的那条序列；
                // BY DEFAULT（而不是 ALWAYS）：迁移/回填要能显式带 id 插入。
                $fragment .= ' GENERATED BY DEFAULT AS IDENTITY';
            }
            if ($col['notnull']) {
                $fragment .= ' NOT NULL';
            }
            if (!$serial && $col['dflt'] !== '') {
                $fragment .= ' DEFAULT ' . $col['dflt'];
            }
            $parts[] = $fragment;
            if ($col['pk']) {
                $pk[] = $col['name'];
            }
        }
        if ($pk !== []) {
            $parts[] = 'PRIMARY KEY (' . implode(
                ', ',
                array_map(static fn(string $c): string => self::quoteIdent($c), $pk)
            ) . ')';
        }

        return 'CREATE TABLE IF NOT EXISTS ' . self::quoteIdent($table) . " (\n    "
            . implode(",\n    ", $parts) . "\n)";
    }

    /**
     * 二级索引那几条（与建表同源：都从基表目录派生）。
     *
     * 恒带 IF NOT EXISTS，所以**已存在的片表也能照跑** —— `ensureTables()` 正是靠这一点
     * 把本机四张 kode_notices_* 缺的那条 `(type, status)` 补回来。
     *
     * @return list<string>
     */
    private static function indexDdlFor(string $base, string $table): array
    {
        $out = [];
        foreach (self::baseIndexes($base) as $idx) {
            $out[] = 'CREATE INDEX IF NOT EXISTS ' . self::quoteIdent(self::shardIndexName($base, $table, $idx['name']))
                . ' ON ' . self::quoteIdent($table) . ' ('
                . implode(', ', array_map(static fn(string $c): string => self::quoteIdent($c), $idx['cols']))
                . ')';
        }

        return $out;
    }

    /**
     * 片表上的索引名：把基表名换成片表名（PG 的索引名在 schema 内全局唯一，照抄必撞）。
     *
     * 收不满标识符形状/长度时退成「原名前缀 + 表名与原名哈希」——必须**确定性**，
     * 否则每次 ensureTables 都会建一张新名字的索引。
     */
    private static function shardIndexName(string $base, string $table, string $name): string
    {
        $pos = strpos($name, $base);
        $derived = $pos === false
            ? $name . '_' . substr($table, strlen($base) + 1)
            : substr_replace($name, $table, $pos, strlen($base));
        if (strlen($derived) <= 63 && preg_match('/^[a-z_][a-z0-9_]{0,62}$/i', $derived) === 1) {
            return $derived;
        }

        return substr((string) preg_replace('/[^A-Za-z0-9_]/', '', $name), 0, 46)
            . '_' . substr(md5($table . '|' . $name), 0, 10);
    }

    /**
     * 取分片库连接包装器（$conn 为 null 时返回默认 Db 门面，两者 select/insert/update/delete 同形）。
     */
    public static function db(?string $conn): object
    {
        if ($conn === null) {
            return new class {
                public function select(string $sql, array $b = []): array
                {
                    return \Kode\Database\Db\Db::select($sql, $b);
                }
                public function insert(string $sql, array $b = []): int|string
                {
                    return \Kode\Database\Db\Db::insert($sql, $b);
                }
                public function update(string $sql, array $b = []): int
                {
                    return \Kode\Database\Db\Db::update($sql, $b);
                }
                public function delete(string $sql, array $b = []): int
                {
                    return \Kode\Database\Db\Db::delete($sql, $b);
                }
            };
        }

        return Db::connection($conn);
    }
}
