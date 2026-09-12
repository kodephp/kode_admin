<?php

declare(strict_types=1);

namespace app\console;

use app\support\PluginDependency;
use app\support\PluginMarket;
use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;

/**
 * 插件依赖图可视化（只读，不修改任何状态）。
 *
 * 用法：
 *   php kode plugin:deps              展示全部插件的依赖关系
 *   php kode plugin:deps notices      展示单个插件的依赖详情
 *   php kode plugin:deps --json       JSON 输出（脚本消费）
 *
 * 退出码：0 = 正常；1 = 参数错误。
 *
 * 依赖引擎：图算法与版本约束判定由框架 {@see \Kode\Framework\Plugin\PluginDependencyResolver}
 * 提供（v1.4.2 下沉），本站 {@see PluginDependency} 只注入本站事实（kode_plugins 表）。
 * 本命令是只读消费方——绝不执行 install/uninstall/upgrade，只展示当前状态。
 *
 * 输出示例（文本模式）：
 *   notices
 *     ├── storage_driver (^1.0) [enabled v1.0.0] ✓
 *     └── auth (>=2.0) [paused v1.5.0] ✗ 版本不满足
 *
 *   循环依赖检测：若存在环，会在循环起点标注 ⚠️ 并列出环路径。
 */
#[AsCommand(
    name: 'plugin:deps',
    description: '展示插件依赖图与版本约束状态',
    usage: 'plugin:deps {name?} {--json:bool}',
)]
final class PluginDepsCommand extends Command
{
    protected function handle(): int
    {
        $only = $this->arg('name');
        $discovered = PluginMarket::discover();

        if ($only !== null) {
            $name = (string) $only;
            if (!isset($discovered[$name])) {
                $this->error('不存在插件：' . $name);
                $this->line('可用：' . implode(', ', array_keys($discovered) ?: ['-']));
                return 1;
            }
            $targets = [$name => $discovered[$name]];
        } else {
            $targets = $discovered;
        }

        if ($targets === []) {
            $this->warn('未发现在 plugins/ 下的任何插件');
            return 1;
        }

        $results = [];
        foreach ($targets as $name => $class) {
            $results[$name] = PluginDependency::overview($name);
        }

        if ($this->flag('json')) {
            $this->output->json($results);
            return 0;
        }

        // 文本输出
        foreach ($results as $name => $overview) {
            $this->printPluginDeps($name, $overview);
        }

        // 汇总：环检测
        $cycles = [];
        foreach ($targets as $name => $class) {
            if (PluginDependency::hasCycle($name)) {
                $cycles[] = $name;
            }
        }
        if ($cycles !== []) {
            $this->warn('⚠️  检测到循环依赖：' . implode(', ', $cycles));
        }

        return 0;
    }

    private function printPluginDeps(string $name, array $overview): void
    {
        $deps = $overview['dependencies'];
        $dependents = $overview['dependents'];
        $transitive = $overview['transitive'];
        $cycle = $overview['cycle'];

        $header = "  {$name}" . ($cycle ? ' ⚠️ 循环依赖' : '');
        $this->line($header);

        if ($deps === []) {
            $this->line('    （无依赖）');
        } else {
            foreach ($deps as $dep) {
                $status = $dep['ok'] ? '✓' : '✗';
                $ver = $dep['version'] ?? '*';
                $installed = $dep['installed'] !== '' ? "v{$dep['installed']}" : '未登记';
                $statusStr = $dep['status'];

                $reason = '';
                if (!$dep['ok']) {
                    if ($dep['status'] !== 'enabled') {
                        $reason = " [状态:{$dep['status']}]";
                    } elseif ($dep['version'] !== '*' && $dep['version'] !== '') {
                        $reason = " [版本不满足]";
                    }
                }

                $this->line("    ├── {$dep['name']} ({$ver}) [{$statusStr} {$installed}] {$status}{$reason}");
            }
        }

        if ($transitive !== []) {
            $this->line("    传递依赖: " . implode(', ', $transitive));
        }
        if ($dependents !== []) {
            $this->line("    被依赖方: " . implode(', ', $dependents));
        }
        $this->line('');
    }
}
