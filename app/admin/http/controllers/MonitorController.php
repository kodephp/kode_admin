<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use Kode\Framework\Http\Controller;
use Kode\Database\Db\Db;
use app\http\concerns\ApiResponse;

/**
 * 服务器监控：负载/CPU/内存/磁盘/PHP/应用信息（只读快照）。
 */
final class MonitorController extends Controller
{
    use ApiResponse;

    /** 分片总览：各基表分片分布 + 行数（拓扑变更需改环境并重启，此处只读）。 */
    public function shards()
    {
        $cfg = (array) config('sharding', []);
        $out = [
            'topology' => [
                'enabled' => (bool) ($cfg['enabled'] ?? false),
                'db_count' => (int) ($cfg['db_count'] ?? 0),
                'table_shards' => (int) ($cfg['table_shards'] ?? 0),
                'db_name' => (string) ($cfg['db_name'] ?? ''),
                'read_write_split' => (bool) config('database.read_write_split', false),
            ],
            'bases' => [],
        ];
        foreach (['kode_audit_logs', 'kode_notices'] as $base) {
            $tables = [];
            $total = 0;
            foreach (\app\support\Shard::tables($base) as $table) {
                $count = null;
                try {
                    $count = (int) (Db::select("SELECT COUNT(*) AS c FROM {$table}")[0]['c'] ?? 0);
                    $total += $count;
                } catch (\Throwable) {
                }
                $tables[] = ['table' => $table, 'rows' => $count];
            }
            // 未分片时也展示基表本身。
            if ($tables === []) {
                try {
                    $total = (int) (Db::select("SELECT COUNT(*) AS c FROM {$base}")[0]['c'] ?? 0);
                } catch (\Throwable) {
                }
                $tables[] = ['table' => $base, 'rows' => $total];
            }
            $out['bases'][] = ['base' => $base, 'total' => $total, 'tables' => $tables];
        }

        return $this->ok($out);
    }

    public function overview()
    {        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0];
        $cpu = $this->cpuCount();
        $memTotal = $this->memTotal();
        $memAvail = $this->memAvailable();
        $diskTotal = @disk_total_space(base_path()) ?: 0;
        $diskFree = @disk_free_space(base_path()) ?: 0;

        return $this->ok([
            'load' => ['1m' => round((float) $load[0], 2), '5m' => round((float) $load[1], 2), '15m' => round((float) $load[2], 2)],
            'cpu' => ['cores' => $cpu],
            'memory' => [
                'total' => $memTotal,
                'available' => $memAvail,
                'used' => max(0, $memTotal - $memAvail),
                'usage' => $memTotal > 0 ? round((1 - $memAvail / $memTotal) * 100, 1) : 0,
            ],
            'disk' => [
                'total' => (int) $diskTotal,
                'free' => (int) $diskFree,
                'used' => (int) ($diskTotal - $diskFree),
                'usage' => $diskTotal > 0 ? round((1 - $diskFree / $diskTotal) * 100, 1) : 0,
            ],
            'app' => [
                'php' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'framework' => \Kode\Framework\Application::VERSION,
                'time' => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    private function cpuCount(): int
    {
        if (is_file('/proc/cpuinfo')) {
            $n = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor');
            if ($n > 0) {
                return $n;
            }
        }
        $out = (string) @shell_exec('sysctl -n hw.ncpu 2>/dev/null');

        return max(1, (int) trim($out));
    }

    private function memTotal(): int
    {
        if (is_file('/proc/meminfo')) {
            foreach (explode("\n", (string) @file_get_contents('/proc/meminfo')) as $line) {
                if (str_starts_with($line, 'MemTotal:')) {
                    return (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT) * 1024;
                }
            }
        }
        $out = (string) @shell_exec('sysctl -n hw.memsize 2>/dev/null');

        return max(0, (int) trim($out));
    }

    private function memAvailable(): int
    {
        if (is_file('/proc/meminfo')) {
            foreach (explode("\n", (string) @file_get_contents('/proc/meminfo')) as $line) {
                if (str_starts_with($line, 'MemAvailable:')) {
                    return (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT) * 1024;
                }
            }
        }
        // macOS：vm_stat 空闲页估算。
        $out = (string) @shell_exec('vm_stat 2>/dev/null');
        if ($out !== '' && preg_match('/page size of (\d+) bytes/', $out, $m)) {
            $page = (int) $m[1];
            $free = 0;
            foreach (explode("\n", $out) as $line) {
                if (preg_match('/^(Pages free|Pages inactive|Pages speculative):\s*(\d+)/', trim($line), $mm)) {
                    $free += (int) $mm[2];
                }
            }

            return $free * $page;
        }

        return 0;
    }
}
