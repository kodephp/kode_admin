<?php

declare(strict_types=1);

namespace app\support;

use Kode\Context\Context;
use Kode\Database\Db\Db;
use Kode\Framework\Scheduling\ScheduleDispatcher;

/**
 * 多租户定时任务同步执行器。
 *
 * 架构设计：
 *   - 租户表（kode_tenants）存储所有活跃租户；
 *   - 执行器遍历所有租户，逐个注入 tenant() 上下文后派发定时任务；
 *   - 每租户执行状态记录到 kode_tenant_schedule_runs 表（持久化审计）；
 *   - 支持租户级跳过（按租户名或 ID 黑名单）。
 *
 * 与框架调度器关系：
 *   - ScheduleDispatcher 负责 #[Cron] 发现 + 单次执行；
 *   - 本类负责「跨租户迭代」这一层——框架未内置的多租户派发。
 */
final class TenantScheduleRunner
{
    private const TENANT_CONTEXT_KEY = 'tenant.id';

    /**
     * 获取所有活跃租户列表。
     *
     * @return list<array{name: string, id: string, status: string}>
     */
    public static function tenants(): array
    {
        $rows = Db::select(
            "SELECT name, id, status FROM kode_tenants WHERE status IN ('active', 'enabled') ORDER BY created_at ASC"
        );

        $tenants = [];
        foreach ($rows as $row) {
            $tenants[] = [
                'name' => (string) ($row['name'] ?? ''),
                'id' => (string) ($row['id'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
            ];
        }

        return $tenants;
    }

    /**
     * 为所有租户执行指定定时任务。
     *
     * @param string $taskName #[Cron] 任务名
     * @param array<string> $skip 跳过的租户名/ID 列表
     * @return array{ok: bool, task: string, total: int, executed: int, skipped: int, failed: int, results: array}
     */
    public static function runForAllTenants(string $taskName, array $skip = []): array
    {
        $tenants = self::tenants();
        $results = [];
        $executed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            $tid = $tenant['id'] ?? $tenant['name'];
            $tname = $tenant['name'];

            // 跳过检查
            if (in_array($tname, $skip, true) || in_array($tid, $skip, true)) {
                $results[] = [
                    'tenant' => $tname,
                    'tenant_id' => $tid,
                    'status' => 'skipped',
                    'message' => '租户已跳过',
                ];
                $skipped++;
                continue;
            }

            $result = self::runForTenant($taskName, $tid, $tname);
            $results[] = $result;

            if ($result['status'] === 'success') {
                $executed++;
            } else {
                $failed++;
            }
        }

        return [
            'ok' => $failed === 0,
            'task' => $taskName,
            'total' => count($tenants),
            'executed' => $executed,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * 为单个租户执行定时任务。
     *
     * @return array{tenant: string, tenant_id: string, status: string, message: string, duration_ms: int}
     */
    private static function runForTenant(string $taskName, string $tenantId, string $tenantName): array
    {
        $startMs = (int) (microtime(true) * 1000);

        try {
            // 注入租户上下文
            Context::set(self::TENANT_CONTEXT_KEY, $tenantId);

            // 执行任务
            /** @var ScheduleDispatcher $dispatcher */
            $dispatcher = resolve(ScheduleDispatcher::class);
            $ok = $dispatcher->runOnce($taskName);

            $durationMs = (int) (microtime(true) * 1000) - $startMs;

            if (!$ok) {
                return [
                    'tenant' => $tenantName,
                    'tenant_id' => $tenantId,
                    'status' => 'failed',
                    'message' => '任务不存在或未注册',
                    'duration_ms' => $durationMs,
                ];
            }

            // 记录执行
            self::recordRun($taskName, $tenantId, $tenantName, 'success', '', $durationMs);

            return [
                'tenant' => $tenantName,
                'tenant_id' => $tenantId,
                'status' => 'success',
                'message' => '执行成功',
                'duration_ms' => $durationMs,
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) (microtime(true) * 1000) - $startMs;
            $msg = Clip::chars($e->getMessage(), 200);

            self::recordRun($taskName, $tenantId, $tenantName, 'failed', $msg, $durationMs);

            return [
                'tenant' => $tenantName,
                'tenant_id' => $tenantId,
                'status' => 'failed',
                'message' => $msg,
                'duration_ms' => $durationMs,
            ];
        } finally {
            // 清除租户上下文（防止泄漏到下一租户）
            Context::set(self::TENANT_CONTEXT_KEY, null);
        }
    }

    /**
     * 获取所有租户最近一次执行状态。
     *
     * @return list<array{task: string, tenant: string, tenant_id: string, status: string, message: string, duration_ms: int, executed_at: string}>
     */
    public static function recentRuns(string $taskName = ''): array
    {
        $where = '';
        $params = [];
        if ($taskName !== '') {
            $where = ' WHERE task_name = ?';
            $params[] = $taskName;
        }

        $rows = Db::select(
            "SELECT task_name, tenant_name, tenant_id, status, message, duration_ms, executed_at
             FROM kode_tenant_schedule_runs
             {$where}
             ORDER BY executed_at DESC
             LIMIT 200",
            $params
        );

        return $rows;
    }

    /**
     * 获取任务执行统计。
     *
     * @return array{task: string, total: int, success: int, failed: int, last_run: string|null, last_duration_ms: int}
     */
    public static function stats(string $taskName): array
    {
        // PG 严格模式：非聚合列必须出现在 GROUP BY 里。取「最近一次运行的 duration_ms」
        // 用 array_agg(... ORDER BY executed_at DESC)[1]，与 MAX(executed_at) 语义一致
        // （对应「last_run 那一刻的耗时」），而非 MAX(duration_ms)（历史最大耗时）。
        $rows = Db::select(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                MAX(executed_at) AS last_run,
                (array_agg(duration_ms ORDER BY executed_at DESC))[1] AS last_duration_ms
             FROM kode_tenant_schedule_runs
             WHERE task_name = ?",
            [$taskName]
        );

        $r = $rows[0] ?? [];
        return [
            'task' => $taskName,
            'total' => (int) ($r['total'] ?? 0),
            'success' => (int) ($r['success'] ?? 0),
            'failed' => (int) ($r['failed'] ?? 0),
            'last_run' => $r['last_run'] ?? null,
            'last_duration_ms' => (int) ($r['last_duration_ms'] ?? 0),
        ];
    }

    /**
     * 记录执行结果。
     */
    private static function recordRun(string $taskName, string $tenantId, string $tenantName, string $status, string $message, int $durationMs): void
    {
        try {
            Db::insert(
                "INSERT INTO kode_tenant_schedule_runs (task_name, tenant_name, tenant_id, status, message, duration_ms, executed_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [$taskName, $tenantName, $tenantId, $status, $message, $durationMs]
            );
        } catch (\Throwable) {
            // 表不存在时静默跳过
        }
    }
}
