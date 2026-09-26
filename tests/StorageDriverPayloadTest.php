<?php

declare(strict_types=1);

namespace app\tests;

use app\support\StorageManager;
use Kode\Event\Dispatcher;
use Kode\Framework\Testing\TestCase;

/**
 * 存储驱动派发载荷的形状回归：`disk()` 的嵌套形态必须在派发前摊平。
 *
 * 为什么盯这个形状：同一个词 `disk` 在本仓有过两种形态——
 *  - 核心侧 `StorageManager::disk()` 返回 `{id, driver, config:{endpoint, bucket, ak, sk…}}`；
 *  - 插件驱动（FlysystemStoragePlugin::createFilesystem）读的是 `$disk['endpoint']` 这类**顶层键**。
 *
 * `transfer()` 一旦直接把前者喂给 `pushRemote()`，配置就永远读不到：
 * 「连通性测试通过、批量转移必失败」——同一份盘配置，两条路径只有一条能用，
 * 而失败信息（"存储驱动配置不完整"）还会把人引向「用户没填全」这个错误方向。
 *
 * 所以契约是：**派发口只认平铺形态**，且所有调用方（含 `StorageController::test()`）都传
 * `disk()` 的原样返回，摊平只在 `pushRemote()` 进门做一次。键与 URL 的同源性问题在
 * {@see StorageRemotePushTest} 里守（旧实现是驱动另算一份 `{prefix}/{Y/m/d}/{id}_{name}`，
 * 展示侧按 path 算，两边永远对不上，等于「transfer 成功后前端点开 404」）。
 */
final class StorageDriverPayloadTest extends TestCase
{
    /** 不撞任何已声明驱动的键：确保不会有真驱动监听器拿它去发网络请求。 */
    private const PROBE_DRIVER = 'gate_probe_driver';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    public function test_nested_disk_config_is_flattened_before_dispatch(): void
    {
        $seen = $this->captureDispatch(function (): void {
            // transfer() 的真实入参形态：StorageManager::disk() 的原样返回。
            StorageManager::pushRemote([
                'id'     => 7,
                'driver' => self::PROBE_DRIVER,
                'config' => [
                    'endpoint' => 'https://oss.example.invalid',
                    'bucket'   => 'kode-test',
                    'ak'       => 'AK',
                    'sk'       => 'SK',
                    'prefix'   => 'media',
                ],
            ], '/tmp/kode-missing-probe.jpg', 42);
        });

        self::assertCount(1, $seen, '监听器没被调用：pushRemote 根本没派发，本断言会空转');
        $disk = $seen[0]['disk'];

        self::assertIsArray($disk);
        foreach (['endpoint', 'bucket', 'ak', 'sk', 'prefix'] as $key) {
            self::assertArrayHasKey(
                $key,
                $disk,
                "驱动读的是顶层键 \$disk['{$key}']，嵌套在 config 里它永远取到空串"
            );
        }
        self::assertSame('https://oss.example.invalid', $disk['endpoint']);
        self::assertSame(7, $disk['id'], '盘 id 不能被媒体配置覆盖');
        self::assertSame(self::PROBE_DRIVER, $disk['driver']);
        self::assertSame(42, $seen[0]['id'], '事件顶层 id 是媒体 id，与 disk.id 不同义');
        self::assertArrayNotHasKey('config', $disk, '嵌套 config 该被摊平掉，别再留一份歧义源');
    }

    /** 已平铺的入参必须原样透传，摊平要幂等（派发口只算一次，调用方不必各自抹平）。 */
    public function test_already_flat_payload_passes_through_unchanged(): void
    {
        $flat = ['id' => 3, 'driver' => 's3', 'endpoint' => 'https://s3.example.invalid', 'bucket' => 'b'];
        self::assertSame($flat, StorageManager::flattenDisk($flat));
        self::assertSame($flat, StorageManager::flattenDisk(StorageManager::flattenDisk($flat)));
    }

    /** config 非数组（脏数据 / 手工 SQL 塞了标量）时不得抛错，也不能把脏值当配置摊开。 */
    public function test_non_array_config_is_left_alone(): void
    {
        $disk = ['id' => 1, 'driver' => 's3', 'config' => 'not-json'];
        self::assertSame($disk, StorageManager::flattenDisk($disk));

        $empty = ['id' => 1, 'driver' => 's3', 'config' => []];
        self::assertSame(['id' => 1, 'driver' => 's3'], StorageManager::flattenDisk($empty));
    }

    /**
     * 注册一次性监听器执行 $run，收回每一次派发的原始数据。
     *
     * 用未实现的驱动名，保证 flysystem 监听器早退、绝不真的连外部端点。
     *
     * @return list<array<string, mixed>>
     */
    private function captureDispatch(callable $run): array
    {
        $seen = [];
        resolve(Dispatcher::class)->listen(
            StorageManager::REMOTE_RESOLVE_EVENT,
            static function (object $event) use (&$seen): void {
                $seen[] = $event->getData();
            }
        );
        $run();

        return $seen;
    }
}
