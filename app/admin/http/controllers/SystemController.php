<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use app\http\concerns\ApiResponse;
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
        $queue = (array) config('queue', []);
        $groups = [
            [
                'group' => 'app',
                'title' => '应用',
                'items' => [
                    ['key' => 'app.name', 'value' => (string) config('app.name', ''), 'remark' => '应用名'],
                    ['key' => 'app.debug', 'value' => config('app.debug', false) ? 'true' : 'false', 'remark' => '调试模式（生产必须 false）'],
                    ['key' => 'app.timezone', 'value' => (string) config('app.timezone', ''), 'remark' => '时区'],
                    ['key' => 'APP_URL', 'value' => (string) env('APP_URL', ''), 'remark' => '外部地址'],
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
                    ['key' => 'CACHE_DRIVER', 'value' => (string) env('CACHE_DRIVER', ''), 'remark' => '缓存驱动'],
                    ['key' => 'QUEUE_DRIVER', 'value' => (string) ($queue['default'] ?? env('QUEUE_DRIVER', '')), 'remark' => '队列驱动'],
                    ['key' => 'LOG_LEVEL', 'value' => (string) env('LOG_LEVEL', ''), 'remark' => '日志级别'],
                ],
            ],
        ];

        return $this->ok($groups);
    }

    /** 热缓存清理（menus:/config: 前缀，SCAN 增量删；localStorage 前端缓存不受影响）。 */
    public function cacheClear()
    {
        $menus = \app\support\HotCache::clear('menus:');
        $configs = \app\support\HotCache::clear('config:');
        \app\support\SysLog::info('system', '清理热缓存', ['menus' => $menus, 'configs' => $configs]);

        return $this->ok(['menus' => $menus, 'configs' => $configs]);
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

    public function backup()    {
        $tables = $this->query('tables');
        $list = $tables === null || $tables === ''
            ? null
            : array_values(array_filter(explode(',', (string) $tables)));

        try {
            $file = \app\support\DbBackup::run($list);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (\Throwable) {
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
