# 插件开发指南（本地开发 · 运行 · 发布）

> 面向插件作者。读完本文可从零写一个可运行插件，并上架市场。
> 约定：本文所有代码均以仓库内真实插件（`plugins/DemoPlugin.php`、
> `plugins/NoticePlugin.php`）为原型，`php -l` 可过、可直接复制改名使用。

---

## 0. 一句话总览

一个插件 = **一个实现 `PluginInterface` 的类**（放在 `plugins/*Plugin.php`），
其余目录可选。框架启动期自动发现、实例化、调用 `register()` / `boot()`。

```
plugins/
├── NoticePlugin.php          # 主类（文件名 = 类名，必须 *Plugin.php 结尾）
└── notices/                  # 插件私有目录（可选，控制器/服务等放这里）
    └── NoticeController.php
```

- **命名红线**：插件名（`name()` 返回值）必须匹配 `^[a-z0-9_]{2,32}$`；
  路由前缀**恒等于** `/api/<name>/*`（运行时门禁靠这个前缀匹配）。
- **不新增生命周期总线**：复用框架既有路由 / 容器 / 事件 / 控制台机制，保持薄核。

---

## 1. 本地开发流程（6 步）

| 步骤 | 动作 | 命令 / 位置 |
| --- | --- | --- |
| 1. 建主类 | `plugins/<Name>Plugin.php`，实现 `PluginInterface` | — |
| 2. 建私有目录 | 控制器/服务放 `plugins/<name>/`（目录名 = 插件名） | — |
| 3. 启用 | 在 `config/plugins.php` 的 `plugins` 数组加本类全限定名 | `config/plugins.php` |
| 4. 生效 | 平滑重载（插件类是启动期加载，需 reload） | `php kode reload` |
| 5. 自检 | 按来源查看已注册路由 | `php kode route:list --source=plugin:<name>` |
| 6. 验契约 | 零漂移审计 + 测试基线全绿 | `php kode plugin:audit` 或 `./vendor/bin/phpunit` |

> **发现机制**：唯一真值源是框架 `Kode\Framework\Plugin\PluginDiscoverer::discover()`
> （扫描 `plugins/*Plugin.php` → 实例化 → 取 `name()`）；本站 `PluginMarket::discover()`
> 只是它的薄委托，保留是为了既有调用点零改动。
> `config/plugins.php` **不再自持清单**，只起「声明/收窄」作用：
> 实际加载 = 声明 ∩ 发现结果（框架 `PluginServiceProvider` 负责交集）。
> 因此**新增插件必须同时做两件事**——放文件 **且** 在 `config/plugins.php` 声明，
> 否则文件在、框架不加载（表现为路由 404，但审计会把它报成漂移）。
> 漂移随时可查：`PluginDiscoverer::undeclared()`（发现了没声明）/
> `missingDeclared()`（声明了发现不到）。
> 增删插件文件后必须 `reload`（进程内静态缓存）。
>
> **打包注意**：产物（PHAR/二进制）里的目录扫描**不能**用 `glob()`——它对 `phar://`
> 流静默返回空数组，会让所有插件无声消失。框架已用 `DirectoryIterator` 规避，
> 详见 `docs/PACKAGING.md` §6.9。
>
> **零漂移是发版红线**：`tests/PluginContractTest.php` 会对仓库全部插件跑
> `PluginContract::audit()`，`drift` 非空即测试失败。新增插件后先跑一遍再提交。

---

## 2. PluginInterface 契约（强制 3 方法）

源码：`vendor/kode/framework/src/Plugin/PluginInterface.php`

```php
interface PluginInterface
{
    /** 插件名：恒等于 URL 前缀，如 'notices' → /api/notices/* */
    public function name(): string;

    /** 注册阶段：绑定服务 / 路由 / 监听器 / 命令（容器与路由已就绪） */
    public function register(PluginManager $manager): void;

    /** 启动阶段：插件自身初始化（预热缓存、注册定时任务等） */
    public function boot(PluginManager $manager): void;
}
```

### 2.1 可选方法（约定钩子，运行时按 `method_exists` 探测）

这些方法**不在接口里**，框架按存在与否调用。全部应**幂等**（可重复执行）。

| 方法 | 触发时机 | 说明 |
| --- | --- | --- |
| `version(): string` | 安装/升级/列表 | 代码版本，缺省 `'1.0.0'` |
| `tables(): list<string>` | 卸载/备份提醒 | 插件所属表名列表，供 purge 与 pg_dump 提示 |
| `install(): void` | 市场「安装」 | 建表等初始化（幂等，`CREATE TABLE IF NOT EXISTS`） |
| `uninstall(bool $purge = false): void` | 市场「卸载」 | 默认只停用保留数据；`$purge=true` 才删表 |
| `upgrade(string $from, string $to): void` | 市场「升级」 | 按版本差做增量变更（幂等） |
| `menus(): list<array>` | 菜单聚合/列表 | 见 §4 |

---

## 3. PluginManager API（register/boot 里能用的一切）

源码：`vendor/kode/framework/src/Plugin/PluginManager.php`

| 方法 | 作用 | 备注 |
| --- | --- | --- |
| `bind(string $id, callable $factory): void` | 绑定单例服务到容器 | 等价 `singleton` |
| `alias(string $abstract, string $alias): void` | 为已绑服务加别名 | 门面/助手友好名 |
| `addRoute(string $name, string|array $methods, string $pattern, mixed $handler): Route` | 注册路由 | 自动打 `plugin:<name>` 来源标签；返回 `Route`，可链式 `->middleware()` |
| `addListener(string $event, mixed $handler): void` | 注册事件监听 | 对齐 `config/event.php` |
| `addCommand(class-string $command): void` | 注册控制台命令 | `php kode console <cmd>` 可调用 |
| `addCron(string $expression, string\|array\|\Closure $handler, string $name = '', string $description = '', bool $cluster = false, bool $enabled = true): ScheduledTask` | 注册定时任务 | 框架 **v1.4.0+**；见 §4.6.1 |
| `make(string $id): object` | 从容器解析服务 | 插件内部取用 |

> `addRoute` 的 `$handler` 支持：闭包 / `[控制器类, 方法]` / 控制器实例方法。
> `$methods` 支持单字符串或数组（`['GET', 'POST']`）。
>
> `addCron` 的 `$handler` 支持三种形态：`'类名::方法名'` 字符串 / `[类名, 方法名]` 元组
> （运行时经容器解析）/ `\Closure`（内联执行，不查容器）。任务会打上 `plugin:<name>`
> 来源标签，`schedule:list` 可按来源过滤；重名注册直接抛 `RuntimeException`。
> 见 §4.6.1。

---

## 4. 能力契约：PluginContract 与「声明⟺实现零漂移」

源码：`app/support/PluginContract.php`

`PluginInterface` 只有 3 个强制方法，其余能力全靠「约定 + `method_exists` 探测」。
约定一旦漂移，典型症状是**「配置里能选 oss 驱动，实际推送不了」**（空壳驱动）。
`PluginContract` 把约定收敛成单一 SoT：`CAPABILITY_SCHEMA` 声明每个能力对应哪个方法，
`describe()` 统一读取，`audit()` 校验声明与实际实现严格一致。

### 4.1 能力 schema（声明即契约）

| 能力名 | 方法 | 必需 | 分组 |
| --- | --- | --- | --- |
| `version` | `version()` | 否 | meta |
| `license` / `author` | `license()` / `author()` | 否 | meta |
| `lifecycle_install` | `install()` | **是** | lifecycle |
| `lifecycle_uninstall` | `uninstall(bool $purge)` | **是** | lifecycle |
| `lifecycle_upgrade` | `upgrade($from, $to)` | **是** | lifecycle |
| `tables` | `tables()` | 否 | data |
| `menus` | `menus()` | 否 | expose |
| `commands` / `events` / `crons` | `commands()` / `events()` / `crons()` | 否 | expose |
| `requires` | `requires()` | 否 | integration |
| `config_schema` | `configSchema()` | **是** | config |
| `permissions` | `permissions()` | 否 | config |
| `drivers` | `drivers()` | **是** | config |

「必需」= 声明了就必须有非空实现，否则 `audit()` 报 drift。

> ⚠️ **`commands` / `events` / `crons` 三个方法只是「声明清单」，真正的注册发生在
> `register()`/`boot()` 里调 `addCommand()` / `addListener()` / `addCron()`（框架 v1.4.0+）。**
> 声明了却不调用注册方法，任务永远不会执行——审计会把它判为 **drift**（阻断），
> 而不是静默通过。见 §4.6.1。

### 4.6.1 `crons()` 兑现契约（框架 v1.4.0 起有消费方）

框架 **v1.4.0** 给 `PluginManager` 补上了 `addCron()`，`crons()` 从「无人消费的元数据」
升级为「必须兑现的契约」。插件有两条等价的路子把定时任务真正注册进 `ScheduleDispatcher`：

**路子 A：`register()` / `boot()` 里逐条 `addCron()`**（显式、可单测、不依赖扫描）

```php
use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;

final class ReportPlugin implements PluginInterface
{
    public function name(): string { return 'report'; }

    public function crons(): array
    {
        return [
            ['name' => 'report.nightly', 'expression' => '0 2 * * *', 'description' => '每天 2 点生成日报'],
        ];
    }

    public function register(PluginManager $m): void
    {
        // 三种处理器形态任选其一：
        $m->addCron('0 2 * * *', 'App\\Jobs\\ReportJob::run', 'report.nightly', '每天 2 点生成日报');
        // $m->addCron('0 2 * * *', [ReportJob::class, 'run'], 'report.nightly', '...');
        // $m->addCron('0 2 * * *', static function (): void { /* ... */ }, 'report.nightly', '...');
    }

    public function boot(PluginManager $m): void {}
}
```

- `$handler` 支持 `'类名::方法名'` 字符串、`[类名, 方法名]` 元组（运行时经容器解析）、
  `\Closure`（内联执行，不查容器）。
- 省略 `$name` 时默认取 `插件名.方法名`；任务来源标记为 `plugin:<name>`，
  `schedule:list --source=plugin:report` 可过滤查看。
- **重名注册直接抛 `RuntimeException`**（而不是静默覆盖），避免两条同名任务只生效一条。

**路子 B：`#[Cron]` 属性 + 插件目录扫描**（约定式，适合任务较多时）

```php
use Kode\Framework\Scheduling\Attributes\Cron;

#[Cron('0 2 * * *', name: 'report.nightly', description: '每天 2 点生成日报')]
final class NightlyReportTask
{
    public function handle(): void { /* ... */ }
}
```

需满足两点：① 任务类放 `plugins/<name>/src/Tasks`；② `SCHEDULE_DISCOVER_PLUGINS=true`
（`config/schedule.php['discover_plugins']`，**默认关闭**）。

两条路子可以同时用；**关键约束是 `crons()` 里声明的每条任务都必须在调度器里真能查到**——
否则它永远不会执行，审计会判 **drift**（阻断，`audit()['ok'] === false`）：

| 情形 | 判定 | 说明 |
| --- | --- | --- |
| 声明元素缺 `name` | drift | 框架按任务名寻址与去重，无名任务无法触发也无法唯一标识 |
| `name` 重复声明 | drift | `addCron()` 对重名直接抛错，等于其中一条永不生效 |
| `name` 非法（非字母开头 / 含非法字符 / 超 64 位） | drift | 须字母开头，仅含 `[A-Za-z0-9_.:-]` |
| 声明了但调度器查不到同名任务 | drift | 声明⟺实现漂移的最典型形态：声明了永不执行 |
| 调度器里有本插件任务但 `crons()` 未声明 | pending | 任务会跑，但平台契约清单看不见它 |
| `ScheduleDispatcher` 解析失败 | pending | 无法验证不阻断（典型于脱离容器的独立脚本） |

审计是只读的：解析 `ScheduleDispatcher` 会触发一次 `#[Cron]` 目录扫描（幂等，
生产首次解析同样发生），但绝不执行任务、绝不改动注册表。

### 4.6.2 `commands()` 与 `events()` 兑现契约

`commands()` 和 `events()` 与 `crons()` 同理——框架早已提供消费方
（`PluginManager::addCommand()` / `addListener()`），但此前审计从不校验兑现。
现补齐：声明了就必须真实注册进运行时，否则审计判 **drift**（阻断）。

**commands()**：返回 `list<class-string>`，每个元素必须是 `Kode\Console\Command`
的子类全限定名。在 `register()` 里逐条 `addCommand()` 注册进 Console Kernel：

```php
public function commands(): array
{
    return [ImportDataCommand::class, ExportReportCommand::class];
}

public function register(PluginManager $m): void
{
    $m->addCommand(ImportDataCommand::class);
    $m->addCommand(ExportReportCommand::class);
}
```

| 情形 | 判定 | 说明 |
| --- | --- | --- |
| 声明元素为空字符串 | drift | `addCommand()` 需非空类名字符串 |
| 声明的类不存在 | drift | 类名拼错或文件未加载，命令永远无法注册 |
| 声明的类不是 `Command` 子类 | drift | 内核只接受 `Console\Command` 子类 |
| 声明了但内核未注册同名命令 | drift | 声明了但 `kode <cmd>` 永远找不到 |
| 内核不可解析 | pending | 无法验证不阻断 |

**events()**：返回 `array<string, mixed>`（事件名 → 处理器），格式与
`config/event.php['listeners']` 一致。在 `register()` 里逐条 `addListener()`
注册进 Event Dispatcher：

```php
public function events(): array
{
    return [
        'order.created' => static function (): void {},
    ];
}

public function register(PluginManager $m): void
{
    $m->addListener('order.created', static function (): void {});
}
```

| 情形 | 判定 | 说明 |
| --- | --- | --- |
| 事件名为空 | drift | `addListener()` 需非空事件名 |
| 声明了但派发器无该事件的监听器 | drift | 声明了但事件派发不到本插件 |
| 派发器不可解析 / 不支持 `hasListeners()` | pending | 无法验证不阻断 |

两个阶段的审计同样是只读的：仅调 `Kernel::all()` / `Dispatcher::hasListeners()`
查表，不执行命令、不派发事件、不改动注册表。

### 4.2 读取能力清单

```php
/** @var \Kode\Plugins\NoticePlugin $plugin */
$desc = \app\support\PluginContract::describe($plugin);
// $desc['capabilities']  已声明能力名数组，如 ['version','tables','menus','permissions']
// $desc['groups']        按分组聚合：['meta'=>[...], 'expose'=>[...]]
// $desc['tables'] / $desc['menus'] / $desc['permissions'] / $desc['drivers']
// $desc['config_schema'] 配置项 schema（后台据此渲染配置页）
// $desc['meta']          ['license'=>.., 'author'=>..]
```

### 4.3 零漂移审计

```php
$audit = \app\support\PluginContract::audit($plugin);
// ['ok'=>bool, 'drift'=>list<string>, 'pending'=>list<string>]
```

`drift` 是必须修复的硬漂移，`pending` 是待复查（环境未就绪 / 依赖未装，不阻断）：

| 检查项 | 归类 | 触发条件 |
| --- | --- | --- |
| 必需能力返回空 | drift | 必需方法存在但返回空（`configSchema()` 空 map、`drivers()` 空列表） |
| 能力方法抛异常 | pending | 非生命周期能力执行失败（如审计时 DB 未连接） |
| 表名非法 / 重复 | drift | 不匹配 `PluginContract::TABLE_NAME_PATTERN`（须字母或下划线开头，仅含字母/数字/下划线，最长 63 位）或重复 |
| 版本号非法 / 空 | drift | `version()` 返回值不匹配 `PluginDependency::VERSION_PATTERN`（1-4 段数字，容忍 `v` 前缀，可带 `-预发布` / `+构建元数据`）或返回空串。该值会写入 `plugin.json` 与 `kode_plugins.version`，再作为 `satisfies()` 的 `$installed` 参与依赖门禁——非法值不报错，只会让**所有依赖方**落精确匹配兜底被永久误拒 |
| 菜单权限码未声明 | drift | `menus()` 的 `auth_code` 不在 `permissions()` 中 |
| 权限码无菜单/门禁引用 | pending | `permissions()` 声明了但既无 `menus()` 的 `auth_code` 引用、也无路由 `RequirePerm` 门禁引用（勾了等于没用）；插件整体未声明菜单时不检查 |
| 门禁码未声明 | drift | 本插件路由上挂了 `RequirePerm('xxx')`，但没有任何插件在 `permissions()` 声明该码（权限目录勾不到，非超管用户永久锁死） |
| 菜单形态非法 | drift | 元素不是数组、缺 `title`、缺 `path`、`path` 不以 `/` 开头、`path` 含非法字符、`path` 重复、`icon`/`auth_code`/`parent` 非字符串 |
| 驱动名非法 / 重复 | drift | 不匹配 `PluginContract::DRIVER_NAME_PATTERN` 或重复 |
| 跨插件同名驱动 | drift | 与另一个**已启用**插件声明了同一驱动键（注册表只留先发现者，另一个插件的实现会静默失效） |
| 配置字段形态非法 | drift | 字段名 / `label` / `type` / `select options` / `default` 校验不过 |
| 依赖插件未安装 | pending | `requires()` 声明的插件不在 `discover()` 中 |
| 依赖声明格式非法 | drift | `requires()` 元素不是字符串也不是含 `name` 键的数组（会被引擎静默丢弃）；或 `version` 键不是字符串/null |
| 依赖版本约束非法 | drift | 约束语法 `satisfies()` 无法解析：区间写法 `">=1.0, <2.0"`（逗号后半段被丢弃，实测 `2.5.0` 可通过声明的 `<2.0` 上界）、`1.0.x` / `@stable` / `1.0.*`（落精确匹配兜底，任何版本被误拒）等 |
| crons 未兑现 | drift | 声明了 `crons()` 但调度器里查不到同名任务（未在 `register()`/`boot()` 调 `addCron()`，也未走 `#[Cron]` 扫描）；缺 `name` / 名重复 / 名非法同样是 drift |
| crons 反向缺失 | pending | 调度器里有 `plugin:<name>` 来源的任务但 `crons()` 未声明（会执行，但契约清单看不见） |
| commands 未兑现 | drift | 声明了 `commands()` 但内核未注册同名命令（未在 `register()` 调 `addCommand()`）；类不存在 / 非 `Command` 子类同样是 drift |
| events 未兑现 | drift | 声明了 `events()` 但派发器无该事件的监听器（未在 `register()` 调 `addListener()`） |

> **审计是只读的。** 生命周期方法（`install`/`uninstall`/`upgrade`）带建表/删表副作用，
> 审计**绝不执行**，只校验「方法存在 + 签名形态」——避免一次只读审计意外建出表。
> 因此生命周期能力永远不会因为「返回空」被判漂移。
>
> **带必填参数的方法不裸调**：`upgrade($from,$to)` 同理。
> `uninstall(bool $purge=false)` 是守卫型方法：`$purge=false` 时「不删表直接返回」
> 才是正确行为，故不做空值判定；行为正确性由市场流程（`confirm`/`purge` 门禁）兜底。

### 4.4 驱动与配置 schema（以 `StorageDriverPlugin` 为参考实现）

参考：`plugins/StorageDriverPlugin.php`

```php
/** 配置 schema：后台自动渲染配置页，secret 字段自动掩码 */
public function configSchema(): array
{
    return [
        'endpoint' => ['label' => 'Endpoint', 'type' => 'string', 'placeholder' => 'https://...'],
        'bucket'   => ['label' => 'Bucket', 'type' => 'string', 'required' => true],
        'ak'       => ['label' => 'AccessKey ID', 'type' => 'string', 'secret' => true],
        'sk'       => ['label' => 'AccessKey Secret', 'type' => 'string', 'secret' => true],
    ];
}

/** 本插件真实提供的驱动键——未实现的驱动不得声明 */
public function drivers(): array
{
    return [self::DRIVER_LOCAL];
}
```

**存储驱动接入约定**：核心 `StorageManager` 只内置 `local`。远程对象存储（oss/s3/cos…）
必须由插件实现真实上传逻辑，并经 `storage_driver_resolve` 事件接入——
核心不再为「配了却推送不了」的空壳驱动留口子。

**`drivers()` 的消费方：`DriverRegistry`（`app/support/DriverRegistry.php`）**

`drivers()` 的返回值不是装饰，而是平台允许创建哪些驱动的**唯一 SoT**：

```php
DriverRegistry::allowed()        // ['local']            可创建的驱动全集（字典序）
DriverRegistry::has('local')     // true                 门禁校验用（容忍大小写/空白）
DriverRegistry::sourceOf('local')// 'storage_driver'     提供该驱动的插件名
```

- `StorageController::store()` 与 `setDefault()` 都改从注册表校验，不再硬编码 `'local'`；
  插件声明并启用新驱动后，存储页**无需改后端代码**即自动放开。
- `GET /api/storage/drivers`（auth，只读）返回 `[{driver, plugin}]`，前端下拉数据源；
  随插件启停即时变化。
- 状态口径与运行时门禁一致：无安装行视为 enabled；`paused`/`uninstalled` 插件的驱动
  立即下线（`PluginMarket::setStatus()` 会清注册表缓存）。
- **跨插件同名驱动判漂移**：注册表只保留先发现者，后声明者的实现会静默失效。
  两个已启用插件声明同一驱动键时，`audit()` 报 drift（见 §4.3 审计表）。

```php
// 核心推送远程媒体时：
Event::dispatch(new StorageDriverResolveEvent(['driver' => $driver, 'path' => $path, 'disk' => $disk]));
// 未实现的驱动如实抛错，绝不静默失败。
```

### 4.5 配置 schema 的消费（`PluginConfig`）

`configSchema()` 不只是给人看的声明——`app/support/PluginConfig.php` 是它的唯一
消费方，负责**存储 / 校验 / 运行时读取**三段闭环，避免「声明了 schema 却无处落库」
的漂移：

| 用途 | 入口 | 说明 |
| --- | --- | --- |
| 存储 | `PluginConfig::save($name, $values)` | 全量校验后写 `kode_sys_configs`（`cgroup='plugin'`、`ckey='plugin.<name>.<field>'`） |
| 运行时读 | `PluginConfig::get($name, $field, $default='')` | 命中缓存→库里值→schema 默认值→调用方兜底，四层回落 |
| 表单数据源 | `PluginConfig::allFor($name)` | 返回 `{schema, values, saved}`；secret 字段已保存时回显掩码 |
| 契约校验 | `PluginContract::auditConfigSchema($plugin)` | 审计阶段 3.5，拦下坏 schema（缺 label / 坏 type / 坏字段名 / 空 select / 坏 default） |

**字段规范**（`normalizeSchema()` 强制收敛，越界字段被静默丢弃）：

| 属性 | 约束 | 说明 |
| --- | --- | --- |
| 字段名 | `^[a-z][a-z0-9_]{0,47}$` | 1–48 位，首字符小写字母 |
| `label` | 必填 | 表单标题，截断至 64 字符 |
| `type` | `string`/`number`/`boolean`/`textarea`/`select` | 越界降级为 `string` |
| `select.options` | 非空数组 | 空则降级为 `string`（避免生成点不开的表单） |
| `number.min` / `number.max` | 数值区间 | `min` 默认 0，`max` 默认 999999 |
| `string` / `textarea` | 长度上限 | `string` 512、`textarea` 4000 |
| `secret` | bool | 读/回显掩码为 `******`；写回时掩码值或空串 = 保持不变（防止覆盖真值） |
| `required` | bool | 空串视为未填，报「该字段为必填」 |

**运行时读取**（插件 `boot()` 里推荐用法）：

```php
$endpoint = PluginConfig::get(self::NAME, 'endpoint', 'https://default.example.com');
$bucket   = PluginConfig::get(self::NAME, 'bucket', '');
if ($bucket === '') {
    throw new RuntimeException('storage_driver 未配置 Bucket');
}
```

> secret 字段**永远**通过 `PluginConfig::get()` 读真值，不要绕过读 `kode_sys_configs`；
> 只有「留空或掩码值」写入才会保持原值不变，写其他值才覆盖。

### 4.6 接口

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/plugins` | 列表（含 `capabilities`/`groups`/`drivers`/`permissions`/`config_schema`/`contract_ok`/`contract_drift`/`contract_pending`/`errors`） |
| GET | `/api/plugins/{name}/contract` | 单插件能力契约详情（额外含 `schema`/`group_labels`） |
| GET | `/api/plugins/{name}/config` | 表单数据源 `{schema, values, saved, name, status, key_prefix}` |
| PUT | `/api/plugins/{name}/config` | `{values}` 全量保存；校验失败返回 `400 + errors` |

### 4.7 依赖声明与门禁（`requires()` + `PluginDependency`）

`requires()` 不是「注释」——`app/support/PluginDependency.php` 是它的唯一消费方，
把声明转化为运行时门禁：

| 动作 | 门禁语义 | 依赖不满足时 |
| --- | --- | --- |
| `install` | 硬门禁 | 拒绝安装，返回 `400 + {missing, notEnabled, versionMismatch}` |
| `resume` | 硬门禁 | 拒绝恢复，同上 |
| `upgrade` | 硬门禁 | 拒绝升级，同上 |
| `pause` | 级联 | 自动暂停所有下游依赖者（transitive），返回 `{cascaded: [...]}` |

**双格式声明**（`normalizeRequirement()` 归一，非法元素被丢弃并记为契约漂移）：

```php
// 格式 A：字符串，无版本约束
public function requires(): array { return ['storage_driver']; }

// 格式 B：数组，带版本约束
public function requires(): array { return [
    ['name' => 'storage_driver', 'version' => '^1.0'],
    'notices',
]; }
```

**版本约束**（`satisfies()`，与 npm/SemVer 兼容的最小集）：

| 约束 | 语义 | 示例 |
| --- | --- | --- |
| `>=1.0` | 大于等于 | `1.2.3` ✓、`0.9.9` ✗ |
| `^1.0` | >=1.0, <2.0（主版本固定） | `1.9.9` ✓、`2.0.0` ✗ |
| `^0.2` | >=0.2, <0.3（0.x 次版本固定） | `0.2.5` ✓、`0.3.0` ✗ |
| `~1.2` | >=1.2, <1.3（次版本固定） | `1.2.9` ✓、`1.3.0` ✗ |
| `1.2.3` | 精确匹配 | `1.2.3` ✓、`1.2.4` ✗ |
| `*` / 空 | 任意版本 | 全部 ✓ |

**传递闭包**：`closure('a')` 返回 a 的所有直接+间接依赖（BFS + 环检测，
A→B→A 只返回 `['B']`，不无限递归）。**反向依赖者**：`dependents('a')` 返回
所有直接或间接依赖 a 的插件（暂停 a 时用于级联）。

**契约审计**（`auditRequires()`，阶段 4.5）：requires() 每个元素必须是合法插件名
字符串或含 name 键的数组，非法格式记为漂移（不是 pending——格式错了引擎会静默丢弃，
等于声明失效）。`version` 键必须是字符串或 `null`，且**约束语法必须能被 `satisfies()`
真正解析**（`PluginDependency::isValidConstraint()` 是语法 SoT，分支与引擎严格一致）：

| 写法 | 结果 | 引擎实际行为 |
| --- | --- | --- |
| `'>=1.0, <2.0'` | **drift** | 逗号后整段被丢弃，`2.5.0` 能通过声明的 `<2.0` 上界 |
| `'1.0.x'` / `'1.0.*'` | **drift** | 落精确匹配兜底，任何版本被误拒 |
| `'@stable'` | **drift** | 同上 |
| `'>=1.0'` / `'^1.0'` / `'~1.2'` / `'1.2.3'` / `'*'` | ✓ 合法 | 按上表语义判定 |

> 不支持多段区间 / 通配 / 频道标记——需要时用 `>=` 与 `<` 组合在 `satisfies()` 调用方
> 自行 AND。`constraintSyntax()` 返回该词表，审计报告会把完整清单内联在错误信息里。

**版本号语法**（阶段 1.6，`PluginDependency::isValidVersion()`）：`version()` 的返回值
必须匹配 `VERSION_PATTERN`（`1-4` 段数字、容忍 `v` 前缀、可带 `-预发布` 与 `+构建元数据`）。
该值会进入依赖门禁，非法值不报错——只会让所有依赖方被永久误拒，且报错信息无法解释根因。
未声明 `version()` 不算漂移（`describe()` 回落 `1.0.0` 是既有契约）。

**卸载清理**：`purge=true` 卸载时自动删除 `kode_sys_configs` 中
`plugin.<name>.` 前缀的所有配置行（`PluginConfig::purge()`），防止残留配置。

### 4.8 权限码门禁（`permissions()` + `RequirePerm`）

`permissions()` 声明的权限码进入权限目录供角色勾选，但路由只挂 `AuthMiddleware`
（登录门禁）时权限码从未在运行时校验——任何已登录用户都能调 delete/update，
属声明⟺实现漂移。`app/admin/http/middleware/RequirePerm.php` 补齐消费端：

```php
use app\admin\http\middleware\RequirePerm;

// 用法：->middleware([new AuthMiddleware(), new RequirePerm('notices:delete')])
$manager->addRoute('notice.destroy', 'DELETE', '/api/notices/{id}', [$ctrl, 'destroy'])
    ->middleware($auth, new RequirePerm('notices:delete'));
```

**语义**：读 JWT payload `uid` → `RolePerms::forUser()` 取 perms 并集 →
命中任一权限码放行，否则 403。**旁路**：`admin` 角色或 `uid=1` 自动放行。

**契约审计**（双向，阶段 2.3 + 2.5）：

- **阶段 2.3（drift，阻断）**：路由上挂了 `RequirePerm` 的权限码必须被**某个**
  插件的 `permissions()` 声明过。未声明的码永远进不了权限目录，非超管用户无论
  怎么勾角色都过不了这道门禁——**永久锁死**。这是声明⟺实现漂移的反方向：
  实现了消费、忘了声明供给。跨插件复用同一码合法（如公共的 `storage:view`），
  归属报告给挂门禁的那个插件（按 `plugin:<name>` 来源标签）。
  若本插件名下无任何路由（插件未 `load()`、或纯组件插件不提供 HTTP 接口），
  则无法判定，有意降级为不判漂移——避免审计早于插件加载时误报。
- **阶段 2.5（pending，不阻断）**：`permissions()` 声明了但既无菜单 `auth_code`
  引用、也**无路由门禁引用**的权限码，记为 `pending`（勾了等于没用，确认是否
  应补菜单或挂 `RequirePerm`）。被 `RequirePerm` 真实消费的码**不再提示**——
  典型于写操作：POST/PUT/DELETE 路由有门禁，菜单只挂读权限码，
  `NoticePlugin` 的 `notices:create`/`update`/`delete` 即属此类。
  边界：插件整体未声明菜单时不做交叉检查（权限码可能只被前端 `v-access`
  按钮消费，与 `RequirePerm` 共用同一份 `RolePerms` 数据源，后端无从验证；
  `StorageDriverPlugin` 即属此类，`menus()` 显式返回 `[]`）。

**示例**：`NoticePlugin` 的 6 条路由按权限码挂门禁——
view→GET×3、create→POST、update→PUT、delete→DELETE；4 个权限码全部声明，
审计 0 漂移 0 待复查。

---

## 5. 菜单声明 menus()

```php
/**
 * @return list<array{title: string, path: string, icon: string,
 *                    auth_code?: string, parent?: string}>
 */
public function menus(): array
{
    return [
        [
            'title'     => '公告管理',
            'path'      => '/notices',        // 前端路由路径
            'icon'      => 'notification',
            'auth_code' => 'notices:view',    // 权限码（可选，供角色勾选）
            'parent'    => '/cms',            // 挂到哪个一级目录（可选）
        ],
    ];
}
```

- 菜单聚合接口自动收录，供前端动态路由。
- 组件插件通常**不声明** `menus()`（除非有配置页）。

### 5.1 契约红线：菜单即「存在前端页面」的声明

声明 `menus()` 等于声明"这个 path 有真实页面"。前端按
`src/api/core/menu.ts` 的 `KODE_COMPONENT_MAP` 解析 path → 视图组件，
**映射不到就落到 `/kode/generic/index` 通用占位页**——页面能打开但内容全空，
是最难发现的漂移。所以：

1. 新增 `menus()` 时必须同时在 `KODE_COMPONENT_MAP` 加条目，并确保
   `src/views/.../index.vue` 真实存在；
2. 纯组件插件保持 `menus()` 返回 `[]`（如 `StorageDriverPlugin`），
   它曾因声明 `/storage-drivers` 却无对应视图而被清除该声明；
3. 前端 CI 门禁 `pnpm check:menu-map`（`scripts/check-menu-map.php`）双向校验：
   ① `KODE_COMPONENT_MAP` 的目标组件文件必须存在；② 后端 `plugins/*Plugin.php`
   的 `menus()` 路径必须能解析到视图。任一不满足即非零退出。

### 5.2 契约审计（阶段 2.2）：菜单形态

后端 `PluginContract::auditMenus()` 只校验**形态**——声明了菜单就必须能渲染成
一个可点击的条目。逐项规则：

| 规则 | 归类 |
| --- | --- |
| 元素不是数组 | drift |
| 缺 `title` | drift |
| 缺 `path` | drift |
| `path` 不以 `/` 开头 | drift |
| `path` 含非法字符（仅允许字母/数字/`._:-/`） | drift |
| 两条菜单同一 `path` | drift |
| `icon` / `auth_code` / `parent` 存在但非字符串 | drift |

> **边界**：后端审计**不校验** `path` 是否真有对应视图/路由。审计可能在插件
> `load()` 之前运行（如市场页预览契约），此时路由表未就绪，判 drift 会误报。
> 「path → 视图」的兑现校验留给前端构建时 `pnpm check:menu-map` 兜底（见 §5.1）。

`tables()` 同理受审计（阶段 1.5）：每个表名必须匹配
`PluginContract::TABLE_NAME_PATTERN`（字母或下划线开头、仅含字母/数字/下划线、
最长 63 位——PostgreSQL 标识符上限），且不得重复。这两个约束是
`install()`/`uninstall($purge)` 的删表保护正则前置条件——声明阶段就把非法名挡住，
避免审计之外的地方拼出会被静默拒绝的 SQL。

`parent` 由后端 `MenuController::tree()` 直接挂接（指向已存在的一级目录 path），
前端不再需要自己做目录收纳；无 `parent` 的插件叶子前端统一收进「扩展」目录。


---

## 6. 完整示例 A：组件插件（无页面、无菜单）

原型：`plugins/DemoPlugin.php` + `plugins/DemoService.php`。
适用场景：钱包、支付、短信、对象存储等**被其它插件调用**的底座能力。

### 6.1 主类

```php
<?php

declare(strict_types=1);

namespace Kode\Plugins;

use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;

final class DemoPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'demo';
    }

    public function register(PluginManager $manager): void
    {
        // 组件通常无前端页面，这里只绑定核心服务。
        $manager->bind('demo.service', static fn(): DemoService => new DemoService());
    }

    public function boot(PluginManager $manager): void
    {
        // 预热缓存 / 注册定时任务等。
    }
}
```

### 6.2 服务

```php
<?php

declare(strict_types=1);

namespace Kode\Plugins;

final class DemoService
{
    public function greet(): string
    {
        return 'hello from demo plugin';
    }
}
```

### 6.3 其它插件如何调用

```php
// 任意业务插件 / 控制器内：按 bind 的 id 从容器取。
/** @var DemoService $demo */
$demo = resolve('demo.service');
$demo->greet();
```

> 跨插件事件（任何插件可监听/触发）：
> `Event::dispatch(new SomeEvent());   // 任何插件可 dispatch`，监听方在 `register()` 里 `$manager->addListener('order.paid', ...)`。

---

## 7. 完整示例 B：业务插件（CRUD + 菜单 + 表生命周期）

原型：`plugins/NoticePlugin.php`（公告管理）。
适用场景：有完整用户旅程、有页面菜单、可独立安装卸载。

### 7.1 主类（含表生命周期与菜单）

```php
<?php

declare(strict_types=1);

namespace Kode\Plugins;

use Kode\Framework\Http\Middleware\AuthMiddleware;
use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;
use Kode\Plugins\notices\NoticeController;
use Kode\Database\Db\Db;

final class NoticePlugin implements PluginInterface
{
    public function name(): string
    {
        return 'notices';                 // 恒等于 URL 前缀 /api/notices/*
    }

    public function version(): string
    {
        return '1.0.0';
    }

    /** @return list<string> 插件所属表（备份提醒 + purge 清理依据） */
    public function tables(): array
    {
        return ['kode_notices'];
    }

    /** 安装：建表（幂等，可重入）。 */
    public function install(): void
    {
        Db::statement(
            'CREATE TABLE IF NOT EXISTS kode_notices (
                id BIGSERIAL PRIMARY KEY,
                title VARCHAR(128) NOT NULL,
                body TEXT NOT NULL,
                status SMALLINT NOT NULL DEFAULT 1,
                created_by VARCHAR(64) NOT NULL DEFAULT \'\',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )'
        );
    }

    /** 卸载：默认只停用（数据保留）；$purge=true 删表（调用方须二次确认 + 备份）。 */
    public function uninstall(bool $purge = false): void
    {
        if (!$purge) {
            return;
        }
        foreach ($this->tables() as $table) {
            if (!preg_match('/^[a-z0-9_]{2,64}$/', $table)) {
                continue;
            }
            Db::statement("DROP TABLE IF EXISTS {$table}");
        }
    }

    /** 升级钩子：按版本差执行增量变更（幂等）。 */
    public function upgrade(string $from, string $to): void
    {
        // 例如 1.0.0 → 1.1.0 加列：ALTER TABLE kode_notices ADD COLUMN IF NOT EXISTS ...
    }

    public function register(PluginManager $manager): void
    {
        $auth = new AuthMiddleware();
        // 约定：读操作挂鉴权；写操作在控制器内再套超管门禁。
        $manager->addRoute('notice.index', 'GET', '/api/notices', [NoticeController::class, 'index'])
            ->middleware($auth);
        $manager->addRoute('notice.store', 'POST', '/api/notices', [NoticeController::class, 'store'])
            ->middleware($auth);
        $manager->addRoute('notice.update', 'PUT', '/api/notices/{id:[A-Za-z0-9]+}', [NoticeController::class, 'update'])
            ->middleware($auth);
        $manager->addRoute('notice.destroy', 'DELETE', '/api/notices/{id:[A-Za-z0-9]+}', [NoticeController::class, 'destroy'])
            ->middleware($auth);
    }

    public function boot(PluginManager $manager): void
    {
    }

    /** @return list<array{title: string, path: string, icon: string}> */
    public function menus(): array
    {
        return [
            ['title' => '公告管理', 'path' => '/notices', 'icon' => 'notification',
             'auth_code' => 'notices:view', 'parent' => '/cms'],
        ];
    }
}
```

### 7.2 控制器（复用核心 ApiResponse + HashId）

真实实现见 `plugins/notices/NoticeController.php`（含分库分表兼容、
归属鉴权、CSV 导出）。骨架如下：

```php
<?php

declare(strict_types=1);

namespace Kode\Plugins\notices;

use Kode\Framework\Http\Controller;
use app\http\concerns\ApiResponse;
use app\support\HashId;
use Kode\Database\Db\Db;

final class NoticeController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $page = max(1, (int) $this->query('page', 1));
        $size = min(100, max(1, (int) $this->query('size', 20)));
        $rows = Db::select(
            'SELECT id, title, body, status, created_at FROM kode_notices ORDER BY id DESC LIMIT ? OFFSET ?',
            [$size, ($page - 1) * $size]
        );
        foreach ($rows as &$row) {
            $row['id'] = HashId::enc((int) $row['id']);   // 对外 ID 一律 HashId
        }
        unset($row);

        return $this->page($rows, (int) Db::select('SELECT COUNT(*) c FROM kode_notices')[0]['c'], $page, $size);
    }

    public function store()
    {
        $data = $this->validate(\Kode\Http\Request::all(), [
            'title' => 'required|min:2|max:128',
            'body'  => 'required|min:1|max:50000',
        ]);
        $id = Db::insert(
            'INSERT INTO kode_notices (title, body, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
            [$data['title'], $data['body']]
        );

        return $this->ok(['id' => HashId::enc((int) $id)], 201);
    }
}
```

> **写操作门禁**：业务插件的写接口在控制器内判断超管（见 NoticeController 的
> `isAdmin()`），读接口仅挂 `AuthMiddleware` 即可。

---

## 8. 运行时门禁：启停即时生效、无需重启

- `app/http/middleware/PluginGateMiddleware.php` 挂在全局最外层（`app/bootstrap.php`）；
  命中 `/api/<name>/*` 且该插件状态非 `enabled` 时**直接 404**，不消耗鉴权/业务资源。
- 状态存 `kode_plugins` 表 + redis `HotCache`（300s）。
- 三态：`enabled`（放行）/ `paused`（404「插件已暂停」）/ `uninstalled`（404「插件未安装」）。
- **未安装行视为 enabled**（兼容「config 加载即用」的旧行为）。

> 因此：`config/plugins.php` 管「代码是否加载」（需 reload）；
> 市场启停管「路由是否放行」（即时生效，无需 reload）。

---

## 9. 市场管理 API（admin）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/plugins` | 列表（版本 / 状态 / 菜单 / 所属表 / 能力清单 / 契约审计 / **依赖图**） |
| GET | `/api/plugins/{name}/contract` | 单个插件完整能力契约 + 零漂移审计明细 + **依赖概览** |
| POST | `/api/plugins/{name}/install` | 调 `install()`，登记版本 + 置 enabled；**依赖未就绪拒绝** |
| POST | `/api/plugins/{name}/uninstall` | `{confirm:1, purge?}`；purge 时删表 + **清配置** |
| POST | `/api/plugins/{name}/pause` | 置 paused（即时 404）+ **级联暂停下游依赖者** |
| POST | `/api/plugins/{name}/resume` | 置 enabled；**依赖未就绪拒绝** |
| POST | `/api/plugins/{name}/upgrade` | 版本比较后调 `upgrade($from,$to)`；**依赖未就绪拒绝** |
| GET | `/api/plugins/{name}/package` | 打包 zip（含 `plugin.json` 清单，随包携带 `contract`） |
| POST | `/api/plugins/install-remote` | `{url, sha256, name}` 远程安装（sha256 + **清单三道门禁** + 20MB 上限，装完需 reload） |

列表与契约接口每项额外返回的能力字段：`capabilities`（已声明能力键集合，按 schema
顺序稳定输出）、`groups`（按 `meta/lifecycle/data/expose/integration/config` 分组）、
`tables`、`menus`、**`commands`**、**`events`**、**`crons`**（未声明则为空数组，
列表与 `contract` 详情字段一致，避免只声明命令/事件/定时任务的插件在列表里看不出能力）、
`drivers`、`permissions`、`requires`、`config_schema`、`meta`、
`contract_ok`、`contract_drift`（声明漂移清单）、`contract_pending`（待复查清单）、
`errors`（方法声明了但执行失败，区别于「未声明」）、
**`dependencies`**（直接依赖列表，含 `{name, version, installed, status, ok}`）、
**`dependents`**（下游依赖者名列表）、**`transitive`**（传递依赖闭包）、**`cycle`**（是否成环）。
契约详情额外返回 `schema`（能力 schema 定义）、`group_labels`（分组中文名）、
**`dependencies`**（同列表项，含 `overview()` 完整结构）。

**依赖门禁**（`install`/`resume`/`upgrade` 共享）：
`PluginDependency::validate()` 传递校验所有依赖——
`missing`（插件不存在）、`notEnabled`（状态非 enabled）、`versionMismatch`（版本不满足约束）
任一非空即返回 `400 + {dependencies: {ok, missing, notEnabled, versionMismatch, checked}}`。
`pause` 的级联语义：`PluginDependency::dependents()` 返回所有下游依赖者，
逐个置 paused，响应含 `{cascaded: [name...], hint: "已级联暂停下游依赖者：..."}`。

`contract_ok=false` 说明插件声明与实际实现不一致（必须修复，见 §4.3）；
`contract_pending` 非空说明待复查（环境未就绪、依赖未装、**或调度器里有本插件任务但
`crons()` 未声明**），不阻断；`contract_ok=false` 说明必须修复（含 crons 声明了却未兑现）。

安全红线（`app/support/PluginPackager.php`）：
sha256 强制校验；解包拒绝 `..` / 绝对路径 / 隐藏文件 / 非 `.php`；
落盘固定 `plugins/` 下；**已存在文件不覆盖**（防偷梁换柱）；
远程安装 SSRF 防护拒绝内网地址（本地联调市场用 `MARKET_ALLOW_PRIVATE=true` 放行）。

**远程安装的清单三道门禁**（`verifyManifest()`，在 sha256 之后、落盘之前）：

| 门禁 | 拒绝条件 | 防止的问题 |
| --- | --- | --- |
| 自述身份 | 包内无 `plugin.json` 或未声明 `name` | 无法校验身份的包一律拒收 |
| 身份一致 | 清单 `name` ≠ 请求的 `name` | 「请求 foo、落盘 `BarPlugin.php`」→ 登记 foo=enabled，而 `discover()` 发现的是 bar，状态与实体错位 |
| 框架下限 | 清单 `kode` 不被当前框架版本满足（`PluginDependency::satisfies()` 判定） | `>=2.0` 的包装到 1.3 上运行 |

校验通过后 **状态与版本同时登记**（`setStatus(ENABLED)` + `setVersion`）：
version 缺失会让 `upgrade()` 永远从 `0.0.0` 起算、误判可升级。
响应额外返回 `{version, kode}`。未声明 `kode` 的旧市场包视为不限框架（宽松兼容），但 `name` 仍必须一致。

---

## 10. 发布 / 打包清单 plugin.json

`GET /api/plugins/{name}/package` 自动生成的清单结构：

```json
{
  "name": "notices",
  "version": "1.0.0",
  "tables": ["kode_notices"],
  "menus": [{"title": "公告管理", "path": "/notices", "icon": "notification", "auth_code": "notices:view", "parent": "/cms"}],
  "kode": ">=1.3",
  "contract": {
    "capabilities": ["version", "lifecycle_install", "lifecycle_uninstall", "lifecycle_upgrade", "tables", "menus", "permissions"],
    "groups": {"meta": ["version"], "lifecycle": ["lifecycle_install", "lifecycle_uninstall", "lifecycle_upgrade"], "data": ["tables"], "expose": ["menus"], "config": ["permissions"]},
    "drivers": [],
    "permissions": ["notices:view", "notices:create", "notices:update", "notices:delete"],
    "config_schema": {}
  }
}
```

`contract` 由 `PluginContract::describe()` 产出，随包携带后**市场侧无需加载插件代码**
即可审阅能力与做零漂移审计；完整字段见 §4.2。

| 字段 | 来源 | 说明 |
| --- | --- | --- |
| `name` | 插件名 | 与 URL 前缀一致 |
| `version` | `version()` | 取插件自身版本（未声明时回退 `1.0.0`） |
| `tables` / `menus` | `tables()` / `menus()` | 未声明则空数组 |
| `kode` | `PluginPackager::frameworkConstraint()` | 框架版本**下限**，随真实环境导出（`vendor/composer/installed.json` 的 `kode/framework`，取主.次，如 `>=1.3`）；读不到才回退 `>=1.0` |
| `contract` | `PluginContract::describe()` | 能力契约快照 |

包内文件：`<Name>Plugin.php` + `<name>/*.php`（仅 `.php`）+ `plugin.json`。

---

## 11. 自检清单（提交前逐项打勾）

- [ ] 主类文件名 = 类名，且以 `Plugin.php` 结尾，放 `plugins/` 根下。
- [ ] `name()` 返回小写字母/数字/下划线，长度 2–32，与 URL 前缀一致。
- [ ] 私有代码放 `plugins/<name>/`，namespace 为 `Kode\Plugins\<name>`。
- [ ] 已写 `version()` / `tables()` / `install()` / `uninstall()` / `upgrade()`（全部幂等）。
- [ ] 建表用 `CREATE TABLE IF NOT EXISTS`；删表前校验表名正则，且默认不删。
- [ ] `menus()` 里出现的每个 `auth_code` 都已在 `permissions()` 声明。
- [ ] `menus()` 里每个 `path` 前端都能解析到真实视图（`KODE_COMPONENT_MAP` 有条目
      且组件文件存在）；纯组件插件直接返回 `[]`，不要声明不存在的页面。
- [ ] `pnpm check:menu-map`（admin-web/apps/web-antd）通过。
- [ ] `configSchema()` / `drivers()` 等必需能力非空（声明了就必须真有实现）。
- [ ] `configSchema()` 字段名 1–48 位（首字符小写字母，仅 `[a-z0-9_]`）、`label` 非空、
      `type` 在白名单内、`select` 有非空 `options`、`default` 通过自身字段校验；
      `pnpm check:menu-map`（admin-web/apps/web-antd）与 `./kode plugin:audit` 均零漂移。
- [ ] 驱动名匹配 `PluginContract::DRIVER_NAME_PATTERN` 且不重复。
- [ ] `tables()` 表名匹配 `PluginContract::TABLE_NAME_PATTERN`（字母或下划线开头、
      仅含字母/数字/下划线、最长 63 位）且不重复；见 §5.2。
- [ ] `menus()` 每条都有 `title` 与以 `/` 开头的 `path`，路径不重复，
      `icon`/`auth_code`/`parent` 为字符串；path→视图由 `pnpm check:menu-map` 兜底；见 §5.2。
- [ ] `version()` 返回值匹配 `PluginDependency::VERSION_PATTERN`（1-4 段数字、容忍 `v`
      前缀、可带 `-预发布` / `+构建元数据）；非法值会让所有依赖方被永久误拒。
- [ ] `requires()` 每个元素是合法插件名字符串或含 `name` 键的数组，`version` 键为字符串
      或 `null`；约束用 `>=`/`^`/`~`/精确/`*`（**不要**写 `">=1.0, <2.0"` / `1.0.x` /
      `@stable`——引擎解析不了会静默退化为精确匹配，判 drift）；`./kode plugin:audit` 零漂移。
- [ ] 定时任务已真实注册：`register()`/`boot()` 里逐条 `addCron()`，或 `#[Cron]` 属性置于
      `plugins/<name>/src/Tasks` 并开启 `SCHEDULE_DISCOVER_PLUGINS=true`；
      `crons()` 声明的每条任务都能在调度器里查到（未兑现会判 drift）；见 §4.6.1。
- [ ] `commands()` 声明的命令类已在 `register()` 里逐条 `addCommand()` 注册进内核
      （未兑现会判 drift）；见 §4.6.2。
- [ ] `events()` 声明的事件监听已在 `register()` 里逐条 `addListener()` 注册进派发器
      （未兑现会判 drift）；见 §4.6.2。
- [ ] `drivers()` 声明的驱动键没有与任何**已启用**插件重名（注册表只留先发现者，
      重名会静默失效并判漂移）；见 §4.4。
- [ ] 发布到市场前 `plugin.json` 的 `name` 与插件 `name()` 一致、`kode` 下限准确
      （远程安装会逐条校验，不一致直接拒收）。
- [ ] 依赖的插件已安装且 enabled、版本满足约束（`install`/`resume`/`upgrade` 会硬门禁）。
- [ ] `permissions()` 声明的每个权限码都在路由上挂了 `RequirePerm` 门禁
      （否则权限目录勾了也白勾——声明⟺实现漂移），且反向成立：
      路由上挂的每个 `RequirePerm` 码都已在本插件或依赖插件的 `permissions()`
      声明（未声明会判漂移，非超管用户永久锁死）；两向均由 `plugin:audit` 机器校验，
      pending 项仅需确认「无菜单引用也无门禁引用」的码是否应补菜单或挂门禁。
- [ ] `GET /api/plugins/{name}/contract` 显示 `contract_ok=true`、`contract_drift=[]`。
- [ ] 读路由挂 `AuthMiddleware`；写操作在控制器内做超管门禁。
- [ ] 对外 ID 全部 `HashId::enc()`，列表分页走 `ApiResponse`。
- [ ] 已在 `config/plugins.php` 登记 + `php kode reload` 生效。
- [ ] `php kode route:list --source=plugin:<name>` 能看到路由。
- [ ] `php -l` 每个新文件零错误。
