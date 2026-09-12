<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use app\support\PluginConfig;
use app\support\PluginContract;
use app\support\PluginDependency;
use app\support\PluginMarket;
use app\support\PluginHealthChecker;
use app\support\PluginScaffolder;
use app\support\PluginVersionHistory;
use app\support\SysLog;
use Kode\Framework\Http\Controller;
use app\http\concerns\ApiResponse;
use Kode\Framework\Plugin\PluginManager;
use Kode\Http\Request;

/**
 * 插件市场：列表 / 安装 / 卸载（含备份提醒 + 二次确认）/ 暂停 / 恢复 / 升级。
 *
 * 启停经运行时门禁即时生效，无需重启；卸载默认保留数据，purge 删表需 confirm=1。
 */
final class PluginController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $states = PluginMarket::states();
        $list = [];
        foreach (PluginMarket::discover() as $name => $class) {
            $plugin = $this->plugin($name);
            $desc = PluginContract::describe($plugin);
            $audit = PluginContract::audit($plugin);
            $dep = PluginDependency::overview($name);
            $list[] = [
                'name'              => $name,
                'class'             => $class,
                'version'           => $desc['version'],
                'installed_version' => $states[$name]['version'] ?? null,
                'status'            => $states[$name]['status'] ?? PluginMarket::ENABLED,
                'menus'             => $desc['menus'],
                'tables'            => $desc['tables'],
                // 对外暴露类能力：列表页也要可见（否则只声明了 commands/events/crons
                // 的插件在列表里看起来和空壳一样，与 contract() 详情不一致）
                'commands'          => $desc['commands'],
                'events'            => $desc['events'],
                'crons'             => $desc['crons'],
                // 能力契约：声明的能力 + 零漂移审计（见 docs/PLUGIN-DEV.md §4）
                'capabilities'      => $desc['capabilities'],
                'groups'            => $desc['groups'],
                'permissions'       => $desc['permissions'],
                'drivers'           => $desc['drivers'],
                'requires'          => $desc['requires'],
                'config_schema'     => $desc['config_schema'],
                'meta'              => $desc['meta'],
                'errors'            => $desc['errors'],
                'contract_ok'       => $audit['ok'],
                'contract_drift'    => $audit['drift'],
                'contract_pending'  => $audit['pending'],
                // 依赖图：直接依赖（含就绪态）+ 传递闭包 + 下游依赖者 + 是否成环
                'dependencies'      => $dep['dependencies'],
                'dependents'        => $dep['dependents'],
                'transitive'        => $dep['transitive'],
                'cycle'             => $dep['cycle'],
            ];
        }

        return $this->ok($list);
    }

    /**
     * 单插件能力契约详情（前端插件详情页「能力」页签用）。
     */
    public function contract()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        $plugin = $this->plugin($name);
        $desc = PluginContract::describe($plugin);
        $audit = PluginContract::audit($plugin);

        return $this->ok($desc + [
            'contract_ok'   => $audit['ok'],
            'contract_drift' => $audit['drift'],
            'contract_pending' => $audit['pending'],
            'schema'        => PluginContract::CAPABILITY_SCHEMA,
            'group_labels'  => PluginContract::GROUP_LABELS,
            'dependencies'  => PluginDependency::overview($name),
        ]);
    }

    /**
     * 插件配置（schema 驱动）：返回 configSchema() 规范化的字段定义 + 当前值。
     *
     * secret 字段回显掩码值；前端留空或原样回传掩码即视为「保持不变」。
     */
    public function config()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        return $this->ok(PluginConfig::allFor($name) + [
            'name'       => $name,
            'status'     => PluginMarket::statusOf($name) ?? PluginMarket::ENABLED,
            'key_prefix' => PluginConfig::PREFIX . $name . '.',
        ]);
    }

    /**
     * 保存插件配置：按 schema 逐字段校验后入库（校验全通过才写，不写半截）。
     */
    public function saveConfig()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        $all = Request::all();
        $payload = is_array($all['values'] ?? null) ? $all['values'] : $all;
        $result = PluginConfig::save($name, $payload);

        if (!$result['ok']) {
            return $this->fail('配置校验失败', 400, ['errors' => $result['errors']]);
        }
        if ($result['saved'] !== []) {
            \app\support\SysLog::info('plugin', '插件配置已更新：' . $name, ['saved' => $result['saved'], 'skipped' => $result['skipped']]);
        }

        return $this->ok($result);
    }

    public function install()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }
        // 依赖门禁：requires() 声明的插件必须已安装且 enabled、版本满足约束
        $dep = PluginDependency::validate($name);
        if (!$dep['ok']) {
            return $this->fail('依赖未就绪，无法安装', 400, ['dependencies' => $dep]);
        }
        $plugin = $this->plugin($name);
        if (method_exists($plugin, 'install')) {
            $plugin->install();
        }
        $version = method_exists($plugin, 'version') ? (string) $plugin->version() : '1.0.0';
        PluginMarket::setStatus($name, PluginMarket::ENABLED);
        PluginMarket::setVersion($name, $version);
        \app\support\SysLog::info('plugin', '插件已安装：' . $name . ' v' . $version, ['deps' => $dep['checked']]);

        return $this->ok(['name' => $name, 'version' => $version, 'status' => PluginMarket::ENABLED]);
    }

    public function uninstall()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }
        $all = Request::all();
        $confirm = filter_var($all['confirm'] ?? null, FILTER_VALIDATE_BOOLEAN);
        if (!$confirm) {
            return $this->fail('卸载需 confirm=1 二次确认', 400);
        }

        $plugin = $this->plugin($name);
        $tables = method_exists($plugin, 'tables') ? array_values((array) $plugin->tables()) : [];
        $purge = filter_var($all['purge'] ?? null, FILTER_VALIDATE_BOOLEAN);
        if ($purge && method_exists($plugin, 'uninstall')) {
            $plugin->uninstall(true);
        }
        $configPurged = $purge ? PluginConfig::purge($name) : 0;
        PluginMarket::setStatus($name, PluginMarket::UNINSTALLED);

        return $this->ok([
            'name' => $name,
            'status' => PluginMarket::UNINSTALLED,
            'purged' => $purge ? $tables : [],
            'config_purged' => $configPurged,
            // 防误删：删表前务必备份，给出可直接执行的命令（只提示，不代执行）。
            'backup_hint' => $purge
                ? '已删除表 ' . implode(',', $tables) . '，请确认已有备份'
                : '数据已保留；恢复用 pg_dump 备份：pg_dump -h ' . env('DB_HOST', '127.0.0.1')
                    . ' -U ' . env('DB_USERNAME', 'root') . ' -d ' . env('DB_DATABASE', 'kode_app')
                    . ' -t ' . implode(' -t ', $tables) . ' > plugin_' . $name . '_backup.sql',
        ]);
    }

    public function pause()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }
        PluginMarket::setStatus($name, PluginMarket::PAUSED);
        // 级联暂停下游依赖者：否则下游仍在运行却调不到已暂停的依赖（resolve 不到服务、事件无人接收）。
        $cascaded = [];
        foreach (PluginDependency::dependents($name) as $dep) {
            if (PluginMarket::statusOf($dep) !== PluginMarket::PAUSED) {
                PluginMarket::setStatus($dep, PluginMarket::PAUSED);
                $cascaded[] = $dep;
            }
        }
        \app\support\SysLog::info('plugin', '插件已暂停：' . $name, ['cascaded' => $cascaded]);

        return $this->ok([
            'name'     => $name,
            'status'   => PluginMarket::PAUSED,
            'cascaded' => $cascaded,
            'hint'     => $cascaded === []
                ? ''
                : '已级联暂停下游依赖者：' . implode(', ', $cascaded),
        ]);
    }

    public function resume()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }
        $dep = PluginDependency::validate($name);
        if (!$dep['ok']) {
            return $this->fail('依赖未就绪，无法恢复', 400, ['dependencies' => $dep]);
        }
        PluginMarket::setStatus($name, PluginMarket::ENABLED);

        return $this->ok(['name' => $name, 'status' => PluginMarket::ENABLED]);
    }

    /**
     * 升级插件（记录版本历史 + 提取变更日志 + 兼容性检查）。
     *
     * 流程：
     *   1. 版本比较：目标 > 当前才升级
     *   2. 依赖校验：requires() 声明的插件必须就绪
     *   3. 契约审计：漂移/待确认项作为 warnings（不阻断）
     *   4. 调用插件 upgrade()（如有实现）
     *   5. 记录版本历史（kode_plugin_versions）
     *   6. 返回 changelog + 兼容性检查结果
     */
    public function upgrade()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }
        $plugin = $this->plugin($name);
        $from = (string) (PluginMarket::states()[$name]['version'] ?? '0.0.0');
        $to = method_exists($plugin, 'version') ? (string) $plugin->version() : '1.0.0';
        if (version_compare($to, $from, '<=')) {
            return $this->ok(['name' => $name, 'version' => $from, 'upgraded' => false]);
        }
        $dep = PluginDependency::validate($name);
        if (!$dep['ok']) {
            return $this->fail('依赖未就绪，无法升级', 400, ['dependencies' => $dep]);
        }
        // 兼容性检查（契约漂移/待确认作为 warnings）
        $compat = PluginVersionHistory::checkCompatibility($name, $from, $to);
        if (!$compat['ok']) {
            return $this->fail('升级被阻断', 400, ['blockers' => $compat['blockers']]);
        }
        // 提取变更日志
        $changelog = PluginVersionHistory::changelogFor($name, $from, $to);
        // 执行升级
        if (method_exists($plugin, 'upgrade')) {
            $plugin->upgrade($from, $to);
        }
        PluginMarket::setStatus($name, PluginMarket::ENABLED);
        PluginMarket::setVersion($name, $to);
        // 记录版本历史
        $operator = (string) (Request::user()?->uid ?? 'system');
        PluginVersionHistory::record($name, $from, $to, PluginVersionHistory::OP_UPGRADE, $changelog, $operator, [
            'dependencies' => $compat,
            'warnings' => $compat['warnings'],
        ]);
        SysLog::info('plugin', "插件已升级：{$name} v{$from} → v{$to}", ['warnings' => $compat['warnings']]);

        return $this->ok([
            'name' => $name,
            'version' => $to,
            'upgraded' => true,
            'changelog' => $changelog,
            'warnings' => $compat['warnings'],
        ]);
    }

    /**
     * 版本历史（时间线）：按插件名查版本变更记录。
     *
     * 响应：{ name, current_version, history: [{ id, from_version, to_version, operation, changelog, upgraded_by, detail, created_at }] }
     */
    public function versionHistory()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }
        $history = PluginVersionHistory::list($name, 50);
        $current = PluginMarket::states();
        $currentVersion = isset($current[$name]) ? (string) $current[$name]['version'] : null;

        return $this->ok([
            'name' => $name,
            'current_version' => $currentVersion,
            'history' => $history,
        ]);
    }

    /**
     * 升级预览：检查兼容性 + 提取变更日志（不执行升级）。
     *
     * 响应：{ name, from_version, to_version, changelog, compatible, blockers, warnings }
     */
    public function upgradePreview()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }
        $plugin = $this->plugin($name);
        $from = (string) (PluginMarket::states()[$name]['version'] ?? '0.0.0');
        $to = method_exists($plugin, 'version') ? (string) $plugin->version() : '1.0.0';

        $compat = PluginVersionHistory::checkCompatibility($name, $from, $to);
        $changelog = PluginVersionHistory::changelogFor($name, $from, $to);

        return $this->ok([
            'name' => $name,
            'from_version' => $from,
            'to_version' => $to,
            'upgradable' => version_compare($to, $from, '>'),
            'changelog' => $changelog,
            'compatible' => $compat['ok'],
            'blockers' => $compat['blockers'],
            'warnings' => $compat['warnings'],
        ]);
    }

    /**
     * 回滚到指定版本（仅更新版本号，不执行代码级回滚）。
     *
     * 请求体：{ target: string }
     * 响应：{ ok, message, version }
     */
    public function rollback()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }
        $all = Request::all();
        $target = trim((string) ($all['target'] ?? ''));
        if ($target === '') {
            return $this->fail('回滚目标版本必填', 400);
        }
        $operator = (string) (Request::user()?->uid ?? 'system');
        $result = PluginVersionHistory::rollback($name, $target, $operator);
        if (!$result['ok']) {
            return $this->fail($result['message'], 400);
        }
        SysLog::info('plugin', "插件已回滚：{$name} → v{$target}", ['operator' => $operator]);

        return $this->ok($result);
    }

    /**
     * 打包下载（供官网市场收录 / 迁移）：插件类 + 控制器 + plugin.json 清单。
     */
    public function package()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }
        $zip = \app\support\PluginPackager::pack($name);
        if ($zip === null) {
            return $this->fail('打包失败', 500);
        }

        return \app\support\PluginPackager::download($zip, "plugin_{$name}.zip");
    }

    /**
     * 从市场远程安装：下载 → sha256 强制校验 → 安全解包 → 落盘 + 登记。
     * 路由/插件类启动期加载，装完需 reload 生效（响应里明示）。
     */
    public function installRemote()
    {
        $data = $this->validate(Request::all(), [
            'url' => 'required|min:10|max:500',
            'sha256' => 'required|min:64|max:64',
            'name' => 'required|min:2|max:32',
        ]);

        $result = \app\support\PluginPackager::installRemote(
            (string) $data['url'],
            strtolower((string) $data['sha256']),
            strtolower((string) $data['name'])
        );
        if (!($result['ok'] ?? false)) {
            \app\support\SysLog::error('plugin', '远程安装失败：' . (string) ($data['name'] ?? ''), ['msg' => (string) ($result['msg'] ?? '')]);

            return $this->fail((string) ($result['msg'] ?? '安装失败'), 400);
        }
        \app\support\SysLog::info('plugin', '远程安装成功：' . (string) ($data['name'] ?? ''), ['files' => $result['files'] ?? 0]);

        return $this->ok($result + ['reload' => '新插件路由需 `php kode reload`（或重启）后生效']);
    }

    /**
     * 脚手架生成：程序化调用 PluginScaffolder 创建插件骨架。
     *
     * 请求体：{ name: string, register?: bool, force?: bool }
     * 响应：{ class, name, files: { class, manifest }, registered, skipped, message }
     *
     * 与 CLI `kode make:plugin <Name>` 共享同一套命名规范化与生成逻辑；
     * 生成的插件需 reload 后框架启动期才加载（路由/类启动期解析）。
     */
    public function scaffold()
    {
        $all = Request::all();
        $name = trim((string) ($all['name'] ?? ''));
        if ($name === '') {
            return $this->fail('插件名必填', 400);
        }

        $register = filter_var($all['register'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $force = filter_var($all['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $basePath = dirname(__DIR__, 3);
        try {
            $result = (new PluginScaffolder($basePath))->scaffold($name, $register, $force);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return $this->fail('文件写入失败：' . $e->getMessage(), 500);
        }

        if ($result['skipped']) {
            \app\support\SysLog::info('plugin', '脚手架跳过（已存在）：' . $result['name']);

            return $this->ok($result + ['reload' => '']);
        }

        \app\support\SysLog::info('plugin', '脚手架生成：' . $result['class'], [
            'registered' => $result['registered'],
        ]);

        return $this->ok($result + [
            'reload' => '新插件路由需 `php kode reload`（或重启）后生效',
            'next_steps' => [
                "编辑 {$result['files']['class']} 实现业务逻辑",
                'php kode plugin:audit 审计契约',
                'php kode plugin:deps 查看依赖',
            ],
        ]);
    }

    /**
     * 重置配置为默认值：删除已保存行，运行时回落 schema default。
     *
     * 响应：{ name, deleted, fields }
     */
    public function resetConfig()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        $result = PluginConfig::reset($name);
        \app\support\SysLog::info('plugin', "插件配置已重置：{$name}", ['deleted' => $result['deleted'], 'fields' => $result['fields']]);

        return $this->ok($result + ['name' => $name]);
    }

    /**
     * 运行时健康检查：读取路由/命令/事件/定时任务/菜单/配置/驱动/依赖的注册状态。
     *
     * 响应：{ name, status: healthy|degraded|unhealthy, summary, checks: { routes, commands, ... } }
     *
     * 与 contract() 的区别：contract() 做声明⟺实现零漂移校验（契约合规性），
     * 本方法做运行时状态诊断（实际有多少东西在跑）。
     */
    public function health()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        $plugin = $this->plugin($name);
        $health = PluginHealthChecker::check($plugin);

        return $this->ok($health + ['name' => $name]);
    }

    /**
     * 全量审计报表：聚合所有插件的契约 + 健康 + 依赖。
     *
     * 响应：{ summary, plugins: [{ name, version, status, health_status, ... }], generated_at }
     *
     * 只读：不调用 install/uninstall/upgrade，仅读取声明 + 运行时注册状态。
     */
    public function auditReport()
    {
        $report = \app\support\PluginAuditReport::generate();

        return $this->ok($report);
    }

    /**
     * 审计报表导出（JSON）。
     */
    public function auditReportExport()
    {
        $json = \app\support\PluginAuditReport::export();

        return new \Kode\Http\Response($json, 200, [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="plugin_audit_report_' . date('Ymd_His') . '.json"',
        ]);
    }

    /**
     * 审计报表导出（CSV）。
     */
    public function auditReportExportCsv()
    {
        $csv = \app\support\PluginAuditReport::exportCsv();

        return new \Kode\Http\Response($csv, 200, [
            'Content-Type'  => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="plugin_audit_report_' . date('Ymd_His') . '.csv"',
        ]);
    }

    /**
     * 插件日志：按插件名过滤系统日志（module=plugin，message 或 context 含插件名）。
     *
     * 响应：分页 { data: [{ id, level, module, message, context, ip, created_at }], total, page, size }
     */
    public function logs()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }
        $page = max(1, (int) $this->param('page', 1));
        $size = min(100, max(1, (int) $this->param('size', 20)));
        $level = substr((string) $this->param('level', ''), 0, 16);

        $where = "module = 'plugin' AND (message LIKE ? OR context LIKE ?)";
        $params = ["%{$name}%", "%{$name}%"];
        if ($level !== '') {
            $where .= ' AND level = ?';
            $params[] = $level;
        }
        $cond = 'WHERE ' . $where;

        $total = (int) (\Kode\Database\Db\Db::select("SELECT COUNT(*) AS c FROM kode_system_logs {$cond}", $params)[0]['c'] ?? 0);
        $params[] = $size;
        $params[] = ($page - 1) * $size;
        $rows = \Kode\Database\Db\Db::select(
            "SELECT id, level, module, message, context, ip, created_at FROM kode_system_logs {$cond} ORDER BY id DESC LIMIT ? OFFSET ?",
            $params
        );
        foreach ($rows as &$row) {
            if ($row['created_at'] instanceof \DateTimeInterface) {
                $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
            }
        }
        unset($row);

        return $this->page($rows, $total, $page, $size);
    }

    /**
     * 版本对比：比较两个版本的 plugin.json 清单差异。
     *
     * 请求参数：{ from: string, to: string }
     * 响应：{ name, from_version, to_version, added, removed, changed, same }
     */
    public function versionCompare()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        $all = Request::all();
        $from = trim((string) ($all['from'] ?? ''));
        $to = trim((string) ($all['to'] ?? ''));

        if ($from === '' || $to === '') {
            return $this->fail('from 和 to 版本均必填', 400);
        }

        // 从版本历史中查找对应版本的 changelog 和 detail
        $history = PluginVersionHistory::list($name, 50);
        $fromDetail = null;
        $toDetail = null;
        foreach ($history as $h) {
            if ((string) $h['from_version'] === $from) {
                $fromDetail = $h;
            }
            if ((string) $h['to_version'] === $to) {
                $toDetail = $h;
            }
        }

        // 对比 changelog
        $fromChangelog = $fromDetail['changelog'] ?? '';
        $toChangelog = $toDetail['changelog'] ?? '';

        // 对比 detail（JSON 结构）
        $fromJson = json_decode($fromDetail['detail'] ?? '{}', true) ?? [];
        $toJson = json_decode($toDetail['detail'] ?? '{}', true) ?? [];

        $added = [];
        $removed = [];
        $changed = [];
        $same = [];

        foreach ($toJson as $key => $value) {
            if (!array_key_exists($key, $fromJson)) {
                $added[$key] = $value;
            } elseif ($value !== $fromJson[$key]) {
                $changed[$key] = ['from' => $fromJson[$key], 'to' => $value];
            } else {
                $same[$key] = $value;
            }
        }
        foreach ($fromJson as $key => $value) {
            if (!array_key_exists($key, $toJson)) {
                $removed[$key] = $value;
            }
        }

        return $this->ok([
            'name'         => $name,
            'from_version' => $from,
            'to_version'   => $to,
            'from_changelog' => $fromChangelog,
            'to_changelog'   => $toChangelog,
            'added'    => $added,
            'removed'  => $removed,
            'changed'  => $changed,
            'same'     => $same,
        ]);
    }

    /**
     * 市场健康检测：验证市场 API 可达性 + 返回延迟。
     *
     * 响应：{ ok, url, latency_ms, message, plugins? }
     */
    public function marketplaceHealth()
    {
        return $this->ok(\app\support\PluginMarketplace::health());
    }

    /**
     * 市场插件列表：代理市场 API 获取插件列表（兼容两种响应形态）。
     *
     * 响应：{ ok, plugins: [{ name, version, description, package_url, sha256 }], message }
     */
    public function marketplaceList()
    {
        return $this->ok(\app\support\PluginMarketplace::list());
    }

    /**
     * 更新检查：对比已安装版本与市场版本，返回可更新列表。
     *
     * 响应：{ ok, updates: [{ name, current_version, available_version, upgradeable }], total, message }
     */
    public function checkUpdates()
    {
        return $this->ok(\app\support\PluginMarketplace::checkUpdates());
    }

    /**
     * 插件文件列表：列出插件目录内的所有文件（大小/修改时间/类型）。
     *
     * 响应：{ ok, name, base_path, files: [{ path, size, human_size, extension, modified_at, is_main }], total, total_human }
     */
    public function files()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        return $this->ok(\app\support\PluginFileExplorer::list($name));
    }

    /**
     * 读取单个文件源码（只读，限 500KB）。
     *
     * 查询参数：path（相对路径，如 notices/Controller.php）
     * 响应：{ ok, name, path, content, size }
     */
    public function fileRead()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        $path = (string) $this->param('path');
        if ($path === '' || str_contains($path, '..')) {
            return $this->fail('路径非法', 400);
        }

        return $this->ok(\app\support\PluginFileExplorer::read($name, $path));
    }

    /**
     * 运行时详情：路由/事件/定时/命令/菜单的完整条目。
     *
     * 响应：{ ok, name, routes: [{ path, methods, source }], events: [{ event, listeners, registered }], crons: [{ name, expression, description, enabled }], commands: [{ class, name, registered }], menus: [{ path, title }] }
     */
    public function runtimeDetail()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        return $this->ok(\app\support\PluginRuntimeDetail::detail($name));
    }

    /**
     * 批量暂停：级联暂停下游依赖者。
     *
     * 请求体：{ names: string[] }
     * 响应：{ ok, paused: string[], cascaded: string[], skipped: string[] }
     */
    public function batchPause()
    {
        $all = Request::all();
        $names = (array) ($all['names'] ?? []);
        $discovered = PluginMarket::discover();

        $paused = [];
        $skipped = [];
        $allCascaded = [];

        foreach ($names as $raw) {
            $name = strtolower(trim((string) $raw));
            if (!isset($discovered[$name])) {
                $skipped[] = $name;
                continue;
            }
            PluginMarket::setStatus($name, PluginMarket::PAUSED);
            $cascaded = [];
            foreach (PluginDependency::dependents($name) as $dep) {
                if (PluginMarket::statusOf($dep) !== PluginMarket::PAUSED) {
                    PluginMarket::setStatus($dep, PluginMarket::PAUSED);
                    $cascaded[] = $dep;
                }
            }
            $paused[] = $name;
            $allCascaded = array_merge($allCascaded, $cascaded);
        }

        \app\support\SysLog::info('plugin', '批量暂停', ['paused' => $paused, 'cascaded' => $allCascaded, 'skipped' => $skipped]);

        return $this->ok([
            'paused'   => array_unique($paused),
            'cascaded' => array_unique($allCascaded),
            'skipped'  => $skipped,
        ]);
    }

    /**
     * 批量启用：依赖校验通过后恢复为 enabled。
     *
     * 请求体：{ names: string[] }
     * 响应：{ ok, resumed: string[], failed: [{ name, reason }] }
     */
    public function batchResume()
    {
        $all = Request::all();
        $names = (array) ($all['names'] ?? []);
        $discovered = PluginMarket::discover();

        $resumed = [];
        $failed = [];

        foreach ($names as $raw) {
            $name = strtolower(trim((string) $raw));
            if (!isset($discovered[$name])) {
                $failed[] = ['name' => $name, 'reason' => '插件不存在'];
                continue;
            }
            $dep = PluginDependency::validate($name);
            if (!$dep['ok']) {
                $failed[] = ['name' => $name, 'reason' => '依赖未就绪'];
                continue;
            }
            PluginMarket::setStatus($name, PluginMarket::ENABLED);
            $resumed[] = $name;
        }

        \app\support\SysLog::info('plugin', '批量启用', ['resumed' => $resumed, 'failed' => $failed]);

        return $this->ok([
            'resumed' => $resumed,
            'failed'  => $failed,
        ]);
    }

    /**
     * 批量卸载：逐个卸载（不含 purge），需 confirm。
     *
     * 请求体：{ names: string[], confirm: bool }
     * 响应：{ ok, uninstalled: string[], skipped: string[] }
     */
    public function batchUninstall()
    {
        $all = Request::all();
        $names = (array) ($all['names'] ?? []);
        $confirm = filter_var($all['confirm'] ?? null, FILTER_VALIDATE_BOOLEAN);
        if (!$confirm) {
            return $this->fail('卸载需 confirm=true 二次确认', 400);
        }
        $discovered = PluginMarket::discover();

        $uninstalled = [];
        $skipped = [];

        foreach ($names as $raw) {
            $name = strtolower(trim((string) $raw));
            if (!isset($discovered[$name])) {
                $skipped[] = $name;
                continue;
            }
            PluginMarket::setStatus($name, PluginMarket::UNINSTALLED);
            $uninstalled[] = $name;
        }

        \app\support\SysLog::info('plugin', '批量卸载', ['uninstalled' => $uninstalled, 'skipped' => $skipped]);

        return $this->ok([
            'uninstalled' => $uninstalled,
            'skipped'     => $skipped,
        ]);
    }

    /** @return string|null 仅允许已发现插件名（防任意类实例化） */
    private function pluginName(): ?string
    {
        $name = (string) $this->param('name');
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return null;
        }

        return isset(PluginMarket::discover()[$name]) ? $name : null;
    }

    private function plugin(string $name): object
    {
        $class = PluginMarket::discover()[$name];

        return new $class();
    }
}
