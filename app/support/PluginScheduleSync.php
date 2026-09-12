<?php

declare(strict_types=1);

namespace app\support;

use Kode\Framework\Scheduling\ScheduleDispatcher;

/**
 * 插件状态 ↔ 调度器对账。
 *
 * 背景：插件通过 PluginManager::addCron() 或 #[Cron] 注册的定时任务带 `plugin:<name>`
 * 来源标签，但此前框架的 ScheduleDispatcher 只有「注册 + 触发」，没有回收能力——
 * 插件被暂停或卸载后，它的任务仍会按时派发，PluginMarket 的状态机与调度器的实际
 * 行为漂移，且不重启进程无法修复（与 DriverRegistry 的 flush 是同一类漂移）。
 *
 * 框架 v1.4.1 补上 setEnabled() / setEnabledBySource() / unregister() /
 * unregisterBySource() 后，本类负责在状态变更时做即时对账。
 *
 * 只报不改（除调度器外）：对账失败必须静默。插件状态落库是唯一契约，调度器对不齐
 * 最多导致「多跑/少跑一轮」，绝不能反过来让启停操作本身失败（例如脱离容器的
 * 脚本环境里 ScheduleDispatcher 解析不出，见 PluginContract::auditCrons() 的降级）。
 */
final class PluginScheduleSync
{
    /** 插件任务的来源标签前缀（与 PluginManager::addCron() 的标记一致）。 */
    private const SOURCE_PREFIX = 'plugin:';

    /**
     * 按插件状态对账其全部定时任务。
     *
     * @param string $name   插件名（PluginInterface::name() 返回值）
     * @param string $status PluginMarket::ENABLED / PAUSED / UNINSTALLED
     *
     * @return int 实际处置的任务数（未安装、状态未知或调度器不可解析时为 0）
     */
    public static function sync(string $name, string $status): int
    {
        if ($name === '' || $status === '') {
            return 0;
        }

        $dispatcher = self::resolveDispatcher();
        if ($dispatcher === null) {
            return 0;
        }

        $source = self::SOURCE_PREFIX . $name;
        $handled = 0;

        try {
            match ($status) {
                PluginMarket::UNINSTALLED => $handled = $dispatcher->unregisterBySource($source),
                PluginMarket::PAUSED => $handled = $dispatcher->setEnabledBySource($source, false),
                PluginMarket::ENABLED => $handled = $dispatcher->setEnabledBySource($source, true),
                default => null,
            };
        } catch (\Throwable) {
        }

        return $handled;
    }

    /**
     * 某插件当前在调度器里的任务快照（含禁用占位），供排障与展示。
     *
     * @return list<array<string, mixed>>
     */
    public static function tasksOf(string $name): array
    {
        $dispatcher = self::resolveDispatcher();
        if ($dispatcher === null) {
            return [];
        }

        $rows = [];
        try {
            foreach ($dispatcher->bySource(self::SOURCE_PREFIX . $name) as $task) {
                $rows[] = [
                    'name' => $task->name,
                    'expression' => $task->expression,
                    'enabled' => $task->enabled,
                    'target' => $task->target(),
                ];
            }
        } catch (\Throwable) {
        }

        return $rows;
    }

    /** 解析调度器；框架未升级（无生命周期 API）或容器不可用时返回 null。 */
    private static function resolveDispatcher(): ?ScheduleDispatcher
    {
        $class = ScheduleDispatcher::class;
        if (!class_exists($class)) {
            return null;
        }
        if (!method_exists($class, 'unregisterBySource')) {
            return null;
        }

        try {
            /** @var ScheduleDispatcher $dispatcher */
            $dispatcher = resolve($class);

            return $dispatcher instanceof ScheduleDispatcher ? $dispatcher : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
