<?php

declare(strict_types=1);

namespace app\console;

use app\support\PluginContract;
use app\support\PluginMarket;
use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;

/**
 * 插件能力契约审计（声明⟺实现零漂移）。
 *
 * 用法：
 *   php kode plugin:audit              审计全部插件
 *   php kode plugin:audit notices      审计单个插件
 *   php kode plugin:audit --json       JSON 输出（CI / 脚本消费）
 *
 * 退出码：0 = 无漂移；1 = 存在漂移（CI 应据此阻断）；2 = 参数/发现错误。
 * 审计是只读的：生命周期方法 install/uninstall/upgrade 绝不会被执行。
 */
#[AsCommand(name: 'plugin:audit', description: '审计插件能力契约（声明⟺实现零漂移）', usage: 'plugin:audit {name?} {--json:bool}')]
final class PluginAuditCommand extends Command
{
    protected function handle(): int
    {
        $only = $this->arg('name');
        $discovered = PluginMarket::discover();

        $targets = [];
        if ($only !== null) {
            $name = (string) $only;
            if (!isset($discovered[$name])) {
                $this->error('不存在插件：' . $name);
                $this->line('可用：' . implode(', ', array_keys($discovered) ?: ['-']));

                return 2;
            }
            $targets = [$name => $discovered[$name]];
        } else {
            $targets = $discovered;
        }

        if ($targets === []) {
            $this->warn('未发现在 plugins/ 下的任何插件');

            return 2;
        }

        $reports = [];
        $driftTotal = 0;
        $pendingTotal = 0;
        foreach ($targets as $name => $class) {
            $plugin = new $class();
            $desc = PluginContract::describe($plugin);
            $audit = PluginContract::audit($plugin);
            $reports[$name] = [
                'class'       => $class,
                'version'     => $desc['version'],
                'capabilities' => $desc['capabilities'],
                'drivers'     => $desc['drivers'],
                'permissions' => $desc['permissions'],
                'ok'          => $audit['ok'],
                'drift'       => $audit['drift'],
                'pending'     => $audit['pending'],
                'errors'      => $desc['errors'],
            ];
            $driftTotal += count($audit['drift']);
            $pendingTotal += count($audit['pending']);
        }

        if ($this->flag('json')) {
            $this->output->raw(json_encode([
                'plugins'  => $reports,
                'drift'    => $driftTotal,
                'pending'  => $pendingTotal,
                'ok'       => $driftTotal === 0,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->line('');

            return $driftTotal === 0 ? 0 : 1;
        }

        // 表格视图
        $rows = [];
        foreach ($reports as $name => $r) {
            $rows[] = [
                $name,
                $r['version'],
                count($r['capabilities']),
                implode(',', $r['drivers']) ?: '-',
                $r['ok'] ? 'OK' : 'DRIFT',
                count($r['drift']),
                count($r['pending']),
            ];
        }
        $this->table(['插件', '版本', '能力', '驱动', '契约', '漂移', '待复查'], $rows);
        $this->line('');

        foreach ($reports as $name => $r) {
            foreach ($r['drift'] as $msg) {
                $this->error("[$name] 漂移：{$msg}");
            }
            foreach ($r['pending'] as $msg) {
                $this->warn("[$name] 待复查：{$msg}");
            }
        }
        $this->line('');

        if ($driftTotal > 0) {
            $this->error("{$driftTotal} 项声明⟺实现漂移（必须修复后再发版）");

            return 1;
        }
        $this->success(sprintf(
            '零漂移：%d 个插件通过审计%s',
            count($reports),
            $pendingTotal > 0 ? '（' . $pendingTotal . ' 项待复查，环境未就绪，不阻断）' : ''
        ));

        return 0;
    }
}
