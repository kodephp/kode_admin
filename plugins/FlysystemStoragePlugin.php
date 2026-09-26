<?php

declare(strict_types=1);

namespace Kode\Plugins;

use app\support\StorageManager;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;

/**
 * Flysystem 远程存储驱动插件
 *
 * 背景：StorageDriverPlugin 只内置 local 兜底；远程对象存储（s3/oss/cos/minio）
 * 需要真实 SDK 推送，否则 StorageManager::pushRemote 返回「驱动未实现」。
 *
 * 契约：监听 storage_driver_resolve 事件（由 StorageManager::pushRemote 派发）。
 * - 入参：driver / path（**绝对**本地文件） / key（**完整**远程对象键，prefix 已含） /
 *   disk（平铺配置：endpoint、bucket、ak、sk…）
 * - 回报：pushed（bool） / url（远程 URL） / msg（错误描述）
 * - 未实现的驱动如实拒绝（pushed=false），绝不静默成功。
 * - 对象键与访问 URL 一律由核心算（StorageManager::remoteObjectKey / remoteObjectUrl）：
 *   驱动自带第二份公式时，写进去的对象和端上点开的地址会指向两个不同键。
 *
 * 支持的驱动：
 *  - s3    — AWS S3（或任何 S3 兼容端点：MinIO、R2、Outscale 等）
 *  - oss   — 阿里云 OSS（S3 兼容模式，endpoint 需为 https://oss-cn-xxx.aliyuncs.com）
 *  - cos   — 腾讯云 COS（S3 兼容模式，endpoint 需为 https://cos.ap-xxx.myqcloud.com）
 *  - minio — MinIO（S3 兼容模式）
 *
 * 统一使用 league/flysystem-aws-s3-v3 适配器（S3 协议），通过 endpoint 指向目标服务商。
 */
final class FlysystemStoragePlugin implements PluginInterface
{
    public const DRIVER_S3 = 's3';
    public const DRIVER_OSS = 'oss';
    public const DRIVER_COS = 'cos';
    public const DRIVER_MINIO = 'minio';

    public function name(): string
    {
        return 'flysystem_storage';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Flysystem 远程存储驱动：基于 S3 协议实现 s3/oss/cos/minio 真实上传';
    }

    public function license(): string
    {
        return 'MIT';
    }

    public function author(): string
    {
        return 'kode';
    }

    /** @return list<string> 本插件所属表（无表：盘配置由核心 kode_storage_configs 托管） */
    public function tables(): array
    {
        return [];
    }

    public function install(): void
    {
        // 无表：本插件只提供驱动实现，桶/密钥等配置读自核心表 kode_storage_configs
        // （由核心 StorageController 维护、StorageManager 取出后经事件下发）。
    }

    public function uninstall(bool $purge = false): void
    {
        // 无表需清理
    }

    public function upgrade(string $from, string $to): void
    {
    }

    /** @return list<string> 本插件提供的存储驱动键 */
    public function drivers(): array
    {
        return self::driverKeys();
    }

    /**
     * 驱动键清单（静态）。
     *
     * `drivers()` 是插件契约要求的实例方法（注册表按实例调用），
     * 而事件监听器 `resolve()` 是静态方法，无法调用实例方法——
     * 故把清单下沉为静态方法，两处共用同一真值源。
     *
     * @return list<string>
     */
    private static function driverKeys(): array
    {
        return [self::DRIVER_S3, self::DRIVER_OSS, self::DRIVER_COS, self::DRIVER_MINIO];
    }

    /** @return list<string> */
    public function requires(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return ['flysystem_storage:view'];
    }

    public function menus(): array
    {
        return [];
    }

    /**
     * 配置 schema：声明配置项（前端配置页自动渲染）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            'endpoint' => [
                'label' => 'Endpoint',
                'type' => 'string',
                'required' => true,
                'placeholder' => 'https://s3.amazonaws.com 或 https://oss-cn-hangzhou.aliyuncs.com',
            ],
            'bucket' => [
                'label' => 'Bucket',
                'type' => 'string',
                'required' => true,
            ],
            'prefix' => [
                'label' => '路径前缀',
                'type' => 'string',
                'placeholder' => 'media/',
            ],
            'ak' => [
                'label' => 'AccessKey ID',
                'type' => 'string',
                'secret' => true,
            ],
            'sk' => [
                'label' => 'AccessKey Secret',
                'type' => 'string',
                'secret' => true,
            ],
            'region' => [
                'label' => 'Region',
                'type' => 'string',
                'placeholder' => 'us-east-1（S3） / cn-hangzhou（OSS）',
            ],
        ];
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

    /**
     * 注册事件监听器：核心 StorageManager::pushRemote 派发时回调本插件。
     */
    public function register(PluginManager $manager): void
    {
        $manager->addListener(StorageManager::REMOTE_RESOLVE_EVENT, [self::class, 'resolve']);
    }

    public function boot(PluginManager $manager): void
    {
        // 自检：确认 Flysystem 类已加载（composer require 完成）
        if (!class_exists(Filesystem::class)) {
            logger()->warning('FlysystemStoragePlugin：未检测到 league/flysystem，远程推送不可用');
        }
    }

    /**
     * 驱动解析：核心派发 storage_driver_resolve 时调用。
     *
     * 契约（与 StorageManager::pushRemote 对齐）：
     *  - 入参：driver / path（**绝对**本地文件） / key（**完整**远程对象键，prefix 已含） /
     *    disk（平铺配置） / id（媒体 id，仅日志用）；
     *  - 回报：pushed（bool） / url（远程 URL） / msg（错误描述）；
     *  - 未实现的驱动如实拒绝（pushed=false），绝不静默成功。
     *
     * 落点为什么不在这里算：驱动与展示侧各算一份键就是本插件那处四号缺陷的成因
     * （对象写到 `prefix/prefix/Y/m/d/…`，前端按 path 拼出来的 URL 指向另一个键）。
     * 键、URL、绝对路径统一由核心在唯一派发点决定，驱动只管「把这个文件放到这个键」。
     *
     * @param object $event 事件对象（Kode\Event\Event 或普通对象）
     */
    public static function resolve(object $event): void
    {
        $driver = (string) (self::read($event, 'driver') ?? '');
        $path = (string) (self::read($event, 'path') ?? '');
        $key = (string) (self::read($event, 'key') ?? '');
        $disk = (array) (self::read($event, 'disk') ?? []);

        // 非本插件支持的驱动，交给其它监听者（如 StorageDriverPlugin）处理
        if (!in_array($driver, self::driverKeys(), true)) {
            return;
        }

        if ($key === '') {
            self::write($event, [
                'pushed' => false,
                'url' => '',
                'msg' => "派发未给出对象键（driver={$driver}）：远程落点只能由核心算，驱动不猜",
            ]);

            return;
        }

        // 基本校验
        if ($path === '' || !is_file($path)) {
            self::write($event, [
                'pushed' => false,
                'url' => '',
                'msg' => "源文件不存在：{$path}",
            ]);

            return;
        }

        try {
            $filesystem = self::createFilesystem($driver, $disk);
            $content = file_get_contents($path);
            if ($content === false) {
                self::write($event, [
                    'pushed' => false,
                    'url' => '',
                    'msg' => "无法读取源文件：{$path}",
                ]);

                return;
            }
            $filesystem->write($key, $content);

            self::write($event, [
                'pushed' => true,
                'url' => StorageManager::remoteObjectUrl($disk, $key),
                'msg' => '',
            ]);
        } catch (\Throwable $e) {
            self::write($event, [
                'pushed' => false,
                'url' => '',
                'msg' => "Flysystem 上传失败（{$driver}）：" . $e->getMessage(),
            ]);
        }
    }

    /**
     * 构造 Flysystem 实例。
     *
     * 所有远程驱动统一使用 S3 协议适配器（league/flysystem-aws-s3-v3），
     * 通过 endpoint 指向目标服务商（AWS / OSS / COS / MinIO 均兼容 S3 协议）。
     *
     * 适配器**不挂 pathPrefix**：派发下来的 `key` 已是含 prefix 的完整对象键，再挂一层就是双前缀。
     * 旧写法照 flysystem 1.x 的参数形状把 `League\Flysystem\Config` 传给
     * `AwsS3V3Adapter::__construct` 的 `string $prefix` 和 `Filesystem::__construct` 的
     * `array $config`——在本文件这种 strict_types 下必然 TypeError，而触发它的前提恰好是
     * 「配置填全」，也就是唯一能走到这一行的条件。
     */
    private static function createFilesystem(string $driver, array $disk): Filesystem
    {
        $endpoint = (string) ($disk['endpoint'] ?? '');
        $bucket = (string) ($disk['bucket'] ?? '');
        $ak = (string) ($disk['ak'] ?? '');
        $sk = (string) ($disk['sk'] ?? '');
        $region = (string) ($disk['region'] ?? 'us-east-1');

        if ($endpoint === '' || $bucket === '' || $ak === '' || $sk === '') {
            throw new \RuntimeException("存储驱动配置不完整（driver={$driver}）：endpoint/bucket/ak/sk 均必填");
        }

        // 构造 Guzzle 客户端（指向目标 endpoint，关闭 SSL 验证仅用于私有部署调试）
        $guzzle = self::createGuzzleClient($endpoint);

        // S3 客户端
        $s3Client = new \Aws\S3\S3Client([
            'client' => $guzzle,
            'credentials' => [
                'key' => $ak,
                'secret' => $sk,
            ],
            'endpoint' => $endpoint,
            'region' => $region,
            'version' => 'latest',
        ]);

        return new Filesystem(new AwsS3V3Adapter($s3Client, $bucket));
    }

    /**
     * 构造 Guzzle 客户端。
     *
     * - 私有部署（如 MinIO）允许 https://localhost:9000 等
     * - 生产环境强制 HTTPS
     *
     * handler 交给 Guzzle 自己选：手搭 `HandlerStack::create(new CurlHandler(new CurlFactory()))`
     * 在本仓装的 guzzle 7 里直接 ArgumentCountError（CurlFactory 的构造至少要一个统计参数），
     * 而它写在构造函数里 = 每次远程推送都在建客户端这一步就炸，连请求都发不出去。
     */
    private static function createGuzzleClient(string $endpoint): ClientInterface
    {
        $isHttps = str_starts_with($endpoint, 'https://');

        return new Client([
            'verify' => $isHttps,
            'timeout' => 30,
            'connect_timeout' => 10,
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

    /** 回报结果：Kode\Event\Event 走 set()，普通对象回写属性。 */
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
