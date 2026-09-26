<?php

declare(strict_types=1);

namespace Kode\Plugins;

use app\support\PluginContract;
use app\support\StorageManager;
use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;
use Kode\Database\Db\Db;

/**
 * 对象存储驱动插件（参考实现：能力契约 + 驱动注册）
 *
 * 背景：核心 StorageManager 只内置 local。远程对象存储（oss/s3/cos…）需要独立
 * SDK、密钥与生命周期，属插件范畴——核心不再为「配了却推送不了」的空壳驱动
 * 留口子，驱动必须由插件真正实现并注册。
 *
 * 契约示范（全部方法幂等）：
 *  - configSchema()：声明配置项，随 plugin.json 契约下发给市场侧审阅；
 *  - drivers()：向平台声明本插件提供的存储驱动键；
 *  - permissions()：声明权限码（菜单声明时审计校验 auth_code 已声明）；
 *  - menus()：本插件为空——纯组件插件不声明页面（见方法注释）；
 *  - events()：声明本插件监听 storage_driver_resolve（核心推送媒体时回调驱动）；
 *  - requires()：声明依赖（本例无）。
 *
 * 接入核心：驱动被登记后，StorageManager::transfer() 不再返回「需先部署 SDK」，
 * 而是通过 storage_driver_resolve 事件调用插件驱动完成真实上传。
 */
final class StorageDriverPlugin implements PluginInterface
{
    /** 驱动键：与 StorageManager 的 driver 字段对应。 */
    public const DRIVER_LOCAL = 'local';

    public function name(): string
    {
        // 约定：插件名恒等于 URL 前缀（本插件无前端页面，仅组件能力）
        return 'storage_driver';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function license(): string
    {
        return 'MIT';
    }

    public function author(): string
    {
        return 'kode';
    }

    /** @return list<string> 本插件所属表 */
    public function tables(): array
    {
        return ['kode_storage_drivers'];
    }

    /** 安装：建表（幂等） */
    public function install(): void
    {
        Db::statement(
            'CREATE TABLE IF NOT EXISTS kode_storage_drivers (
                id         BIGSERIAL PRIMARY KEY,
                driver     VARCHAR(32)  NOT NULL UNIQUE,
                endpoint   VARCHAR(255) NOT NULL DEFAULT \'\',
                bucket     VARCHAR(128) NOT NULL DEFAULT \'\',
                prefix     VARCHAR(128) NOT NULL DEFAULT \'\',
                ak         VARCHAR(128) NOT NULL DEFAULT \'\',
                sk         VARCHAR(255) NOT NULL DEFAULT \'\',
                enabled    SMALLINT     NOT NULL DEFAULT 1,
                created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )'
        );
    }

    /** 卸载：默认保留数据，$purge=true 才删表 */
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

    /** 升级钩子（幂等增量变更） */
    public function upgrade(string $from, string $to): void
    {
        // 1.0.0 → 1.1.0：ALTER TABLE kode_storage_drivers ADD COLUMN IF NOT EXISTS ...
    }

    /**
     * 配置 schema：后台据此自动渲染配置页，字段类型见 PLUGIN-DEV §5.4。
     *
     * @return array<string, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            'endpoint' => ['label' => 'Endpoint', 'type' => 'string', 'placeholder' => 'https://oss-cn-hangzhou.aliyuncs.com'],
            'bucket'   => ['label' => 'Bucket', 'type' => 'string', 'required' => true],
            'prefix'   => ['label' => '路径前缀', 'type' => 'string'],
            'ak'       => ['label' => 'AccessKey ID', 'type' => 'string', 'secret' => true],
            'sk'       => ['label' => 'AccessKey Secret', 'type' => 'string', 'secret' => true],
        ];
    }

    /**
     * 本插件提供的存储驱动键。
     * 契约红线：这里声明的驱动必须有真实实现（本插件在 boot 中注册 local 兜底驱动；
     * 远程驱动由具体 SDK 子插件补充，未实现的不得声明）。
     *
     * @return list<string>
     */
    public function drivers(): array
    {
        return [self::DRIVER_LOCAL];
    }

    /** @return list<string> 依赖的其它插件（未安装仅提示，不阻断审计） */
    public function requires(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['storage_driver:view', 'storage_driver:manage'];
    }

    /**
     * 本插件是纯组件插件，不声明菜单。
     *
     * 理由（声明⟺实现零漂移）：菜单即"存在前端页面"的声明，但本插件没有页面——
     * 前端无 views/kode/storage-drivers/ 视图，映射表也无 /storage-drivers 条目，
     * 声明菜单只会渲染出通用占位页。存储磁盘的管理界面是核心职责
     * （菜单「存储配置」/storage → views/kode/storage/index.vue，仅允许 local 盘）；
     * 远程驱动真正的配置页应在其 SDK 子插件落地、能真实推送之后再声明。
     */
    public function menus(): array
    {
        return [];
    }

    /**
     * 事件声明（契约语义：本插件**监听**哪些事件）。
     *
     * 声明即须兑现——register() 里对应有一条 addListener()，否则事件派发时
     * 本插件收不到（PluginContract::auditEvents 会判漂移）。用常量而非字面量，
     * 保证与 StorageManager 派发侧共用同一真值源。
     *
     * @return array<string, array{0: class-string, 1: string}> 事件名 => 监听器
     */
    public function events(): array
    {
        return [
            StorageManager::REMOTE_RESOLVE_EVENT => [self::class, 'resolve'],
        ];
    }

    public function register(PluginManager $manager): void
    {
        // 组件插件不注册前端页面路由，仅注册事件：核心推送媒体时回调插件驱动。
        $manager->addListener(StorageManager::REMOTE_RESOLVE_EVENT, [self::class, 'resolve']);
    }

    public function boot(PluginManager $manager): void
    {
        // 自检：本插件声明的能力是否零漂移（失败只告警，不阻断启动）。
        try {
            $audit = PluginContract::audit($this);
            if (!$audit['ok']) {
                logger()->warning('存储驱动插件契约漂移：' . implode('；', $audit['drift']));
            }
        } catch (\Throwable) {
        }
    }

    /**
     * 驱动解析：核心 StorageManager::pushRemote() 派发 storage_driver_resolve。
     *
     * 契约（与 StorageManager::pushRemote 对齐）：
     *  - 入参用 `get()` 读取——框架 Kode\Event\Event **没有 `__get`**，
     *    写 `$event->driver` 恒得 null（曾导致 local 驱动被误判为未实现）；
     *  - 结果用 `set('pushed', bool)` / `set('url')` / `set('msg')` 回报；
     *  - 未实现的驱动如实拒绝（pushed=false），绝不静默成功。
     */
    public static function resolve(object $event): void
    {
        $driver = (string) (self::read($event, 'driver') ?? '');
        $path = (string) (self::read($event, 'path') ?? '');

        if ($driver === self::DRIVER_LOCAL) {
            // local 盘无需远程推送，核心仅需改 disk_id 登记。
            self::write($event, ['pushed' => true, 'url' => $path, 'msg' => 'local 驱动无需推送']);

            return;
        }

        self::write($event, [
            'pushed' => false,
            'url'    => '',
            'msg'    => "驱动 {$driver} 未实现：请补充对应 SDK 子插件（契约要求声明⟺实现）",
        ]);
    }

    /** 读取事件数据：兼容 Kode\Event\Event（get()）与普通对象（属性访问）。 */
    private static function read(object $event, string $key): mixed
    {
        if (method_exists($event, 'get')) {
            return $event->get($key);
        }

        return $event->{$key} ?? null;
    }

    /** 回报结果：Kode\Event\Event 走 set()，普通对象回写属性（便于测试）。 */
    private static function write(object $event, array $data): void
    {
        if (method_exists($event, 'set')) {
            foreach ($data as $k => $v) {
                $event->set($k, $v);
            }

            return;
        }
        if (method_exists($event, 'fill')) {
            $event->fill($data);

            return;
        }
        foreach ($data as $k => $v) {
            $event->{$k} = $v;
        }
    }
}
