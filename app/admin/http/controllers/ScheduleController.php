<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use Kode\Framework\Http\Controller;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use app\http\concerns\ApiResponse;

/**
 * 定时任务：框架 #[Cron] 注册表只读 + 手动触发（管理启停请改代码注解，见 config/schedule.php）。
 */
final class ScheduleController extends Controller
{
    use ApiResponse;

    public function index()
    {
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);
        $rows = [];
        foreach ($dispatcher->registered() as $task) {
            $rows[] = [
                'name' => (string) ($task->name ?? ''),
                'expression' => (string) ($task->expression ?? ''),
                'enabled' => (bool) ($task->enabled ?? true),
                'handler' => (string) (($task->class ?? '') . '::' . ($task->method ?? '')),
                'source' => (string) ($task->source ?? ''),
            ];
        }

        return $this->ok($rows);
    }

    public function run()
    {
        $name = (string) $this->param('name');
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
            return $this->fail('不存在', 404);
        }
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);
        try {
            $ok = $dispatcher->runOnce($name);
        } catch (\Throwable $e) {
            \app\support\SysLog::error('schedule', "手动触发失败：{$name}", ['error' => $e->getMessage()]);

            return $this->fail('执行失败：' . $e->getMessage(), 500);
        }
        if (!$ok) {
            return $this->fail('不存在', 404);
        }
        \app\support\SysLog::info('schedule', "手动触发：{$name}");

        return $this->ok(['name' => $name]);
    }
}
