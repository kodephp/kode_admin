<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\support\Clip;
use app\support\PluginConfig;
use app\support\PluginContract;
use app\support\PluginDependency;
use app\support\PluginDependencyResolver;
use app\support\PluginMarket;
use app\support\PluginHealthChecker;
use app\support\PluginMetrics;
use app\support\PluginScaffolder;
use app\support\PluginScheduleSync;
use app\support\PluginVersionHistory;
use app\support\SysLog;
use Kode\Framework\Http\Controller;
use app\concerns\ApiResponse;
use app\concerns\PageWindow;
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
    use PageWindow;

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
                'description'       => $desc['description'] ?? '',
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
        // 先取「装之前登记的是什么」：setStatus 会凭空建一行并送 version=1.0.0 默认值，
        // 之后再读就分不清「本来就在 1.0.0」与「压根没装过」（见 changeVersion 的 $from）。
        $prior = (string) (PluginMarket::states()[$name]['version'] ?? '');
        $plugin = $this->plugin($name);
        if (method_exists($plugin, 'install')) {
            $plugin->install();
        }
        $version = $this->declaredVersion($plugin);
        PluginMarket::setStatus($name, PluginMarket::ENABLED);
        PluginVersionHistory::changeVersion($name, $version, PluginVersionHistory::OP_INSTALL, '', $this->actorUid(), [], $prior);
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
        // 下游依赖者还在启用时拆掉被依赖方 = 它们当场失去服务/事件源（表也可能被 purge 掉）。
        // install/resume/upgrade 都有依赖门禁，唯独「拆」这一步没有——破口留在最贵的一处。
        $blocked = $this->blockingDependents($name);
        if ($blocked !== []) {
            return $this->fail(
                '仍有已启用的下游依赖者：' . implode(', ', $blocked)
                . '。先卸载它们，或先 pause 本插件（会级联暂停下游）再卸载。',
                400,
                ['dependents' => $blocked]
            );
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
            'backup_hint' => self::backupHint($name, $tables, $purge),
        ]);
    }

    /**
     * 卸载响应里的备份提示：给得出可直接执行的命令，但不带连接凭证。
     *
     * 提示曾是 `pg_dump -h <host> -U <user> -d <db>`：卸载响应把 DB 账号和库名送到浏览器，
     * 落进前端日志/代理日志/截图都是白送的侦察面（§4 凭证只在服务端用）。
     * 连接参数改由本机 PGHOST/PGUSER/PGDATABASE 或 ~/.pgpass 提供，命令照抄即可跑。
     *
     * @param list<string> $tables
     */
    private static function backupHint(string $name, array $tables, bool $purge): string
    {
        if ($purge) {
            return '已删除表 ' . implode(',', $tables) . '，请确认已有备份';
        }
        if ($tables === []) {
            return '数据已保留；该插件未声明表，无需备份';
        }

        return '数据已保留；备份命令（连接参数取本机的 PGHOST/PGUSER/PGDATABASE 或 ~/.pgpass）：pg_dump '
            . implode(' ', array_map(static fn ($t) => '-t ' . escapeshellarg((string) $t), $tables))
            . ' > plugin_' . $name . '_backup.sql';
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
        $row = PluginMarket::states()[$name] ?? null;
        // 没登记过就别假装「从 0.0.0 升上来」：账本里写 0.0.0 是给回滚当候选用的假版本
        $from = (string) ($row['version'] ?? '');
        if ($row === null) {
            return $this->fail("插件 '{$name}' 尚未安装，先安装再升级", 400);
        }
        $to = $this->declaredVersion($plugin);
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
        // 改版本列 + 留账本走同一派发口（此前这里是 setVersion 与 record 各调一次，
        // 其余三处只调了前者，账本于是只记了四分之一的账）
        PluginVersionHistory::changeVersion($name, $to, PluginVersionHistory::OP_UPGRADE, $changelog, $this->actorUid(), [
            'dependencies' => $compat,
            'warnings' => $compat['warnings'],
        ], $from);
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
        // 预览与执行必须同一口径，否则「预览说可升级、点升级被挡」；未安装时 from 留空串
        $from = (string) (PluginMarket::states()[$name]['version'] ?? '');
        $to = $this->declaredVersion($plugin);

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
        $operator = $this->actorUid();
        $result = PluginVersionHistory::rollback($name, $target, $operator);
        if (!$result['ok']) {
            return $this->fail($result['message'], 400, ['candidates' => $result['candidates'] ?? []]);
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
            strtolower((string) $data['name']),
            $this->actorUid()
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
        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];
        $level = Clip::chars((string) $this->query('level', ''), 16);

        $where = "module = 'plugin' AND (message LIKE ? OR context LIKE ?)";
        $params = ["%{$name}%", "%{$name}%"];
        if ($level !== '') {
            $where .= ' AND level = ?';
            $params[] = $level;
        }
        $cond = 'WHERE ' . $where;

        $total = (int) (\Kode\Database\Db\Db::select("SELECT COUNT(*) AS c FROM kode_system_logs {$cond}", $params)[0]['c'] ?? 0);
        $params[] = $size;
        $params[] = $offset;
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
     * 读取单个文件源码（只读，上限见 PluginFileExplorer::MAX_FILE_BYTES）。
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

        // 恒走 query()：这条路由的 pattern 只有 {name}，path 是查询串带来的。
        // 曾用 param()（= 只读 _route_params，不回落 query/body），于是每次都取到空串，
        // 下面的「路径非法」400 变成**无条件返回** —— 代码编辑器点开任何文件都是 400。
        $path = (string) $this->query('path', '');
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
     * 响应：{ ok, uninstalled: string[], skipped: string[], blocked: [{ name, dependents }] }
     * 传 purge=1 直接 400：本接口不删表，也不假装删了。
     *
     * 部分成功是允许的，但**只在依赖链自洽的前提下**：一条卸不掉的记录会连带
     * 拿掉它给上游的豁免，见 batchUninstall() 里的不动点。
     */
    public function batchUninstall()
    {
        $all = Request::all();
        $names = (array) ($all['names'] ?? []);
        $confirm = filter_var($all['confirm'] ?? null, FILTER_VALIDATE_BOOLEAN);
        if (!$confirm) {
            return $this->fail('卸载需 confirm=true 二次确认', 400);
        }
        // purge 在这里静默无效 = 「以为删了表、其实只翻了状态」，与单插件接口的语义正好相反；
        // 批量路径不碰数据，要清表就回到逐个确认的 /uninstall。
        if (filter_var($all['purge'] ?? null, FILTER_VALIDATE_BOOLEAN)) {
            return $this->fail('批量卸载不删表：请逐个调用 POST /api/plugins/{name}/uninstall?purge=1', 400);
        }
        $discovered = PluginMarket::discover();

        // 归一一次（trim + 小写 + 去重），三份清单都从这一份派生，别把同一串 normalize 写三遍。
        // 值 = 这个名字是否真实存在。
        $requested = [];
        foreach ($names as $raw) {
            $name = strtolower(trim((string) $raw));
            if (!array_key_exists($name, $requested)) {
                $requested[$name] = isset($discovered[$name]);
            }
        }

        // 本批一起卸的名字互不阻塞：否则 [依赖方, 被依赖方] 的成败取决于用户给的顺序，
        // 报错还指向「顺序不对」这种假因。
        // 豁免只发给「剔除掉本批卸不掉的之后仍然留在批内」的那些（求不动点）：
        // 「不阻塞」的前提是对方真的会被卸掉，卸不掉的成员不能替上游背书。
        // 附带效果是 blocked 报的是**批处理完后真正还活着**的阻塞方，
        // 而不是「批外那几个」这种单轮视图——否则运维照清单清完批外下游，重试仍被同批的半成品挡住。
        $candidates = [];
        foreach ($requested as $name => $known) {
            if ($known) {
                $candidates[$name] = true;
            }
        }
        do {
            $pruned = false;
            foreach (array_keys($candidates) as $name) {
                if ($this->blockingDependents($name, $candidates) !== []) {
                    unset($candidates[$name]);
                    $pruned = true;
                }
            }
        } while ($pruned);

        $uninstalled = [];
        $skipped = [];
        $blocked = [];
        foreach ($requested as $name => $known) {
            if (!$known) {
                $skipped[] = $name;
            } elseif (isset($candidates[$name])) {
                PluginMarket::setStatus($name, PluginMarket::UNINSTALLED);
                $uninstalled[] = $name;
            } else {
                // 被剔除的按**最终**候选集报阻塞方：报「谁还活着挡住它」，不是它那一轮的临时原因。
                $blocked[] = ['name' => $name, 'dependents' => $this->blockingDependents($name, $candidates)];
            }
        }

        \app\support\SysLog::info('plugin', '批量卸载', [
            'uninstalled' => $uninstalled,
            'skipped'     => $skipped,
            'blocked'     => $blocked,
        ]);

        return $this->ok([
            'uninstalled' => $uninstalled,
            'skipped'     => $skipped,
            'blocked'     => $blocked,
            'hint'        => $blocked === [] ? '' : '有插件仍被已启用的下游依赖者引用，未卸载：见 blocked 列表',
        ]);
    }

    /**
     * 写入文件内容（代码编辑器保存）。
     *
     * 请求体：{ path: string, content: string }
     * 响应：{ ok, name, path, size, message }
     */
    public function fileWrite()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        $all = Request::all();
        $path = (string) ($all['path'] ?? '');
        $content = (string) ($all['content'] ?? '');

        if ($path === '' || $content === '') {
            return $this->fail('path 和 content 必填', 400);
        }

        $result = \app\support\PluginFileExplorer::write($name, $path, $content);
        if (!$result['ok']) {
            return $this->fail($result['message'], 400);
        }

        return $this->ok($result);
    }

    /**
     * 导出插件备份（JSON）。
     *
     * 响应：{ ok, name, json, missing: string[], message }（`missing` 是导出时读不动的条目）
     */
    public function backup()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        $result = \app\support\PluginBackupService::export($name);
        if (!$result['ok']) {
            return $this->fail($result['message'], 400);
        }

        return $this->ok($result);
    }

    /**
     * 从 JSON 恢复插件。
     *
     * 请求体：{ json: string }
     * 响应：{ ok, restored: string[], skipped: string[], failed: {插件名: [原因]}, message }
     * 部分失败也算失败：400 + `errors` 带每个插件未落盘的原因。
     */
    public function restore()
    {
        $all = Request::all();
        $json = (string) ($all['json'] ?? '');

        if ($json === '') {
            return $this->fail('json 必填', 400);
        }

        $result = \app\support\PluginBackupService::import($json, $this->actorUid());
        if (!$result['ok']) {
            return $this->fail($result['message'], 400, ['errors' => ['restore' => $result['failed']]]);
        }

        return $this->ok($result);
    }

    /**
     * 代码搜索：跨插件文件全文搜索关键词。
     *
     * 查询参数：keyword（必填）、plugin（可选，限定插件名）
     * 响应：{ ok, keyword, files: [{ path, line, context, filename }], total, message }
     */
    public function codeSearch()
    {
        $keyword = (string) $this->query('keyword', '');
        $plugin = $this->query('plugin');
        $pluginName = $plugin !== null ? (string) $plugin : null;

        $result = \app\support\PluginCodeSearcher::search($keyword, $pluginName);
        if (!$result['ok']) {
            return $this->fail($result['message'], 400);
        }

        return $this->ok($result);
    }

    /**
     * 依赖树：以指定插件为根构建依赖关系树。
     *
     * 响应：{ ok, name, tree: [{ name, status, version, children }], roots, cycle, message }
     */
    public function dependencyTree()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        return $this->ok(\app\support\PluginDependencyTree::tree($name));
    }

    /**
     * 全量依赖图：所有插件的依赖树结构。
     *
     * 响应：{ ok, trees, cycle_plugins, message }
     */
    public function dependencyTreeAll()
    {
        return $this->ok(\app\support\PluginDependencyTree::all());
    }

    /**
     * API 文档：从路由表生成插件 API 文档。
     *
     * 响应：{ ok, name, version, generated_at, endpoints: [{ method, path, params, summary, group }], total, message }
     */
    public function apiDoc()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        return $this->ok(\app\support\PluginApiDoc::generate($name));
    }

    /**
     * 插件统计面板：聚合所有插件的运行时指标。
     *
     * 响应：{ ok, generated_at, totals: { plugins, files, routes, ... }, plugins: [...], message }
     */
    public function metrics()
    {
        return $this->ok(PluginMetrics::summary());
    }

    /**
     * 全局代码搜索：跨所有插件搜索关键词（无需先选插件名）。
     *
     * 查询参数：keyword（必填）、plugin（可选，限定插件名）
     * 响应：{ ok, keyword, files: [{ path, line, context, filename }], total, message }
     */
    public function globalCodeSearch()
    {
        $keyword = (string) $this->query('keyword', '');
        if ($keyword === '') {
            return $this->fail('keyword 必填', 400);
        }
        $plugin = $this->query('plugin');
        $pluginName = $plugin !== null ? (string) $plugin : null;

        $result = \app\support\PluginCodeSearcher::search($keyword, $pluginName);
        if (!$result['ok']) {
            return $this->fail($result['message'], 400);
        }

        return $this->ok($result);
    }

    /**
     * 插件定时任务快照：该插件在调度器中的任务列表（含禁用占位）。
     *
     * 响应：{ ok, name, tasks: [{ name, expression, enabled, target }], total }
     */
    public function scheduleTasks()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        $tasks = PluginScheduleSync::tasksOf($name);

        return $this->ok([
            'ok'    => true,
            'name'  => $name,
            'tasks' => $tasks,
            'total' => count($tasks),
        ]);
    }

    /**
     * API 文档 Markdown 导出：生成可粘贴到文档的 Markdown 格式。
     *
     * 响应：{ ok, name, markdown, total, generated_at }
     */
    public function apiDocMarkdown()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        $doc = \app\support\PluginApiDoc::generate($name);
        if (!$doc['ok']) {
            return $this->fail($doc['message'], 400);
        }

        $markdown = $this->docToMarkdown($doc);

        return $this->ok([
            'ok'           => true,
            'name'         => $name,
            'markdown'     => $markdown,
            'total'        => $doc['total'],
            'generated_at' => $doc['generated_at'],
        ]);
    }

    /**
     * 依赖拓扑排序：返回安装/卸载/升级的正确顺序。
     *
     * 响应：{ ok, install_order, uninstall_order, upgrade_order, cycles, total, resolved, circular, message }
     */
    public function dependencyResolve()
    {
        return $this->ok(PluginDependencyResolver::resolve());
    }

    /**
     * 单插件安装前置条件：检查该插件的所有依赖是否就绪。
     *
     * 响应：{ ok, name, requires: [{ name, version, installed, status, ready }], all_ready, message }
     */
    public function prerequisites()
    {
        $name = $this->pluginName();
        if ($name === null) {
            return $this->fail('不存在', 404);
        }

        $result = PluginDependencyResolver::prerequisitesOf($name);
        if (!$result['ok']) {
            return $this->fail($result['message'], 400);
        }

        return $this->ok($result);
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

    /**
     * 插件自述版本（`version()` 是权威口径，`kode_plugins.version` 只是登记镜像）。
     *
     * 三处调用点（install / upgrade / 预览）各写一遍 `method_exists` 兜底，
     * 早晚会只改对一个；'1.0.0' 也必须是**一处**默认值。
     */
    private function declaredVersion(object $plugin): string
    {
        return method_exists($plugin, 'version') ? (string) $plugin->version() : '1.0.0';
    }

    /**
     * 记账用的操作者：登录态取 uid，CLI/无鉴权路径落 'system'。
     */
    private function actorUid(): string
    {
        return (string) (Request::user()?->uid ?? 'system');
    }

    /**
     * 拆掉本插件后会当场失效的下游依赖者（只认「已启用」的那些）。
     *
     * 已暂停/已卸载/无状态记录的下游不必拦：前者要恢复时由 resume 的依赖门禁兜住，
     * 后两者本来没在跑。$alsoUninstall 是本批次同时会被卸掉的名字（批量卸载传入），
     * 否则用户提交 [依赖方, 被依赖方] 会被自己挡下，报错还指向「顺序不对」这种假因。
     *
     * @param array<string, true> $alsoUninstall
     * @return list<string>
     */
    private function blockingDependents(string $name, array $alsoUninstall = []): array
    {
        $out = [];
        foreach (PluginDependency::dependents($name) as $dep) {
            if (isset($alsoUninstall[$dep]) || PluginMarket::statusOf($dep) !== PluginMarket::ENABLED) {
                continue;
            }
            $out[] = $dep;
        }

        return $out;
    }

    /**
     * 将 API 文档转为 Markdown 格式。
     */
    private function docToMarkdown(array $doc): string
    {
        $lines = [];
        $lines[] = "# API 文档：{$doc['name']}";
        $lines[] = '';
        $lines[] = "**版本**：v{$doc['version']}  ";
        $lines[] = "**生成时间**：{$doc['generated_at']}  ";
        $lines[] = "**端点总数**：{$doc['total']}";
        $lines[] = '';

        // 按 group 分组
        $groups = [];
        foreach ($doc['endpoints'] as $ep) {
            $group = $ep['group'] ?? '其他';
            if (!isset($groups[$group])) {
                $groups[$group] = [];
            }
            $groups[$group][] = $ep;
        }

        foreach ($groups as $groupName => $endpoints) {
            $lines[] = "## {$groupName}";
            $lines[] = '';
            foreach ($endpoints as $ep) {
                $method = strtoupper($ep['method']);
                $path = $ep['path'];
                $summary = $ep['summary'];
                $params = $ep['params'] ?? [];

                $lines[] = "### `{$method} {$path}`";
                $lines[] = '';
                $lines[] = "> {$summary}";
                $lines[] = '';

                if (!empty($params)) {
                    $lines[] = '**路径参数：**';
                    $lines[] = '';
                    foreach ($params as $p) {
                        $lines[] = "- `{$p}`";
                    }
                    $lines[] = '';
                }

                $lines[] = '```';
                $lines[] = "{$method} {$path}";
                $lines[] = '```';
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }
}
