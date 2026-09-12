<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use app\http\concerns\ApiResponse;
use app\support\Shard;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Framework\Plugin\PluginManager;

/**
 * 仪表盘汇总（数字一眼看板）+ 系统信息。
 */
final class DashboardController extends Controller
{
    use ApiResponse;

    public function summary()
    {
        $users = (int) (Db::select('SELECT COUNT(*) AS c FROM kode_users')[0]['c'] ?? 0);
        $roles = (int) (Db::select('SELECT COUNT(*) AS c FROM kode_roles')[0]['c'] ?? 0);

        $notices = 0;
        try {
            if (Shard::enabled()) {
                $cfg = (array) config('sharding', []);
                for ($d = 0; $d < max(1, (int) ($cfg['db_count'] ?? 2)); $d++) {
                    $conn = sprintf('shard_%02d', $d);
                    for ($t = 0; $t < max(1, (int) ($cfg['table_shards'] ?? 2)); $t++) {
                        $table = sprintf('kode_notices_%02d', $t);
                        $notices += (int) (Db::connection($conn)->select("SELECT COUNT(*) AS c FROM {$table}")[0]['c'] ?? 0);
                    }
                }
            } else {
                $notices = (int) (Db::select('SELECT COUNT(*) AS c FROM kode_notices')[0]['c'] ?? 0);
            }
        } catch (\Throwable) {
            // 分片库故障不拖垮仪表盘。
        }

        $auditsToday = 0;
        foreach (Shard::tables('kode_audit_logs') as $table) {
            try {
                $auditsToday += (int) (Db::select(
                    "SELECT COUNT(*) AS c FROM {$table} WHERE created_at >= CURRENT_DATE"
                )[0]['c'] ?? 0);
            } catch (\Throwable) {
            }
        }

        try {
            $plugins = count(resolve(PluginManager::class)->all());
        } catch (\Throwable) {
            $plugins = 0;
        }

        return $this->ok([
            'users' => $users,
            'roles' => $roles,
            'notices' => $notices,
            'audits_today' => $auditsToday,
            'plugins' => $plugins,
            'system' => [
                'framework' => \Kode\Framework\Application::VERSION,
                'php' => PHP_VERSION,
                'db' => 'pgsql',
                'sharding' => Shard::enabled(),
            ],
        ]);
    }
}
