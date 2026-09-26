<?php

declare(strict_types=1);

namespace app\support;

use app\admin\middleware\RequirePerm;
use Kode\Framework\Plugin\PluginInterface;

/**
 * 插件能力契约（声明式元数据 + 声明⟺实现零漂移审计）
 *
 * 为什么需要它：PluginInterface 只有 3 个强制方法，其余能力（版本/表/菜单/
 * 驱动/权限/事件/命令/cron）全靠"约定 + method_exists 探测"。约定漂移了没人知道——
 * 典型症状是「配置中心里能建 oss 驱动，实际推送不了」。本类把约定收敛成
 * 单一 SoT：CAPABILITY_SCHEMA 声明每个能力对应哪个方法，describe() 统一读取，
 * audit() 校验声明与实际实现严格一致。
 *
 * 使用：
 *   PluginContract::describe($plugin);   // 标准能力清单（供 API / 前端展示）
 *   PluginContract::audit($plugin);      // ['ok'=>bool,'drift'=>list<string>]
 *   PluginContract::capabilitiesOf($plugin); // 能力名数组（如 ['storage_driver']）
 *
 * 设计取舍：不新增接口继承层次（插件只需实现 PluginInterface），能力一律
 * 通过方法探测，保持与既有插件的零成本兼容；schema 变更只改本类一处。
 */
final class PluginContract
{
    /**
     * 能力 schema：能力名 => [方法名, 必需, 分组]。
     *
     * 「必需」= true 表示能力一旦声明就必须有对应实现（零漂移红线）。
     * 分组用于前端按类聚合展示（生命周期/数据/对外暴露/集成/配置）。
     *
     * @var array<string, array{0: string, 1: bool, 2: string}>
     */
    public const CAPABILITY_SCHEMA = [
        // ---- 元数据 ----
        'version'      => ['version', false, 'meta'],
        'license'      => ['license', false, 'meta'],
        'author'       => ['author', false, 'meta'],
        // ---- 生命周期（安装/卸载/升级） ----
        'lifecycle_install'   => ['install', true, 'lifecycle'],
        'lifecycle_uninstall' => ['uninstall', true, 'lifecycle'],
        'lifecycle_upgrade'   => ['upgrade', true, 'lifecycle'],
        // ---- 数据 ----
        'tables' => ['tables', false, 'data'],
        // ---- 对外暴露 ----
        'menus'    => ['menus', false, 'expose'],
        'commands' => ['commands', false, 'expose'],
        'events'   => ['events', false, 'expose'],
        'crons'    => ['crons', false, 'expose'],
        // ---- 集成：依赖其它插件 ----
        'requires' => ['requires', false, 'integration'],
        // ---- 配置：声明 schema 即可由后台生成配置页 ----
        'config_schema' => ['configSchema', true, 'config'],
        // ---- 权限：声明即可进入权限目录供角色勾选 ----
        'permissions' => ['permissions', false, 'config'],
        // ---- 驱动：插件向平台注册的能力实现（如存储驱动/队列驱动） ----
        'drivers' => ['drivers', true, 'config'],
    ];

    /** 驱动名格式：小写字母开头，2–32 位小写字母/数字/下划线。 */
    public const DRIVER_NAME_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/';

    /** 表名格式：字母或下划线开头，仅含字母/数字/下划线，最长 63 位（PostgreSQL 标识符上限）。 */
    public const TABLE_NAME_PATTERN = '/^[a-z_][a-z0-9_]{0,62}$/';

    /** 能力分组中文名（前端展示用）。 */
    public const GROUP_LABELS = [
        'meta'        => '元数据',
        'lifecycle'   => '生命周期',
        'data'        => '数据',
        'expose'      => '对外暴露',
        'integration' => '集成依赖',
        'config'      => '配置与驱动',
    ];

    /**
     * 读取插件的标准能力清单。
     *
     * 返回值结构：
     *   ['name'=>string, 'class'=>string, 'version'=>string,
     *    'capabilities'=>list<string>, 'groups'=>array<group, list<string>>,
     *    'tables'=>list<string>, 'menus'=>list<array>, 'commands'=>list, 'events'=>list,
     *    'crons'=>list, 'requires'=>list<string>, 'permissions'=>list<string>,
     *    'drivers'=>list<string>, 'config_schema'=>array, 'meta'=>array,
     *    'errors'=>list<string>]
     *
     * `errors` 记录「方法声明了但执行失败」的能力（区别于未声明）：
     * 能力为空 + 无 error = 真·未声明；能力为空 + 有 error = 环境未就绪，
     * 不能据此判定声明漂移。
     *
     * @param object|PluginInterface $plugin
     */
    public static function describe(object $plugin): array
    {
        $capabilities = self::capabilitiesOf($plugin);
        $grouped = [];
        $errors = [];
        foreach ($capabilities as $cap) {
            $grouped[self::CAPABILITY_SCHEMA[$cap][2]][] = $cap;
        }

        return [
            'name'          => is_object($plugin) && method_exists($plugin, 'name') ? (string) $plugin->name() : '',
            'class'         => $plugin::class,
            'version'       => (string) (method_exists($plugin, 'version') ? $plugin->version() : '1.0.0'),
            // description 是可选方法：插件没实现就返回空串，前端不展示（避免"暂无简介"噪音）
            'description'   => (string) (method_exists($plugin, 'description') ? $plugin->description() : ''),
            'capabilities'  => $capabilities,
            'groups'        => array_filter($grouped, static fn($v) => $v !== []),
            'tables'        => self::listOf($plugin, 'tables', $errors),
            'menus'         => self::listOf($plugin, 'menus', $errors),
            'commands'      => self::listOf($plugin, 'commands', $errors),
            'events'        => self::mapOf($plugin, 'events', $errors),
            'crons'         => self::listOf($plugin, 'crons', $errors),
            // tenantCrons 是可选方法：插件声明"这个任务是多租户任务"，cron 到达时应为所有租户同步执行。
            // 返回任务名数组（string 或 {name, ...} 对象），框架据此在 ScheduleDispatcher 层做租户分派。
            'tenant_crons'  => self::listOf($plugin, 'tenantCrons', $errors),
            'requires'      => self::listOf($plugin, 'requires', $errors),
            'permissions'   => self::listOf($plugin, 'permissions', $errors),
            'drivers'       => self::listOf($plugin, 'drivers', $errors),
            'config_schema' => self::mapOf($plugin, 'configSchema', $errors),
            'meta'          => array_filter([
                'license' => method_exists($plugin, 'license') ? (string) $plugin->license() : null,
                'author'  => method_exists($plugin, 'author') ? (string) $plugin->author() : null,
            ], static fn($v) => $v !== null),
            'errors'        => $errors,
        ];
    }

    /**
     * 声明⟺实现零漂移审计。
     *
     * 检查项（编号 = 阶段）：
     *  0   能力方法执行失败 → pending（环境未就绪，不能据此判定声明漂移）；
     *  1   必需能力：声明了就必须有非空实现（如 configSchema() 返回空 map、
     *      drivers() 返回空列表都视为声明了但没实现）；
     *  1.5 表名格式合法且不重复（须能作为 SQL 标识符，见 audit 内阶段 1.5）；
     *  1.6 版本号格式：version() 的值会写入 plugin.json 与 kode_plugins.version，
     *      再作为 satisfies() 的 $installed 参与依赖门禁——非法值不报错，
     *      只会让所有依赖方落进精确匹配兜底被永久误拒；
     *  2   权限码：menus() 声明了 auth_code 就必须出现在 permissions() 中
     *      （防「菜单能进、权限勾选不到」的静默漂移）；
     *  2.2 菜单形态合法：缺 title / path 不以 / 开头 / 路径重复等（见 auditMenus）；
     *  2.3 门禁码兑现：本插件路由上挂的 RequirePerm 码必须被某个插件声明过
     *      （未声明 = 权限目录勾不到，非超管用户被永久锁死，见 auditPermGates）；
     *  2.5 反向检查：permissions() 声明了但既无菜单引用、也无路由门禁引用 → pending
     *      （被 RequirePerm 真实消费的码不算——写操作路由有门禁但菜单只挂读权限码；
     *      插件整体未声明菜单时不做交叉检查，权限码可能只由前端 v-access 消费）；
     *  3   驱动名：drivers() 返回的必须是非空、不重复、格式合法的能力键；
     *  3.1 跨插件同名驱动冲突（注册表只留先发现者，另一个插件的实现会静默失效）；
     *  3.5 配置 schema：configSchema() 的每个字段必须 label 非空、type 在白名单内、
     *      select 有 options、default 能通过该字段自身校验（见 auditConfigSchema）；
     *  4   依赖：requires() 声明的插件必须存在（未安装不算漂移，只标记 pending）；
     *  4.5 依赖声明格式：requires() 每个元素必须是合法插件名字符串或含 name 键的数组，
     *      且 version 约束必须是 satisfies() 真正能解析的语法（非法表达式会被静默
     *      退化为精确匹配，声明的语义与执行结果不一致 → 漂移，见 auditRequires）；
     *  4.6 crons 兑现校验（框架 v1.4.0 起 PluginManager::addCron() 已是消费方）：
     *      声明⟺实现——声明的每条任务必须真实注册进 ScheduleDispatcher（见 auditCrons）；
     *  4.7 commands 兑现校验：声明的命令类必须真实注册进 Console Kernel（见 auditCommands）；
     *  4.8 events 兑现校验：声明的事件监听必须真实注册进 Event Dispatcher（见 auditEvents）；
     *  5   能力方法执行失败 → pending（与阶段 0 同一口径）。
     *
     * 边界：生命周期能力（install/uninstall/upgrade）带建表/删表副作用，
     * 审计**只校验方法存在与签名形态、绝不调用**——这是有意降级，
     * 避免一次只读审计意外建出表。
     *
     * @return array{ok: bool, drift: list<string>, pending: list<string>}
     */
    public static function audit(object $plugin, ?array $discovered = null): array
    {
        $drift = [];
        $pending = [];

        // 0. 非生命周期能力：执行失败记为待复查，不判漂移。
        foreach (self::CAPABILITY_SCHEMA as $cap => [$method, , $group]) {
            if ($group === 'lifecycle' || !method_exists($plugin, $method)) {
                continue;
            }
            $r = self::call($plugin, $method);
            if ($r['error'] !== null) {
                $pending[] = $cap . '：' . $method . '() 执行失败 ' . $r['error']::class
                    . ' ' . $r['error']->getMessage();
            }
        }

        // 1. 必需能力必须真有非空实现。
        //    注意：
        //    - 生命周期方法有副作用，不裸调（仅校验存在与签名）；
        //    - 带必填参数的方法（如 upgrade($from,$to)）不裸调；
        //    - uninstall(bool $purge=false) 是守卫型方法：$purge=false 时「不删表直接
        //      返回」才是正确行为，故不做空值判定（由 PluginController 的 confirm/purge 门禁兜底）；
        //    - 执行失败已在第 0 步记为 pending，此处跳过（空值判定仅在取到值时进行）。
        foreach (self::CAPABILITY_SCHEMA as $cap => [$method, $required, $group]) {
            if (!$required || $group === 'lifecycle' || !method_exists($plugin, $method)) {
                continue;
            }
            if ($cap === 'lifecycle_uninstall' || self::requiredArgs($plugin, $method) > 0) {
                continue;
            }
            $r = self::call($plugin, $method);
            if ($r['error'] !== null) {
                continue;
            }
            if ($r['value'] === [] || $r['value'] === null || $r['value'] === '') {
                $drift[] = $cap . '：声明了必需能力但 ' . $method . '() 返回空';
            }
        }

        // 1.5 表名合法性：声明了就必须是合法 SQL 标识符
        $tables = array_map('strval', self::listOf($plugin, 'tables'));
        foreach ($tables as $table) {
            if (!preg_match(self::TABLE_NAME_PATTERN, $table)) {
                $drift[] = 'tables：表名「' . $table . '」非法'
                    . '（须字母或下划线开头，仅含字母/数字/下划线，最长 63 位）';
            }
        }
        if (count($tables) !== count(array_unique($tables))) {
            $drift[] = 'tables：表名重复';
        }

        // 1.6 版本号格式：version() 会写入 plugin.json 与 kode_plugins.version，
        //     再作为 PluginDependency::satisfies() 的 $installed 参与依赖门禁。
        //     非法值不报错——只会让所有依赖方落进精确匹配兜底被永久误拒。
        $vr = self::call($plugin, 'version');   // 执行失败已在阶段 0 记为 pending
        if ($vr['exists'] && $vr['error'] === null) {
            $ver = trim((string) $vr['value']);
            if ($ver === '') {
                $drift[] = 'version：version() 声明了但返回空'
                    . '（市场版本显示为空，打包安装后会回落为硬编码默认 1.0.0，与真实版本不符）';
            } elseif (!PluginDependency::isValidVersion($ver)) {
                $drift[] = 'version：版本号「' . $ver . '」非法'
                    . '（须 1-4 段数字，容忍 v 前缀，可带 -预发布 / +构建元数据；'
                    . '该值会进入依赖门禁，非法值会让所有依赖方被永久误拒）';
            }
        }

        // 2. 菜单权限码必须已声明
        $menus = self::listOf($plugin, 'menus');
        $perms = array_map('strval', self::listOf($plugin, 'permissions'));
        if ($menus !== [] && $perms !== []) {
            foreach ($menus as $menu) {
                $code = (string) ($menu['auth_code'] ?? '');
                if ($code !== '' && !in_array($code, $perms, true)) {
                    $drift[] = "permissions：菜单「{$menu['title']}」声明了权限码 {$code}，但未在 permissions() 中声明";
                }
            }
        }

        // 2.2 菜单形态校验：声明了就必须能生成可点击的菜单项
        $drift = array_merge($drift, self::auditMenus($plugin));

        // 2.3 门禁码兑现：本插件路由上挂的 RequirePerm 码必须被某个插件声明过
        //     （未声明 = 权限目录勾不到，非超管用户被永久锁死）
        $drift = array_merge($drift, self::auditPermGates($plugin));

        // 2.5 反向检查：声明了权限但既无菜单引用、也无路由门禁引用 → pending。
        //     被 RequirePerm 门禁真实消费的权限码（典型于写操作：POST/PUT/DELETE 路由
        //     有门禁，菜单只挂读权限码）不再需要人工确认——「勾不到」不成立。
        //     边界：插件整体未声明菜单（menuCodes 为空）时不做交叉检查。权限码也可能
        //     只被前端 v-access 按钮消费（与 RequirePerm 共用同一份 RolePerms 数据源），
        //     后端无从验证；StorageDriverPlugin 即属此类（menus() 显式返回 []）。
        $menuCodes = array_map(
            static fn($m) => (string) ($m['auth_code'] ?? ''),
            array_filter($menus, static fn($m) => is_array($m) && !empty($m['auth_code']))
        );
        $scan = self::scanGates();
        $gatedAll = $scan === null ? [] : $scan[0];
        foreach ($perms as $code) {
            if ($code === '' || $menuCodes === [] || in_array($code, $menuCodes, true)) {
                continue;
            }
            if ($scan !== null && in_array($code, $gatedAll, true)) {
                continue;
            }
            $pending[] = "permissions：权限码 {$code} 声明了但无菜单引用、也无路由门禁引用"
                . '（角色勾了等于没用，确认是否应补菜单或在路由上挂 RequirePerm）';
        }

        // 3. 驱动名合法性
        $drivers = array_map('strval', self::listOf($plugin, 'drivers'));
        foreach ($drivers as $driver) {
            if (!preg_match(self::DRIVER_NAME_PATTERN, $driver)) {
                $drift[] = 'drivers：驱动名「' . $driver . '」非法（须小写字母开头，2-32 位小写字母/数字/下划线）';
            }
        }
        if (count($drivers) !== count(array_unique($drivers))) {
            $drift[] = 'drivers：驱动名重复';
        }

        // 3.1 跨插件同名驱动冲突：DriverRegistry 只保留先发现者，后声明者静默失效。
        //     两个已启用插件声明同一驱动键 = 一个插件白写实现，必须判漂移。
        //     只与「已启用」的插件比（paused/uninstalled 未进注册表，不构成运行时冲突）；
        //     states() 取一次，避免循环内反复查库。
        if ($drivers !== []) {
            $own = method_exists($plugin, 'name') ? (string) $plugin->name() : '';
            $states = PluginMarket::states();
            foreach (PluginMarket::discover() as $other => $class) {
                if ($other === $own) {
                    continue;
                }
                $status = isset($states[$other]) ? (string) $states[$other]['status'] : null;
                if ($status !== null && $status !== PluginMarket::ENABLED) {
                    continue;
                }
                try {
                    $otherDrivers = method_exists($class, 'drivers')
                        ? array_map('strval', (array) ((new $class())->drivers()))
                        : [];
                } catch (\Throwable) {
                    continue;
                }
                foreach (array_intersect($drivers, $otherDrivers) as $shared) {
                    $drift[] = 'drivers：驱动名「' . $shared . '」与插件「' . $other . '」冲突'
                        . '（注册表只保留先发现者，其中一个插件的实现会静默失效）';
                }
            }
        }

        // 3.5 配置 schema 字段形态：声明了就必须真能生成表单
        $drift = array_merge($drift, self::auditConfigSchema($plugin));

        // 4. 依赖插件是否存在。
        //    requires() 的两种形态都要取 name（数组形态直接 (string) 会触发
        //    Array to string conversion，且 isset() 判定必然落空 → 误报未安装）。
        //    缺 name 的非法元素由阶段 4.5 判漂移，此处跳过不重复报。
        $discovered ??= PluginMarket::discover();
        foreach (self::listOf($plugin, 'requires') as $req) {
            $reqName = self::requirementName($req);
            if ($reqName === '') {
                continue;
            }
            if (!isset($discovered[$reqName])) {
                $pending[] = "requires：依赖插件「{$reqName}」未安装（不影响当前审计通过）";
            }
        }

        // 4.5 依赖声明格式：声明了就必须能被解析（非法格式 = 声明⟺实现漂移）
        $drift = array_merge($drift, self::auditRequires($plugin));

        // 4.6 crons 兑现校验（框架 v1.4.0 起 PluginManager::addCron() 已是消费方）：
        //     crons() 从「无人消费的元数据」升级为「必须兑现的契约」——声明⟺实现零漂移：
        //     声明了就必须真实注册进 ScheduleDispatcher，否则永远不会执行。
        if (method_exists($plugin, 'crons')
            && method_exists(\Kode\Framework\Plugin\PluginManager::class, 'addCron')) {
            $cronsAudit = self::auditCrons($plugin);
            $drift = array_merge($drift, $cronsAudit['drift']);
            $pending = array_merge($pending, $cronsAudit['pending']);
        }

        // 4.7 commands 兑现校验（PluginManager::addCommand() 已是消费方）：
        //     声明的命令类必须真实注册进 Console Kernel，否则 kode <cmd> 找不到。
        if (method_exists($plugin, 'commands')
            && method_exists(\Kode\Framework\Plugin\PluginManager::class, 'addCommand')) {
            $cmdAudit = self::auditCommands($plugin);
            $drift = array_merge($drift, $cmdAudit['drift']);
            $pending = array_merge($pending, $cmdAudit['pending']);
        }

        // 4.8 events 兑现校验（PluginManager::addListener() 已是消费方）：
        //     声明的事件监听必须真实注册进 Event Dispatcher，否则事件派发不到本插件。
        if (method_exists($plugin, 'events')
            && method_exists(\Kode\Framework\Plugin\PluginManager::class, 'addListener')) {
            $evtAudit = self::auditEvents($plugin);
            $drift = array_merge($drift, $evtAudit['drift']);
            $pending = array_merge($pending, $evtAudit['pending']);
        }

        return ['ok' => $drift === [], 'drift' => $drift, 'pending' => $pending];
    }

    /**
     * crons() 兑现审计（阶段 4.6）。
     *
     * 框架 v1.4.0 提供 PluginManager::addCron() 后，crons() 不再是无人消费的元数据，
     * 而是必须兑现的契约——插件需在 register()/boot() 里逐条 addCron()（来源标记
     * plugin:<name>），或改用 #[Cron] 属性置于 plugins/<name>/src/Tasks 并开启
     * schedule.discover_plugins。校验矩阵：
     *  - 声明元素缺 name            → drift（框架按任务名寻址与去重，无名任务无法触发）；
     *  - 声明的 name 重复            → drift（addCron() 对重名抛错，等于其中一条永不生效）；
     *  - 声明了但调度器无同名任务    → drift（声明⟺实现漂移的最典型形态：声明了永不执行）；
     *  - 调度器有本插件任务但未声明  → pending（任务会跑，但平台契约清单看不见）；
     *  - 调度器不可解析              → pending（无法验证不阻断，典型于脱离容器的独立脚本）。
     *
     * 只读：解析 ScheduleDispatcher 会触发一次 #[Cron] 目录扫描（幂等，生产首次解析同样发生），
     * 但绝不执行任务、绝不改动注册表。
     *
     * @param object $plugin
     * @return array{drift: list<string>, pending: list<string>}
     */
    private static function auditCrons(object $plugin): array
    {
        $drift = [];
        $pending = [];

        $own = method_exists($plugin, 'name') ? (string) $plugin->name() : '';
        $r = self::call($plugin, 'crons');
        $crons = $r['error'] === null ? array_values((array) $r['value']) : [];

        if ($crons === []) {
            return ['drift' => [], 'pending' => []];
        }

        // 1) 收集声明名，同时校验形态与重复。
        $declared = [];
        foreach ($crons as $i => $cron) {
            $name = is_array($cron) ? (string) ($cron['name'] ?? '') : trim((string) $cron);
            if ($name === '') {
                $drift[] = 'crons：第 ' . ($i + 1) . ' 条声明缺少 name'
                    . '（框架按任务名寻址与去重，无名任务无法触发也无法唯一标识）';
                continue;
            }
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,63}$/', $name)) {
                $drift[] = 'crons：任务名「' . $name . '」非法'
                    . '（须字母开头，仅含字母/数字/._:-，最长 64 位）';
            }
            if (isset($declared[$name])) {
                $drift[] = 'crons：任务名「' . $name . '」重复声明'
                    . '（addCron() 对重名直接抛错，重复声明等于其中一条永不生效）';
            }
            $declared[$name] = true;
        }

        // 2) 与运行时调度器对账。
        $source = 'plugin:' . $own;
        try {
            /** @var \Kode\Framework\Scheduling\ScheduleDispatcher $dispatcher */
            $dispatcher = resolve(\Kode\Framework\Scheduling\ScheduleDispatcher::class);

            $registered = [];
            foreach ($dispatcher->registered() as $task) {
                if ($task->source === $source) {
                    $registered[$task->name] = true;
                }
            }
        } catch (\Throwable $e) {
            $pending[] = 'crons：无法与调度器对账（ScheduleDispatcher 解析失败 '
                . $e::class . ' ' . $e->getMessage() . '），本轮跳过兑现校验';

            return ['drift' => $drift, 'pending' => $pending];
        }

        foreach ($declared as $name => $_) {
            if (!isset($registered[$name])) {
                $drift[] = 'crons：任务「' . $name . '」声明了但未注册进调度器'
                    . '（需在 register()/boot() 里调 PluginManager::addCron() 兑现，'
                    . '或改用 #[Cron] 属性置于 plugins/' . $own . '/src/Tasks 并开启 schedule.discover_plugins）';
            }
        }

        foreach ($registered as $name => $_) {
            if (!isset($declared[$name])) {
                $pending[] = 'crons：任务「' . $name . '」已在调度器中但 crons() 未声明'
                    . '（任务会执行，但平台契约清单看不见它；建议补进 crons() 保持声明⟺实现一致）';
            }
        }

        return ['drift' => $drift, 'pending' => $pending];
    }

    /**
     * commands() 兑现审计（阶段 4.7）。
     *
     * 框架 PluginManager::addCommand() 提供消费方，commands() 声明的命令类
     * 必须在 register() 里逐条 addCommand() 注册进 Console Kernel——否则
     * kode <cmd> 永远找不到。校验矩阵：
     *  - 声明元素为空              → drift（addCommand() 拒绝空类名）；
     *  - 声明的类不存在             → drift（类名拼错或文件未加载，命令永远无法注册）；
     *  - 声明的类不是 Command 子类   → drift（内核只接受 Console\Command 子类）；
     *  - 声明了但内核未注册同名命令  → drift（声明⟺实现漂移：声明了但 kode 找不到）；
     *  - 内核不可解析              → pending（无法验证不阻断，典型于脱离容器的脚本）。
     *
     * 只读：解析 Kernel 不会触发命令执行，仅读取已注册列表。
     *
     * @param object $plugin
     * @return array{drift: list<string>, pending: list<string>}
     */
    private static function auditCommands(object $plugin): array
    {
        $drift = [];
        $pending = [];

        $r = self::call($plugin, 'commands');
        $commands = $r['error'] === null ? array_values((array) $r['value']) : [];

        if ($commands === []) {
            return ['drift' => [], 'pending' => []];
        }

        // 解析 Console Kernel
        try {
            /** @var \Kode\Console\Kernel $kernel */
            $kernel = resolve(\Kode\Console\Kernel::class);
        } catch (\Throwable $e) {
            $pending[] = 'commands：无法与命令内核对账（Kernel 解析失败 '
                . $e::class . ' ' . $e->getMessage() . '），本轮跳过兑现校验';

            return ['drift' => $drift, 'pending' => $pending];
        }

        // 收集内核已注册的命令类名集合（按类实例匹配）
        $registeredClasses = [];
        foreach ($kernel->all() as $cmd) {
            if ($cmd instanceof \Kode\Console\Command) {
                $registeredClasses[$cmd::class] = true;
            }
        }

        foreach ($commands as $i => $cmd) {
            $class = is_string($cmd) ? $cmd : '';
            if ($class === '') {
                $drift[] = 'commands：第 ' . ($i + 1) . ' 条声明为空'
                    . '（addCommand() 需传非空类名字符串）';
                continue;
            }
            if (!class_exists($class)) {
                $drift[] = 'commands：命令类「' . $class . '」不存在';
                continue;
            }
            if (!is_subclass_of($class, \Kode\Console\Command::class)) {
                $drift[] = 'commands：命令类「' . $class . '」不是 Console\Command 子类'
                    . '（内核只接受 Command 子类）';
                continue;
            }
            if (!isset($registeredClasses[$class])) {
                $drift[] = 'commands：命令类「' . $class . '」声明了但未注册进内核'
                    . '（需在 register() 里调 PluginManager::addCommand() 兑现）';
            }
        }

        return ['drift' => $drift, 'pending' => $pending];
    }

    /**
     * events() 兑现审计（阶段 4.8）。
     *
     * 框架 PluginManager::addListener() 提供消费方，events() 声明的事件监听
     * 必须在 register() 里逐条 addListener() 注册进 Event Dispatcher——否则
     * 事件派发时本插件收不到。events() 格式为 array<string, mixed>（事件名 => 处理器），
     * 与 config/event.php 的 listeners 一致。校验矩阵：
     *  - 事件名为空                 → drift（addListener() 需非空事件名）；
     *  - 声明了但派发器无该事件的监听器 → drift（声明⟺实现漂移：声明了但事件派发不到）；
     *  - 派发器不可解析             → pending（无法验证不阻断，典型于脱离容器的脚本）。
     *
     * 只读：仅调 hasListeners() 查表，不触发事件派发、不改动注册表。
     *
     * @param object $plugin
     * @return array{drift: list<string>, pending: list<string>}
     */
    private static function auditEvents(object $plugin): array
    {
        $drift = [];
        $pending = [];

        $r = self::call($plugin, 'events');
        $events = is_array($r['value']) ? $r['value'] : [];

        if ($events === []) {
            return ['drift' => [], 'pending' => []];
        }

        // 解析 Event Dispatcher
        try {
            /** @var \Kode\Event\Dispatcher $dispatcher */
            $dispatcher = resolve(\Kode\Event\Dispatcher::class);
        } catch (\Throwable $e) {
            $pending[] = 'events：无法与事件派发器对账（Dispatcher 解析失败 '
                . $e::class . ' ' . $e->getMessage() . '），本轮跳过兑现校验';

            return ['drift' => $drift, 'pending' => $pending];
        }

        if (!method_exists($dispatcher, 'hasListeners')) {
            $pending[] = 'events：当前 Dispatcher 版本不支持 hasListeners()，无法验证兑现';

            return ['drift' => $drift, 'pending' => $pending];
        }

        foreach ($events as $event => $handler) {
            $eventName = (string) $event;
            if ($eventName === '') {
                $drift[] = 'events：存在空事件名（addListener() 需非空事件名）';
                continue;
            }
            if (!$dispatcher->hasListeners($eventName)) {
                $drift[] = 'events：事件「' . $eventName . '」声明了但未注册监听器'
                    . '（需在 register() 里调 PluginManager::addListener() 兑现）';
            }
        }

        return ['drift' => $drift, 'pending' => $pending];
    }

    /**
     * 菜单形态审计（阶段 2.2）。
     *
     * 与 configSchema 同模式：声明了菜单就必须能生成可点击的菜单项——
     * 缺 title 用户看不到入口文字，path 不以 / 开头前端路由匹配不上（静默 404），
     * 同一路径重复声明会让两条菜单都指向同一页面（其中一条白占位）。
     *
     * 检查项：
     *  - `title` 非空（否则菜单只能显示原始键名或空白，不可读）；
     *  - `path` 非空、以 `/` 开头、仅含合法 URL 路径字符（`[a-zA-Z0-9/._:-]`）；
     *  - 路径不重复（同一插件内两条菜单指向同一路径 = 一条白占位）；
     *  - `icon` / `auth_code` / `parent` 非空时必须是字符串。
     *
     * 为什么不做「路径是否对应已注册路由」的兑现校验：
     * 路由注册由插件 register() 完成，但审计可能在插件 load() 之前运行（市场页预览），
     * 此时路由表未就绪——判 drift 会误报。路径兑现校验留给前端构建时
     * `pnpm check:menu-map` 做（见 PLUGIN-DEV.md §11）。
     *
     * @return list<string>
     */
    public static function auditMenus(object $plugin): array
    {
        if (!method_exists($plugin, 'menus')) {
            return [];
        }
        $r = self::call($plugin, 'menus');
        if ($r['error'] !== null || !is_array($r['value']) || $r['value'] === []) {
            return [];
        }

        $drift = [];
        $seenPaths = [];

        foreach ((array) $r['value'] as $i => $menu) {
            $idx = '第 ' . ($i + 1) . ' 条';
            if (!is_array($menu)) {
                $drift[] = "menus：{$idx}声明必须是数组（当前类型：" . get_debug_type($menu) . '）';
                continue;
            }

            $title = trim((string) ($menu['title'] ?? ''));
            if ($title === '') {
                $drift[] = "menus：{$idx}缺 title（菜单无法显示可读标题）";
            }

            $path = (string) ($menu['path'] ?? '');
            if ($path === '') {
                $drift[] = "menus：{$idx}缺 path（点击菜单后无法导航）";
            } elseif (!str_starts_with($path, '/')) {
                $drift[] = "menus：{$idx}的 path「{$path}」必须以 / 开头（前端路由匹配不上会静默 404）";
            } elseif (!preg_match('#^[a-zA-Z0-9/._:-]+$#', $path)) {
                $drift[] = "menus：{$idx}的 path「{$path}」含非法字符"
                    . '（仅允许字母/数字/._:-/）';
            } else {
                $label = $title !== '' ? "「{$title}」" : $idx;
                if (isset($seenPaths[$path])) {
                    $drift[] = "menus：{$label}的 path「{$path}」重复声明"
                        . '（两条菜单指向同一页面，其中一条白占位）';
                }
                $seenPaths[$path] = true;
            }

            // 可选字段：非空时必须是字符串
            foreach (['icon', 'auth_code', 'parent'] as $key) {
                if (array_key_exists($key, $menu) && $menu[$key] !== null
                    && !is_string($menu[$key])) {
                    $drift[] = "menus：{$idx}的 {$key} 必须是字符串"
                        . '（当前类型：' . get_debug_type($menu[$key]) . '）';
                }
            }
        }

        return $drift;
    }

    /**
     * 权限码门禁兑现审计（阶段 2.3）。
     *
     * RequirePerm 是权限码在路由上的唯一消费端，但审计此前从不校验
     * 「挂门禁的码是否声明过」——未声明的码永远进不了权限目录，非超管用户
     * 无论怎么勾角色都过不了这道门禁，等于永久锁死（声明⟺实现漂移的
     * 另一个方向：实现了消费、忘了声明供给）。
     *
     * 判据换了两次，第二次才是对的：原先比的是「各插件 permissions() 的并集」，
     * 但**那张面从来不是授予面** —— 角色页能勾到的是 `PermCatalog::groups()` 那三张
     * 输入面（菜单 auth_code ∪ 按钮码 ∪ permissions()）。于是 17 个门禁码里 11 个
     * 操作码（workflow:edit / notices:create / wallet:adjust …）明明勾不到、审计却全绿。
     * 现在与授予面同源：码不在 `PermCatalog::codes()` 里就是漂移。
     *
     * 授予面读不出来（DB 异常）时判**漂移并写明原因**，不静默放过：
     * 「无法验证」渲染成 OK，等于把这条门禁在最需要它的环境里关掉。
     *
     * 只读：仅遍历 Router 已注册路由与中间件实例，不发起请求、不改动路由表。
     *
     * 边界（有意降级，不判漂移）：
     *  - 路由表不可解析（脱离容器的独立脚本）→ 直接返回空，由阶段 0/5 记录；
     *  - 本插件名下无任何路由 → 无法判定（插件未 load()、或不提供 HTTP 接口），
     *    返回空而非 drift，避免审计早于插件加载时误报。
     *
     * @return list<string>
     */
    public static function auditPermGates(object $plugin): array
    {
        $own = method_exists($plugin, 'name') ? (string) $plugin->name() : '';
        $scan = self::scanGates();
        if ($scan === null) {
            return [];
        }

        $gates = $scan[1]['plugin:' . $own] ?? [];
        if ($gates === []) {
            return [];
        }

        try {
            $grantable = PermCatalog::codes();
        } catch (\Throwable $e) {
            return ['permissions：授予面读不出来（' . $e->getMessage() . '），无法判定路由门禁码是否勾得到'];
        }
        $drift = [];
        foreach ($gates as $code) {
            if (in_array($code, $grantable, true)) {
                continue;
            }
            $drift[] = "permissions：路由门禁要求权限码 {$code}，但它不在权限目录里"
                . '（菜单 auth_code、按钮码、插件 permissions() 三张面都没有它，'
                . '角色页勾不到 = 非超管用户被永久锁死）';
        }

        return $drift;
    }

    /**
     * 扫描全部路由上的 RequirePerm 门禁码。
     *
     * 返回 `[全局去重码集, 按来源标签分组的码集]`；路由表或来源登记表
     * 不可解析时返回 null（无法验证，不阻断）。
     *
     * 不缓存：一次审计请求会逐个插件调用 audit()，路由表规模很小，
     * 每次重扫的成本可忽略，而静态缓存会在独立 App 的测试之间泄漏状态。
     *
     * @return array{0: list<string>, 1: array<string, list<string>>}|null
     */
    private static function scanGates(): ?array
    {
        try {
            /** @var \Kode\Http\App $app */
            $app = resolve(\Kode\Http\App::class);
            /** @var \Kode\Framework\Http\RouteRegistry $registry */
            $registry = resolve(\Kode\Framework\Http\RouteRegistry::class);
        } catch (\Throwable) {
            return null;
        }

        $all = [];
        $bySource = [];
        foreach ($app->getRouter()->getRoutes() as $route) {
            $source = $registry->sourceOf($route) ?? '';
            foreach ($route->getMiddlewares() as $mw) {
                if (!$mw instanceof RequirePerm) {
                    continue;
                }
                foreach ($mw->perms() as $code) {
                    $code = trim((string) $code);
                    if ($code === '') {
                        continue;
                    }
                    $all[$code] = true;
                    $bySource[$source][$code] = true;
                }
            }
        }

        $bySource = array_map(
            static fn($v) => array_keys((array) $v),
            $bySource
        );

        return [array_values(array_keys($all)), $bySource];
    }

    /**
     * 配置 schema 字段形态审计（「声明了 configSchema 就必须能生成表单」的落地校验）。
     *
     * 为什么不放在 PluginConfig：字段词表（类型白名单、字段名格式）由 PluginConfig
     * 定义并消费，契约侧直接引用其常量——若两处各自维护一份词表，词表本身就会漂移，
     * 那正是本审计要防的事。
     *
     * 检查项：
     *  - 字段名合法（`PluginConfig::FIELD_PATTERN`）；
     *  - `label` 非空（否则表单只能显示原始键名，不可读）；
     *  - `type` 在白名单内（写错会被静默降级成 string，等于声明失效）；
     *  - `select` 必须给出非空 `options`；
     *  - `default` 必须能通过该字段自身的校验（default 是 number 却给了非数值、
     *    default 不在 options 内，都会在运行时悄悄退化成空值）。
     *
     * @return list<string>
     */
    public static function auditConfigSchema(object $plugin): array
    {
        if (!method_exists($plugin, 'configSchema')) {
            return [];
        }
        $r = self::call($plugin, 'configSchema');
        if ($r['error'] !== null || !is_array($r['value']) || $r['value'] === []) {
            return [];
        }

        $drift = [];
        foreach ((array) $r['value'] as $field => $def) {
            $field = (string) $field;
            if (!preg_match(PluginConfig::FIELD_PATTERN, $field)) {
                $drift[] = "config_schema：字段名「{$field}」非法（须小写字母开头，1-48 位小写字母/数字/下划线）";
                continue;
            }
            if (!is_array($def)) {
                $drift[] = "config_schema：字段「{$field}」定义必须是数组";
                continue;
            }
            $label = trim((string) ($def['label'] ?? ''));
            if ($label === '') {
                $drift[] = "config_schema：字段「{$field}」缺 label（表单将无法显示可读标题）";
            }
            $type = (string) ($def['type'] ?? 'string');
            if (!in_array($type, PluginConfig::TYPES, true)) {
                $drift[] = "config_schema：字段「{$field}」的 type「{$type}」不在白名单 "
                    . implode('/', PluginConfig::TYPES) . '（会被静默降级为 string）';
                continue;
            }
            $options = array_values(array_filter(
                array_map('strval', (array) ($def['options'] ?? [])),
                static fn($v) => $v !== ''
            ));
            if ($type === 'select' && $options === []) {
                $drift[] = "config_schema：字段「{$field}」声明为 select 但 options 为空";
                continue;
            }
            $default = (string) ($def['default'] ?? '');
            if ($default === '') {
                continue;
            }
            if ($type === 'number' && !is_numeric($default)) {
                $drift[] = "config_schema：字段「{$field}」的 default「{$default}」不是数值";
            }
            if ($type === 'select' && !in_array($default, $options, true)) {
                $drift[] = "config_schema：字段「{$field}」的 default「{$default}」不在 options 内";
            }
            if ($type !== 'number' && function_exists('mb_strlen') && mb_strlen($default) > 512) {
                $drift[] = "config_schema：字段「{$field}」的 default 超长";
            }
        }

        return $drift;
    }

    /**
     * 依赖声明格式审计（「声明了 requires 就必须能被依赖引擎解析」的落地校验）。
     *
     * 合法格式（两种，PluginDependency::normalizeRequirement() 归一）：
     *   - `'storage_driver'`                                  → 无版本约束
     *   - `['name' => 'storage_driver', 'version' => '^1.0']` → 带版本约束
     *
     * 非法格式（会被引擎静默丢弃，等于声明失效 → 漂移）：
     *   - 空字符串 / 非字符串非数组元素
     *   - 数组缺 `name` 键 / `name` 不匹配插件名正则
     *   - 数组 `version` 键不是字符串（如给了数组或 null 以外的非字符串）
     *
     * @return list<string>
     */
    public static function auditRequires(object $plugin): array
    {
        if (!method_exists($plugin, 'requires')) {
            return [];
        }
        $r = self::call($plugin, 'requires');
        if ($r['error'] !== null || !is_array($r['value'])) {
            return [];
        }
        $drift = [];
        foreach ((array) $r['value'] as $i => $req) {
            $idx = '[' . $i . ']';
            if (is_string($req)) {
                $name = strtolower(trim($req));
                if ($name === '' || !preg_match(PluginDependency::NAME_PATTERN, $name)) {
                    $drift[] = "requires{$idx}：依赖名「{$req}」非法（须 2-32 位小写字母/数字/下划线）";
                }
                continue;
            }
            if (is_array($req)) {
                $name = self::requirementName($req);
                if ($name === '' || !preg_match(PluginDependency::NAME_PATTERN, $name)) {
                    $drift[] = "requires{$idx}：缺合法 name 键（当前值：「" . json_encode($req['name'] ?? null, JSON_UNESCAPED_UNICODE) . "」）";
                }
                $version = $req['version'] ?? null;
                if ($version !== null && !is_string($version)) {
                    $drift[] = "requires{$idx}：version 必须是字符串或 null（当前类型：" . get_debug_type($version) . '）';
                    continue;
                }
                // 约束语法：引擎无法解析的表达式会静默退化为精确匹配兜底，
                // 声明的语义与真实执行结果不一致 → 漂移。
                if ($version !== null && !PluginDependency::isValidConstraint($version)) {
                    $drift[] = "requires{$idx}：依赖「{$name}」的版本约束「{$version}」非法——"
                        . 'satisfies() 无法解析会静默退化为精确匹配：区间写法 ">=1.0, <2.0" 的逗号后半段被丢弃'
                        . '（实测 2.5.0 可通过声明的 <2.0 上界），"1.0.x" / "@stable" 会被任何版本误拒'
                        . '。支持：' . implode('；', PluginDependency::constraintSyntax());
                }
                continue;
            }
            $drift[] = "requires{$idx}：元素必须是字符串或含 name 键的数组（当前类型：" . get_debug_type($req) . '）';
        }

        return $drift;
    }

    /**
     * 从单个 requires() 元素取出归一化的插件名（小写、去首尾空白）。
     *
     * 两种合法形态：字符串（名本身）与 array{name: string, version?: string}。
     * 取不到（缺 name 键 / 类型不符 / 空串）返回 ''——调用方据此区分
     * 「非法声明」（交 4.5 判漂移）与「已声明但未安装」（交阶段 4 记 pending），
     * 避免数组元素被 (string) 强转后既误报未安装、又触发 Array to string conversion。
     */
    private static function requirementName(mixed $req): string
    {
        if (is_string($req)) {
            return strtolower(trim($req));
        }
        if (is_array($req) && is_string($req['name'] ?? null)) {
            return strtolower(trim($req['name']));
        }

        return '';
    }

    /**
     * 插件声明的能力名列表（按 schema 顺序，稳定输出）。
     *
     * @return list<string>
     */
    public static function capabilitiesOf(object $plugin): array
    {
        $out = [];
        foreach (self::CAPABILITY_SCHEMA as $cap => [$method]) {
            if (method_exists($plugin, $method)) {
                $out[] = $cap;
            }
        }

        return $out;
    }

    /**
     * 判断插件是否声明了某能力。
     */
    public static function has(object $plugin, string $cap): bool
    {
        if (!isset(self::CAPABILITY_SCHEMA[$cap])) {
            return false;
        }
        [$method] = self::CAPABILITY_SCHEMA[$cap];

        return method_exists($plugin, $method);
    }

    /**
     * 方法必填参数个数（0 表示可无参裸调，用于安全审计）。
     */
    private static function requiredArgs(object $plugin, string $method): int
    {
        return (new \ReflectionMethod($plugin, $method))->getNumberOfRequiredParameters();
    }

    /**
     * 安全调用能力方法（只读探测用，吞异常改为返回 error 供调用方决策）。
     *
     * @return array{exists: bool, value: mixed, error: \Throwable|null}
     */
    private static function call(object $plugin, string $method): array
    {
        if (!method_exists($plugin, $method)) {
            return ['exists' => false, 'value' => null, 'error' => null];
        }
        try {
            return ['exists' => true, 'value' => $plugin->{$method}(), 'error' => null];
        } catch (\Throwable $e) {
            return ['exists' => true, 'value' => null, 'error' => $e];
        }
    }

    /**
     * 读取列表型能力（不存在或失败返回 []，失败同时记入 $errors）。
     *
     * @return list<mixed>
     */
    private static function listOf(object $plugin, string $method, ?array &$errors = null): array
    {
        $r = self::call($plugin, $method);
        if ($r['error'] !== null && $errors !== null) {
            $errors[] = $method . '() 执行失败 ' . $r['error']::class . ' ' . $r['error']->getMessage();
        }

        return $r['value'] === null ? [] : array_values((array) $r['value']);
    }

    /**
     * 读取 map 型能力（不存在或失败返回 []，失败同时记入 $errors）。
     *
     * @return array<string, mixed>
     */
    private static function mapOf(object $plugin, string $method, ?array &$errors = null): array
    {
        $r = self::call($plugin, $method);
        if ($r['error'] !== null && $errors !== null) {
            $errors[] = $method . '() 执行失败 ' . $r['error']::class . ' ' . $r['error']->getMessage();
        }

        return is_array($r['value']) ? $r['value'] : [];
    }
}
