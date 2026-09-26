<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\concerns\ApiResponse;
use Kode\Framework\Http\Controller;
use Kode\Framework\Packaging\Packager;

/**
 * 系统运维：数据库备份下载。
 *
 * pg_dump 参数全部取服务端环境变量/配置，用户只能选“备份哪些表”（白名单校验），
 * 命令行永不拼接用户输入。备份为 SQL 文本下载，恢复用 psql -f。
 */
final class SystemController extends Controller
{
    use ApiResponse;

    /**
     * 框架配置只读总览（config/*.php + 环境变量，脱敏；改配置需改 .env 并重启，
     * 故只读展示，禁止运行时写入）。
     */
    public function configs()
    {
        $mask = static function (string $v): string {
            return $v === '' ? '' : '******';
        };
        $db = (array) config('database', []);
        $sharding = (array) config('sharding', []);
        $groups = [
            [
                'group' => 'app',
                'title' => '应用',
                'items' => [
                    ['key' => 'app.name', 'value' => (string) config('app.name', ''), 'remark' => '应用名'],
                    ['key' => 'app.debug', 'value' => config('app.debug', false) ? 'true' : 'false', 'remark' => '调试模式（生产必须 false）'],
                    ['key' => 'app.timezone', 'value' => (string) config('app.timezone', ''), 'remark' => '时区'],
                    // 注：本系统无 APP_URL 消费方（绝对地址由前端基地址与 CORS origins 决定），故不展示
                ],
            ],
            [
                'group' => 'database',
                'title' => '数据库',
                'items' => [
                    ['key' => 'DB_CONNECTION', 'value' => (string) env('DB_CONNECTION', ''), 'remark' => '主连接'],
                    ['key' => 'DB_HOST', 'value' => (string) env('DB_HOST', ''), 'remark' => '主机'],
                    ['key' => 'DB_DATABASE', 'value' => (string) env('DB_DATABASE', ''), 'remark' => '库名'],
                    ['key' => 'DB_USERNAME', 'value' => (string) env('DB_USERNAME', ''), 'remark' => '属主即连接账号'],
                    ['key' => 'DB_PASSWORD', 'value' => $mask((string) env('DB_PASSWORD', '')), 'remark' => '已脱敏'],
                    ['key' => 'DB_READ_WRITE_SPLIT', 'value' => !empty($db['read_write_split']) ? 'true' : 'false', 'remark' => '读写分离'],
                ],
            ],
            [
                'group' => 'sharding',
                'title' => '分库分表（亿级开关，改拓扑需改环境并重启）',
                'items' => [
                    ['key' => 'SHARD_ENABLED', 'value' => !empty($sharding['enabled']) ? 'true' : 'false', 'remark' => '分片总闸'],
                    ['key' => 'SHARD_DB_COUNT', 'value' => (string) ($sharding['db_count'] ?? ''), 'remark' => '库分片数（2 的幂次）'],
                    ['key' => 'SHARD_TABLE_COUNT', 'value' => (string) ($sharding['table_shards'] ?? ''), 'remark' => '库内表分片数'],
                    ['key' => 'SHARD_DB_NAME', 'value' => (string) ($sharding['db_name'] ?? ''), 'remark' => '分片库名模板'],
                ],
            ],
            [
                'group' => 'security',
                'title' => '安全',
                'items' => [
                    ['key' => 'JWT_TTL', 'value' => (string) config('jwt.guards.api.ttl', ''), 'remark' => 'Token 有效期（秒）'],
                    ['key' => 'JWT_SECRET', 'value' => $mask((string) env('JWT_SECRET', '')), 'remark' => '已脱敏'],
                    ['key' => 'HASHID_SALT', 'value' => $mask((string) env('HASHID_SALT', '')), 'remark' => '已脱敏'],
                    ['key' => 'CORS_ENABLED', 'value' => (string) env('CORS_ENABLED', ''), 'remark' => '跨域开关'],
                ],
            ],
            [
                'group' => 'infra',
                'title' => '基础设施',
                'items' => [
                    // 值一律取生效配置（env 缺省时回落 config 默认，展示原始 env 会把"未设置"误显示成空驱动）
                    ['key' => 'CACHE_DRIVER', 'value' => (string) config('cache.default', ''), 'remark' => '缓存驱动'],
                    ['key' => 'QUEUE_CONNECTION', 'value' => (string) config('queue.default', ''), 'remark' => '队列驱动'],
                    ['key' => 'LOG_LEVEL', 'value' => (string) config('logging.level', ''), 'remark' => '日志级别'],
                ],
            ],
        ];

        return $this->ok($groups);
    }

    /**
     * 热缓存清理（按 HotCache::NAMESPACES 全量，SCAN 增量删；localStorage 前端缓存不受影响）。
     *
     * 出参逐命名空间出行、键就是 NAMESPACES 那份原样：此前这里把 `{前缀: 删除数}` 整份当
     * `data` 发出去，前端读的是 `res.menus` / `res.configs` —— 那两个键从来不存在，
     * 提示条渲染成「热缓存已清理（菜单 ，配置 ）」，而第三个命名空间 `plugin:` 的数
     * 在页面上永远看不见。前端不写死名字表，新增前缀才不会「清理面有它、页面上看不见」。
     *
     * `complete: false` 时 `total` 只是「清成功的那些命名空间」之和，不是全站口径 ——
     * 连不上缓存服务的那几行是 null（见 HotCache::clear()），只有这个标志能把
     * 「一个都没清成」和「清完了、本来没键可清」分开（两者都会报 total 0）。
     */
    public function cacheClear()
    {
        $cleared = \app\support\HotCache::clearAll();
        // 「哪些行没清成」只由 incomplete() 判一次；这里按它剔行，不再自己 filter 一遍谓词。
        $incomplete = \app\support\HotCache::incomplete($cleared);
        $done = array_diff_key($cleared, array_flip($incomplete));

        \app\support\SysLog::info('system', '清理热缓存', $cleared);

        return $this->ok([
            'namespaces' => $cleared,
            'total' => array_sum($done),
            'complete' => $incomplete === [],
        ]);
    }

    /**
     * 打包诊断（只读）：引擎/产物/平台/前置检查/二进制档位/文件统计。
     *
     * 与 CLI `kode pack:info --json` 输出一致，供前端系统页展示打包就绪状态。
     */
    public function packInfo()
    {
        $packer = Packager::driver();
        $config = $packer::config();
        $root = $packer::projectRoot();
        $paths = $packer::collect();

        $fileCount = count($paths);
        $totalBytes = 0;
        foreach ($paths as $rel) {
            $abs = $root . '/' . $rel;
            if (is_file($abs)) {
                $totalBytes += (int) filesize($abs);
            }
        }

        return $this->ok([
            'engine' => [
                'driver' => $packer,
                'projectRoot' => $root,
                'buildDir' => $packer::buildDir(),
            ],
            'artifacts' => [
                'pharFilename' => $config['phar_filename'] ?? 'kode.phar',
                'binFilename' => $config['bin_filename'] ?? 'kode.bin',
                'signatureAlgorithm' => $config['signature_algorithm'] ?? \Phar::SHA256,
                'memoryLimitMb' => $config['memory_limit_mb'] ?? 512,
            ],
            'platform' => [
                'os' => PHP_OS,
                'osFamily' => PHP_OS_FAMILY,
                'arch' => php_uname('m'),
                'php' => PHP_VERSION,
                'phpVersion' => substr(PHP_VERSION, 0, 3),
                'pharReadonly' => ini_get('phar.readonly') === '1',
                'openssl' => extension_loaded('openssl'),
                'phar' => extension_loaded('phar'),
                'zip' => extension_loaded('zip'),
            ],
            'precheck' => [
                'issues' => $packer::precheck(),
                'pass' => $packer::precheck() === [],
            ],
            'binary' => [
                'platformIssues' => $packer::binaryPlatformIssues(),
                'ready' => $packer::binaryPlatformIssues() === [],
            ],
            'files' => [
                'count' => $fileCount,
                'totalBytes' => $totalBytes,
                'totalHuman' => $packer::humanBytes($totalBytes),
            ],
        ]);
    }

    /**
     * 系统健康检查：聚合数据库连接、Redis、磁盘、进程、框架版本等状态。
     */
    public function health()
    {
        $dbStatus = 'unknown';
        $dbLatency = 0;
        try {
            $start = hrtime(true);
            \Kode\Database\Db\Db::select('SELECT 1');
            $dbLatency = (int) ((hrtime(true) - $start) / 1_000_000);
            $dbStatus = 'connected';
        } catch (\Throwable) {
            $dbStatus = 'disconnected';
        }

        $redisStatus = 'unknown';
        $redisLatency = 0;
        try {
            $redis = \app\support\HotCache::connection();
            if ($redis === null) {
                // 扩展未装 / redis 未起：热缓存自动穿透回源，业务不受影响
                $redisStatus = 'unavailable';
            } else {
                $start = hrtime(true);
                $redis->ping();
                $redisLatency = (int) ((hrtime(true) - $start) / 1_000_000);
                $redisStatus = 'connected';
            }
        } catch (\Throwable) {
            $redisStatus = 'disconnected';
        }

        $disk = ['total' => 0, 'free' => 0, 'used' => 0, 'percent' => 0];
        $total = @disk_total_space(dirname(__DIR__, 3));
        $free = @disk_free_space(dirname(__DIR__, 3));
        if ($total > 0 && $free !== false) {
            $disk = [
                'total' => $total,
                'free' => $free,
                'used' => $total - $free,
                'percent' => round(($total - $free) / $total * 100, 2),
            ];
        }

        // 进程存活只有一份判据 = 框架的槽位表（与 /api/process/status 同源）。
        // 此前这里读的是 /tmp/kode-daemon.pid —— 那个名字从来没人写（守护进程按槽位写
        // kode-worker-<name>:<slot>.pid），所以健康卡恒报 not_running；而它从
        // /proc/{pid}/stat 正则抓的第一个数字是**父进程号**，不是运行时长。
        $alive = [];
        $stale = [];
        $earliest = null;
        $slotCount = 0;
        foreach (\Kode\Framework\Facades\Process::slotStates() as $slot) {
            ++$slotCount;
            $label = $slot['name'] . ':' . $slot['slot'];
            if ($slot['alive']) {
                $alive[$label] = $slot['pid'];
                if ($slot['started_at'] !== null) {
                    $earliest = $earliest === null ? (int) $slot['started_at'] : min($earliest, (int) $slot['started_at']);
                }
            } else {
                // 只点名「文件在、指着死人」——那是被 SIGKILL 之类留下的现场。
                // pid 为 null 是这一路从没启动过，跟 stale 不是一回事（混成一个
                // not_running 等于把「没启动过」和「启动过又没了」说成同一件事）。
                if ($slot['pid'] !== null) {
                    $stale[] = $label;
                }
            }
        }

        $frameworkVersion = (string) env('APP_VERSION', 'unknown');

        return $this->ok([
            'status' => $dbStatus === 'connected' ? 'healthy' : 'degraded',
            'timestamp' => date('Y-m-d H:i:s'),
            'framework' => [
                'version' => $frameworkVersion,
                'php' => PHP_VERSION,
                'os' => PHP_OS_FAMILY,
                'memory' => [
                    'used' => memory_get_usage(true),
                    'peak' => memory_get_peak_usage(true),
                    'limit' => ini_get('memory_limit'),
                ],
            ],
            'database' => [
                'status' => $dbStatus,
                'latency_ms' => $dbLatency,
            ],
            'redis' => [
                'status' => $redisStatus,
                'latency_ms' => $redisLatency,
            ],
            'disk' => $disk,
            'process' => [
                'status' => $alive === [] ? 'not_running' : 'running',
                'slot_count' => $slotCount,
                'alive' => $alive,
                'stale' => $stale,
                'start_time' => $earliest === null ? null : date('Y-m-d H:i:s', $earliest),
            ],
        ]);
    }

    /**
     * 一键修复：只做「无副作用、可逆、幂等」的自愈动作。
     *
     * 修复项：
     *  - 热缓存：clearAll() 全部已登记前缀（与 cacheClear 同口径）
     *  - 磁盘告警、数据库、Redis：只诊断不动手（删日志/碰外部服务的副作用都不是「可逆」）
     *
     * pid 文件不在修复项里，理由见下面第 1 条。
     *
     * 每次修复动作都记录 SysLog，便于审计。返回 executed / skipped 两个数组。
     */
    public function repair()
    {
        $executed = [];
        $skipped = [];

        // 不清理 pid 文件。此前第一项是「发现失效 PID 文件就 @unlink」，两条都不成立：
        // 判据读的是幻影路径（见 health()），而 kode/process 的 Daemon **默认** pid 文件名
        // 恰好就是那个名字 —— 这一刀可能落在别人的进程上。清理责任属于写侧（守护进程
        // 优雅退出时自己删），读侧删文件会把一次「正在启动、还没落 pid」的正常拉起抹平。

        // 清理热缓存（安全幂等）。走 HotCache::clearAll() 而不是手写前缀：
        // 只清 menus: 与 config: 时，插件的 plugin:config: 和 plugin:status: 两个前缀
        // 仍按 TTL 读旧值，「一键修复」后行为照旧，而报告上写着已清理。
        $cleared = \app\support\HotCache::clearAll();
        $incomplete = \app\support\HotCache::incomplete($cleared);

        if ($incomplete === []) {
            $parts = [];
            foreach ($cleared as $ns => $n) {
                $parts[] = $ns . $n;
            }
            $executed[] = '清理热缓存（' . implode(' / ', $parts) . '）';
        } else {
            // 一个键都没清动时不许进 executed：此前这里渲染的是「清理热缓存（menus:0 / …）」，
            // 而 0 来自「服务连不上」，于是失败被列进「已修复」。
            $skipped[] = '热缓存未能清理（' . implode('、', $incomplete) . ' 连不上缓存服务）';
        }

        // 数据库与 Redis 不做自动操作（可能是外部服务，自动操作无意义）；
        // 磁盘也不做自动清理（避免误删日志/上传）。

        \app\support\SysLog::info('system', '一键修复', [
            'executed' => $executed,
            'skipped' => $skipped,
        ]);

        return $this->ok(['executed' => $executed, 'skipped' => $skipped]);
    }

    /** 数据库备份下载。 */
    public function backup()
    {
        $tables = $this->query('tables');
        $list = $tables === null || $tables === ''
            ? null
            : array_values(array_filter(explode(',', (string) $tables)));

        try {
            // 下载即删的场景故意不落 storage/backups：那里的文件会留在列表里被重复下载
            $file = \app\support\DbBackup::run($list);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            \app\support\SysLog::error('system', '数据库备份下载失败：' . $e->getMessage());

            return $this->fail('备份失败（检查 pg_dump 可用性与数据库权限）', 500);
        }

        $body = (string) file_get_contents($file);
        @unlink($file);
        \app\support\SysLog::info('system', '数据库备份下载', ['bytes' => strlen($body)]);

        return \Kode\Framework\Http\Resp::make('', 200, [])
            ->withHeader('Content-Type', 'application/sql')
            ->withHeader('Content-Disposition', 'attachment; filename="kode_backup_' . date('Ymd_His') . '.sql"')
            ->withHeader('Content-Length', (string) strlen($body))
            ->withBody(\Kode\Http\Psr7\Stream::create($body));
    }
}
