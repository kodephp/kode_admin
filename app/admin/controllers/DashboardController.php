<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\concerns\ApiResponse;
use app\support\AccountScope;
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
        $payload = AccountScope::payload();
        if (!AccountScope::isAdmin($payload)) {
            // 全站库存（用户/角色/插件计数、版本与分片开关）只对超管出；其他人只拿自己的今日请求数。
            // 口径是「缺键」而不是「给 0」——0 会被读成「这台系统没有用户」，那是另一种泄漏。
            // 前端按 scope 隐藏对应卡片。
            // 空用户名不能当过滤值：`username = ''` 数的是匿名行，等于把别人的流量算进「我的今天」。
            $who = AccountScope::usernameOf($payload);
            [$auditsToday, $unreadable] = $who === ''
                ? [0, []]
                : $this->countAuditsAcrossShards(
                    'SELECT COUNT(*) AS c FROM %s WHERE username = ? AND created_at >= CURRENT_DATE',
                    [$who]
                );

            return $this->ok([
                'scope' => 'self',
                'audits_today' => $auditsToday,
            ], 200, ['unreadable_shards' => $unreadable]);
        }

        $users = (int) (Db::select('SELECT COUNT(*) AS c FROM kode_users')[0]['c'] ?? 0);
        $roles = (int) (Db::select('SELECT COUNT(*) AS c FROM kode_roles')[0]['c'] ?? 0);

        // 片数与连接名都只由 Shard::readUnits() 决定：此前这里自己乘了一遍 dbCount()×tableShards()
        // 并直接 Db::connection($conn) —— 未注册的名字不报错而是回落主库配置，于是把主库残留的
        // 同名片表数了进来；整块 try/catch 又把读不动的片吃成一个「看着正常」的总数。
        $notices = 0;
        $scan = Shard::scan(Shard::readUnits('kode_notices'), static fn(?string $c, string $t): int => (int) (
            Shard::db($c)->select("SELECT COUNT(*) AS c FROM {$t}")[0]['c'] ?? 0
        ));
        foreach ($scan['results'] as $piece) {
            $notices += (int) $piece;
        }

        [$auditsToday, $unreadable] = $this->countAuditsAcrossShards(
            'SELECT COUNT(*) AS c FROM %s WHERE created_at >= CURRENT_DATE'
        );
        $unreadable = array_merge($unreadable, Shard::unreadableKey($scan['unreadable']));

        try {
            $plugins = count(resolve(PluginManager::class)->all());
        } catch (\Throwable) {
            $plugins = 0;
        }

        return $this->ok([
            'scope' => 'all',
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
        ], 200, ['unreadable_shards' => $unreadable]);
    }

    /**
     * 今日审计量：按 audit 的片清单逐片 COUNT(*)，读不动的片如实带回。
     *
     * @param  string      $sqlTpl 含一个 `%s`（片表名）的单片计数语句，不接受用户输入
     * @param  list<mixed> $params
     * @return array{0: int, 1: list<array{shard: string, reason: string, code: string}>}
     */
    private function countAuditsAcrossShards(string $sqlTpl, array $params = []): array
    {
        $scan = Shard::scan(
            Shard::readUnits('kode_audit_logs', false, false),
            static fn(?string $c, string $t): int => (int) (
                Shard::db($c)->select(sprintf($sqlTpl, $t), $params)[0]['c'] ?? 0
            )
        );
        $total = 0;
        foreach ($scan['results'] as $piece) {
            $total += (int) $piece;
        }

        return [$total, Shard::unreadableKey($scan['unreadable'])];
    }
}
