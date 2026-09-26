<?php

declare(strict_types=1);

namespace app\admin\controllers;

use Kode\Framework\Http\Controller;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use app\concerns\ApiResponse;
use app\concerns\RetentionGuard;
use app\support\DbReadFace;
use app\support\PluginContract;
use app\support\PluginMarket;
use app\support\SysLog;
use app\support\TenantScheduleRunner;

/**
 * 定时任务管理：框架 #[Cron] 注册表 + 手动触发 + 多租户同步执行 + 执行审计。
 *
 * v1.6.0 增强（多租户权限收敛）：
 *  - toggle(): 运行时启停任务（无需重启）；
 *  - nextRuns(): 计算全部任务下次运行时刻；
 *  - health(): 获取任务健康指标（成功率/平均耗时/连续失败）；
 *  - byTenant(): 按租户 ID 查看任务；
 *  - create(): 动态创建租户级闭包任务；
 *  - lockStatus(): 检查任务级互斥锁状态；
 *  - history(): 从新审计表查询执行历史；
 *  - prune(): 清理过期执行历史。
 */
final class ScheduleController extends Controller
{
    use ApiResponse;
    use RetentionGuard;

    public function index()
    {
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);
        $rows = [];
        foreach ($dispatcher->registered() as $task) {
            $nextRun = $dispatcher->nextRun($task->name);
            $rows[] = [
                'name' => (string) ($task->name ?? ''),
                'expression' => (string) ($task->expression ?? ''),
                'enabled' => (bool) ($task->enabled ?? true),
                'handler' => (string) (($task->class ?? '') . '::' . ($task->method ?? '')),
                'source' => (string) ($task->source ?? ''),
                'cluster' => (bool) ($task->cluster ?? false),
                'tenant_id' => (int) ($task->tenantId ?? 0),
                'next_run' => $nextRun?->format('Y-m-d H:i:s'),
            ];
        }

        return $this->ok($rows);
    }

    /**
     * PUT /api/schedules/{name}/toggle — 运行时启停任务。
     *
     * 请求体：{ enabled: bool }
     */
    public function toggle()
    {
        $name = (string) $this->param('name');
        $enabled = (bool) $this->input('enabled');

        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
            return $this->fail('任务名非法', 400);
        }

        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);
        $changed = $dispatcher->setEnabled($name, $enabled);

        if (!$changed) {
            $task = $dispatcher->find($name);
            if ($task === null) {
                return $this->fail('不存在', 404);
            }
            return $this->ok(['name' => $name, 'enabled' => $task->enabled, 'changed' => false]);
        }

        SysLog::info('schedule', "任务状态变更：{$name} → " . ($enabled ? '启用' : '停用'));
        return $this->ok(['name' => $name, 'enabled' => $enabled, 'changed' => true]);
    }

    /**
     * GET /api/schedules/next-runs — 全部任务下次运行时刻。
     */
    public function nextRuns()
    {
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);
        $runs = $dispatcher->allNextRuns();

        $rows = [];
        foreach ($runs as $name => $next) {
            $rows[] = [
                'name' => $name,
                'next_run' => $next?->format('Y-m-d H:i:s'),
            ];
        }

        return $this->ok($rows);
    }

    /**
     * GET /api/schedules/health — 全部任务健康指标。
     *
     * framework v1.14.0 起 `stats()` 读不到时会**抛**（旧写法在包里 logger() 之后回一份
     * `total:0 / success_rate:100.0` 的合成行）。抛到这里还不够 —— 这一屏原来的接法是
     * 「异常出控制器 → 500（msg 里是 PDO 原文，含 host/port）」+「前端 `catch { 忽略 }`」，
     * 两头合起来把「一座库没读到」渲染成「这台系统没有任务跑过」。现在口径与监控那四条腿一致：
     * HTTP 恒 200，`{tasks, available, reason}` 形状不随成败变（AGENTS §6）。
     */
    public function health()
    {
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);

        try {
            $stats = $dispatcher->stats();
        } catch (\Throwable $e) {
            return $this->ok(DbReadFace::unreadable($e, 'schedules-health', ['tasks' => []]));
        }

        return $this->ok(DbReadFace::readable(['tasks' => $stats]));
    }

    /**
     * GET /api/schedules/{name}/history — 单任务执行历史（新审计表）。
     *
     * Query: ?limit=<n>
     *
     * 抽屉只在有人来查故障时才点开，所以「读不到」必须说得出（同 `health()`）。
     * 唯一算「没有历史」的是包里那条判断：历史表还没建。
     */
    public function history()
    {
        $name = (string) $this->param('name');
        $limit = min(200, max(1, (int) $this->query('limit', 50)));

        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);

        try {
            $rows = $dispatcher->runHistory($name, $limit);
        } catch (\Throwable $e) {
            return $this->ok(DbReadFace::unreadable($e, 'schedules-history', ['rows' => []]));
        }

        // 格式化日期
        foreach ($rows as &$row) {
            if (isset($row['started_at']) && $row['started_at'] instanceof \DateTimeInterface) {
                $row['started_at'] = $row['started_at']->format('Y-m-d H:i:s');
            }
        }
        unset($row);

        return $this->ok(DbReadFace::readable(['rows' => array_values($rows)]));
    }

    /**
     * GET /api/schedules/lock-status — 任务级互斥锁状态。
     */
    public function lockStatus()
    {
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);
        $locked = $dispatcher->lockedTasks();

        $rows = [];
        foreach ($dispatcher->registered() as $task) {
            $rows[] = [
                'name' => $task->name,
                'locked' => $dispatcher->isLocked($task->name),
                'enabled' => $task->enabled,
            ];
        }

        return $this->ok(['locked' => $locked, 'tasks' => $rows]);
    }

    /**
     * POST /api/schedules/{name}/run — 手动触发 + 记录执行历史。
     */
    public function run()
    {
        $name = (string) $this->param('name');
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
            return $this->fail('不存在', 404);
        }
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);
        $start = hrtime(true);
        try {
            $ok = $dispatcher->runOnce($name);
            $duration = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->recordCronRun($name, $ok ? 'success' : 'failed', $ok ? '' : '任务不存在或已禁用', $duration);
        } catch (\Throwable $e) {
            $duration = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->recordCronRun($name, 'failed', $e->getMessage(), $duration);
            SysLog::error('schedule', "手动触发失败：{$name}", ['error' => $e->getMessage()]);
            return $this->fail('执行失败：' . $e->getMessage(), 500);
        }
        if (!$ok) {
            return $this->fail('不存在', 404);
        }
        SysLog::info('schedule', "手动触发：{$name}");
        return $this->ok(['name' => $name, 'duration_ms' => $duration]);
    }

    /**
     * POST /api/schedules/create — 动态注册一条定时任务。
     *
     * 请求体：{ name, expression, handler, description?, cluster?, tenant_id?, metadata? }
     *
     * handler 是**已存在的任务方法引用**（`app\tasks\X`、`X`、`X::method`），不是代码字符串：
     * 动态注册只是给既有任务换排期/挂租户，管理接口不现场编译 PHP。
     */
    public function create()
    {
        $name = (string) $this->input('name', '');
        $expression = (string) $this->input('expression', '');
        $handlerRef = trim((string) $this->input('handler', ''));
        $description = (string) $this->input('description', '');
        $cluster = (bool) $this->input('cluster', false);
        $tenantId = (int) $this->input('tenant_id', 0);
        $metadata = (array) $this->input('metadata', []);

        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
            return $this->fail('任务名非法', 400);
        }
        if ($expression === '' || !preg_match('/^[\d\*,\/\-]+(\s+[\d\*,\/\-]+){4}$/', $expression)) {
            return $this->fail('Cron 表达式非法（需 5 段格式）', 400);
        }

        $handler = $this->resolveTaskHandler($handlerRef);
        if ($handler === null) {
            return $this->fail('Handler 必须是 app\\tasks 下已存在的公共任务方法（形如 CleanupTask 或 MonitorTask::ping）', 400);
        }

        [$class, $method] = $handler;

        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);
        try {
            $task = $dispatcher->addCron($name, $expression, static function () use ($class, $method): void {
                resolve($class)->{$method}();
            }, [
                'description' => $description,
                'cluster' => $cluster,
                'tenantId' => $tenantId,
                'source' => 'dynamic',
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            SysLog::error('schedule', "动态创建任务失败：{$name}", ['error' => $e->getMessage()]);
            return $this->fail('创建失败：' . $e->getMessage(), 500);
        }

        SysLog::info('schedule', "动态创建任务：{$name}（{$expression}）→ {$class}::{$method}");
        return $this->ok([
            'name' => $task->name,
            'expression' => $task->expression,
            'enabled' => $task->enabled,
            'tenant_id' => $task->tenantId,
            'handler' => $class . '::' . $method,
        ]);
    }

    /**
     * 把 handler 引用解析为 `[类全名, 方法名]`；不合法返回 null。
     *
     * 只认「磁盘上已有的 app\tasks 任务类」：一条 `file_put_contents('/tmp/x', …)` 就能
     * 穿过原来的危险函数黑名单（`$f='sys'.'tem'; $f('id')` 同样可绕），把管理接口变成
     * 远程代码执行入口。引用式契约让「执行什么」退化为一次白名单查表——不存在的类、
     * 非公共方法、需传参的方法、魔术方法一律拒绝。
     *
     * @return array{0: string, 1: string}|null
     */
    private function resolveTaskHandler(string $ref): ?array
    {
        $prefix = 'app\\tasks\\';
        $bare = strncasecmp($ref, $prefix, strlen($prefix)) === 0
            ? substr($ref, strlen($prefix))
            : $ref;

        if (!preg_match('/^([A-Za-z][A-Za-z0-9_]*)(::([A-Za-z][A-Za-z0-9_]*))?$/', $bare, $m)) {
            return null;
        }

        $class = $prefix . $m[1];
        if (!class_exists($class)) {
            return null;
        }

        $reflection = new \ReflectionClass($class);
        if ($reflection->isInterface() || $reflection->isAbstract() || !$reflection->isUserDefined()) {
            return null;
        }

        $method = $m[3] ?? '';
        if ($method === '') {
            if (!$reflection->hasMethod('handle')) {
                return null;
            }
            $method = 'handle';
        }

        // 类名大小写不敏感：以声明时的真实名字为准，避免注册表里混进 app\tasks\cleanupTask
        $class = $reflection->getName();

        if (str_starts_with($method, '__')) {
            return null;
        }
        if (!$reflection->hasMethod($method)) {
            return null;
        }

        $target = $reflection->getMethod($method);
        if (!$target->isPublic() || $target->isStatic() || $target->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        return [$class, $target->getName()];
    }

    /**
     * GET /api/schedules/by-tenant/{tenantId} — 按租户查看任务。
     */
    public function byTenant()
    {
        $tenantId = (int) $this->param('tenantId');

        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);
        $tasks = $dispatcher->byTenant($tenantId);

        $rows = [];
        foreach ($tasks as $task) {
            $rows[] = [
                'name' => $task->name,
                'expression' => $task->expression,
                'enabled' => $task->enabled,
                'source' => $task->source,
                'tenant_id' => $task->tenantId,
                'next_run' => $dispatcher->nextRun($task->name)?->format('Y-m-d H:i:s'),
            ];
        }

        return $this->ok($rows);
    }

    /**
     * POST /api/schedules/prune — 清理过期执行历史。
     *
     * Query: ?days=<n>（必填，7..3650）
     */
    public function prune()
    {
        // days 的判定只有一份：漏传/0/负数以前夹成 1 天，等于一次「删光除今天以外全部执行历史」的
        // 无告警批量删除（口径同 audits/syslogs/login-logs 三处 clean）。
        $guard = $this->retentionDays($this->query('days'));
        if ($guard['error'] !== null) {
            return $this->fail($guard['error'], 400);
        }
        $days = $guard['days'];

        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);

        try {
            $deleted = $dispatcher->pruneRunHistory($days);
        } catch (\Throwable $e) {
            // 清理失败不能回 `{deleted:0}` —— 那会被念成「清理完成，没有过期数据」，
            // 而保留期是否真的在生效从此无人知晓（framework v1.11.1 起这条会抛）。
            // 响应只给结论：驱动原文里有 host/port，那是内网拓扑（`DbReadFace::reason()`）。
            SysLog::error('schedule', "清理执行历史失败：{$days} 天保留期", ['error' => $e->getMessage()]);

            return $this->fail('清理执行历史失败：' . DbReadFace::reason($e), 500);
        }

        SysLog::info('schedule', "清理执行历史：删除 {$deleted} 条（超过 {$days} 天）");
        return $this->ok(['deleted' => $deleted, 'retention_days' => $days]);
    }

    /**
     * GET /api/schedules/runs — 非租户任务执行历史（兼容旧表）。
     *
     * Query: ?task=<name>&limit=<n>
     */
    public function runs()
    {
        $task = (string) $this->query('task', '');
        $limit = min(100, max(1, (int) $this->query('limit', 50)));

        $list = [];

        try {
            $query = \Kode\Database\Db\Db::table('kode_cron_runs');
            if ($task !== '') {
                $query->where('task_name', $task);
            }
            $query->orderBy('executed_at', 'desc')->limit($limit);

            $rows = $query->get();
        } catch (\Throwable $e) {
            return $this->ok(DbReadFace::unreadable($e, 'schedules-runs', ['rows' => []]));
        }

        foreach ($rows as $row) {
            // `QueryBuilder::get()` 交回的是关联数组；`$row->task_name` 只有一条 warning，
            // 于是整列表渲染成「有 N 行，每行全空」。见 tests/QueryBuilderRowShapeTest.php。
            $list[] = [
                'task_name' => (string) ($row['task_name'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'duration_ms' => (int) ($row['duration_ms'] ?? 0),
                'executed_at' => (string) ($row['executed_at'] ?? ''),
            ];
        }

        return $this->ok(DbReadFace::readable(['rows' => $list]));
    }

    /**
     * GET /api/schedule/tenants — 列出所有活跃租户。
     */
    public function tenants()
    {
        try {
            $rows = TenantScheduleRunner::tenants();
        } catch (\Throwable $e) {
            return $this->ok(DbReadFace::unreadable($e, 'schedule-tenants', ['rows' => []]));
        }

        return $this->ok(DbReadFace::readable(['rows' => $rows]));
    }

    /**
     * POST /api/schedule/tenants/run — 为所有租户执行指定任务。
     *
     * 请求体：{ task: string, skip?: string[] }
     */
    public function runForTenants()
    {
        $task = (string) $this->input('task', '');
        $skip = (array) $this->input('skip', []);

        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $task)) {
            return $this->fail('任务名非法', 400);
        }

        try {
            $result = TenantScheduleRunner::runForAllTenants($task, $skip);
        } catch (\Throwable $e) {
            SysLog::error('schedule', "租户调度失败：{$task}", ['error' => $e->getMessage()]);
            return $this->fail('执行失败：' . $e->getMessage(), 500);
        }

        SysLog::info('schedule', "租户调度完成：{$task}，成功 {$result['executed']}，失败 {$result['failed']}，跳过 {$result['skipped']}");
        return $this->ok($result);
    }

    /**
     * GET /api/schedule/tenants/runs — 获取租户任务执行记录。
     *
     * Query: ?task=<name>
     */
    public function tenantRuns()
    {
        $task = (string) $this->query('task', '');

        try {
            $rows = TenantScheduleRunner::recentRuns($task);
        } catch (\Throwable $e) {
            return $this->ok(DbReadFace::unreadable($e, 'schedule-tenant-runs', ['rows' => []]));
        }

        return $this->ok(DbReadFace::readable(['rows' => $rows]));
    }

    /**
     * GET /api/schedule/tenants/stats/{task} — 获取任务执行统计。
     *
     * 读不到时四个数字回 **null 而不是 0**：`total: 0` 会被念成「这台系统一次都没跑过」，
     * 那是另一种假数据（口径同 §3 的聚合缺键规则、监控那四条腿的连接数）。
     */
    public function tenantStats()
    {
        $task = (string) $this->param('task');
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $task)) {
            return $this->fail('任务名非法', 400);
        }

        try {
            $stats = TenantScheduleRunner::stats($task);
        } catch (\Throwable $e) {
            return $this->ok(DbReadFace::unreadable($e, 'schedule-tenant-stats', [
                'task' => $task,
                'total' => null,
                'success' => null,
                'failed' => null,
                'last_run' => null,
                'last_duration_ms' => null,
            ]));
        }

        return $this->ok(DbReadFace::readable($stats));
    }

    /**
     * GET /api/schedule/tenant-tasks — 聚合所有插件通过 `tenantCrons()` 声明的多租户任务。
     */
    public function tenantTasks()
    {
        $out = [];
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);
        $registered = [];
        foreach ($dispatcher->registered() as $task) {
            $registered[(string) ($task->name ?? '')] = [
                'source' => (string) ($task->source ?? ''),
                'expression' => (string) ($task->expression ?? ''),
                'enabled' => (bool) ($task->enabled ?? true),
            ];
        }

        foreach (PluginMarket::discover() as $name => $class) {
            try {
                $plugin = new $class();
            } catch (\Throwable) {
                continue;
            }
            if (!method_exists($plugin, 'tenantCrons')) {
                continue;
            }
            try {
                $declared = (array) $plugin->tenantCrons();
            } catch (\Throwable) {
                continue;
            }
            foreach ($declared as $taskName) {
                $taskKey = is_string($taskName) ? $taskName : (string) ($taskName['name'] ?? '');
                if ($taskKey === '') {
                    continue;
                }
                $meta = $registered[$taskKey] ?? [];
                $out[] = [
                    'plugin' => $name,
                    'task' => $taskKey,
                    'source' => $meta['source'] ?? ("plugin:{$name}"),
                    'expression' => $meta['expression'] ?? '',
                    'enabled' => $meta['enabled'] ?? false,
                ];
            }
        }

        return $this->ok($out);
    }

    // ─── 私有辅助 ───────────────────────────────────────────

    /**
     * 记录非租户任务执行历史（兼容旧表）。
     */
    private function recordCronRun(string $taskName, string $status, string $message, int $durationMs): void
    {
        try {
            \Kode\Database\Db\Db::table('kode_cron_runs')->insert([
                'task_name' => $taskName,
                'status' => $status,
                'message' => $message,
                'duration_ms' => $durationMs,
                'executed_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            SysLog::error('schedule', "记录执行历史失败：{$taskName}", ['error' => $e->getMessage()]);
        }
    }
}
