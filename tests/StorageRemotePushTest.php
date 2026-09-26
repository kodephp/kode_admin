<?php

declare(strict_types=1);

namespace app\tests;

use app\support\StorageManager;
use Kode\Event\Dispatcher;
use Kode\Framework\Testing\TestCase;
use Kode\Plugins\FlysystemStoragePlugin;
use League\Flysystem\Filesystem;

/**
 * 远程推送这条链路的四处独立死因（缺一处都推不动，全仓此前没有一条能走通）。
 *
 * 这条链的形态是「核心派发 storage_driver_resolve，插件驱动真实上传」，
 * 而核心与插件之间靠一份数组约定对接。约定一旦在两处各自解释，就是四件事同时坏：
 *
 *  1. 载荷形状：`disk()` 的嵌套 config vs 驱动读的顶层键（已由 flattenDisk 收口，
 *     见 StorageDriverPayloadTest）；
 *  2. 本地文件路径：核心传的是 `kode_media.path` 那种 `uploads/...` 相对串，
 *     驱动 `is_file($path)` 按**进程 CWD** 解析。守护进程的 CWD 实测是 `/`，
 *     于是永远「源文件不存在」——错误信息还是真的，只是没人会想到是调用方给错了形状；
 *  3. 对象键有两份公式：驱动自己算 `{prefix}/{date}/{mediaId}_{name}`，
 *     而展示侧 `remoteUrl()` 算 `{prefix}/{去掉 uploads/ 的 path}`。两边永远对不上，
 *     推成功后前端拿到的 URL 必 404；且驱动构造适配器时还额外挂了 pathPrefix，
 *     对象实际落在 `{prefix}/{prefix}/…`——双前缀；
 *  4. 适配器构造用错了 flysystem 1.x 的写法：`AwsS3V3Adapter::__construct` 的第 3 参是
 *     `string $prefix`、`Filesystem::__construct` 的第 2 参是 `array $config`，
 *     而这里传的是 `League\Flysystem\Config` 对象。在 strict_types 的文件里，
 *     配置填全（唯一会走到这一行的条件）立刻 TypeError；
 *  5. Guzzle 客户端手搭 handler：`new CurlFactory()` 在本仓装的 guzzle 7 里缺必传参数，
 *     建客户端就 ArgumentCountError（这一条是本轮跑本回归时逼出来的，此前被 1/2 挡在前面）。
 *
 * 收口方向（本文件守的就是这个方向）：**键、URL、本地绝对路径全部由核心在唯一派发点算好**，
 * 驱动只认 `path`（绝对，可读）+ `key`（完整对象键，prefix 已含且只出现一次），
 * 不再自带第二份公式。
 */
final class StorageRemotePushTest extends TestCase
{
    /** 不撞任何已声明驱动的键：保证不会有真驱动监听器拿它去发网络请求。 */
    private const PROBE_DRIVER = 'gate_probe_driver';

    /** 测试用的媒体相对路径（真实形状：kode_media.path 恒为 `uploads/…`）。 */
    private const MEDIA_PATH = 'uploads/2026/09/gate_report.pdf';

    /** @var list<string> */
    private array $tempFiles = [];

    /** 媒体绝对路径（在 setUp 算好：tearDown 里再调 mediaFile() 会把刚删掉的文件造回来）。 */
    private string $mediaAbs = '';

    /** 由本测试创建的目录，测试结束才回收；不是自己建的一律不动。 */
    private ?string $createdDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        $this->mediaAbs = StorageManager::localPath((string) self::MEDIA_PATH);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
        if ($this->createdDir !== null && is_dir($this->createdDir)
            && (array_diff(scandir($this->createdDir) ?: [], ['.', '..']) === [])) {
            @rmdir($this->createdDir);
        }
        $this->createdDir = null;

        parent::tearDown();
    }

    // ─── 死因 4：适配器构造 ────────────────────────────────────────

    /**
     * 配置填全时适配器必须建得起来。
     *
     * 这一条是「配置不完整就早退」之后的第一行真实构造，之前从未被执行到：
     * 传 `Config` 对象给 `string $prefix` 在 strict_types 下是 TypeError，
     * 于是唯一能建成 Filesystem 的路径一条都没有。
     */
    public function test_filesystem_is_constructible_with_a_complete_config(): void
    {
        $ref = new \ReflectionMethod(FlysystemStoragePlugin::class, 'createFilesystem');
        $ref->setAccessible(true);

        $fs = $ref->invokeArgs(null, [self::PROBE_DRIVER, self::remoteDisk()]);

        self::assertInstanceOf(Filesystem::class, $fs, '建不出实例：远程推送一条路径都走不到 write()');
    }

    // ─── 死因 2/3：派发载荷里的 path 与 key ────────────────────────

    /**
     * 驱动拿到的是**绝对**本地路径 + **完整**对象键。
     */
    public function test_dispatch_hands_the_driver_an_absolute_path_and_a_full_key(): void
    {
        $file = $this->mediaFile();
        $seen = $this->captureDispatch(function () use ($file): void {
            // transfer() 的真实入参：`kode_media.path` 原样（相对 storage/ 的串）
            StorageManager::pushRemote(
                ['id' => 7, 'driver' => self::PROBE_DRIVER, 'config' => self::remoteDisk()],
                (string) self::MEDIA_PATH,
                42
            );
        });

        self::assertCount(1, $seen, '监听器没被调用：pushRemote 根本没派发，本断言会空转');
        $payload = $seen[0];

        self::assertSame(
            $file,
            $payload['path'],
            '传相对串给驱动 = 让它按进程 CWD 猜；守护进程 CWD 是 /，永远 is_file=false'
        );
        self::assertTrue(is_file((string) $payload['path']), '派发出去的 path 必须真的可读');
        self::assertSame(
            'media/2026/09/gate_report.pdf',
            $payload['key'],
            'prefix 只能出现一次，且日期/mediaId 这类第二份公式必须消失'
        );
        self::assertSame(42, $payload['id'], '事件顶层 id 是媒体 id');
        self::assertSame('https://oss.example.invalid', $payload['disk']['endpoint'], 'disk 仍是平铺形态');
    }

    /**
     * 核心不变量：驱动写进去的键 == 展示侧算出来的键。
     *
     * 这两处此前是两份公式（`media/media/2026/09/24/42_gate_report.pdf` vs
     * `media/2026/09/gate_report.pdf`），意味着 transfer 成功后 disk_id 翻了、
     * 前端展示的远程 URL 却指向一个从不存在过的对象。
     */
    public function test_written_key_and_published_url_are_the_same_object(): void
    {
        $disk = self::remoteDisk();
        $key = StorageManager::remoteObjectKey($disk, (string) self::MEDIA_PATH);
        $url = StorageManager::remoteObjectUrl($disk, $key);

        self::assertSame('media/2026/09/gate_report.pdf', $key);
        self::assertSame('https://oss.example.invalid/kode-test/media/2026/09/gate_report.pdf', $url);
        // 路径式寻址：URL path = /{bucket}/{key}，去掉 bucket 段必须正好回到对象键
        self::assertSame(
            '/' . self::remoteDisk()['bucket'] . '/' . $key,
            (string) parse_url($url, PHP_URL_PATH),
            'URL 路径与对象键不一致 = 写进去的和端上来点开的不是同一个对象'
        );

        // 派发出去的键必须与读侧公式同源（不是各自再算一遍）
        $seen = $this->captureDispatch(function () use ($disk): void {
            StorageManager::pushRemote(['id' => 7, 'driver' => self::PROBE_DRIVER, 'config' => $disk], (string) self::MEDIA_PATH, 42);
        });
        self::assertSame($key, $seen[0]['key'] ?? null, '驱动侧的键不是核心算的那一份');
    }

    /** 无 prefix / prefix 自带斜杠：键的拼法不能产生空段或双斜杠。 */
    public function test_object_key_shape_is_stable_across_config_variants(): void
    {
        self::assertSame('2026/09/a.png', StorageManager::remoteObjectKey(['prefix' => ''], 'uploads/2026/09/a.png'));
        self::assertSame('media/2026/09/a.png', StorageManager::remoteObjectKey(['prefix' => '/media/'], 'uploads/2026/09/a.png'));
        self::assertSame('2026/09/a.png', StorageManager::remoteObjectKey([], 'uploads/2026/09/a.png'));
        // 嵌套形态（disk() 原样返回）必须与平铺同解，否则又是一个「两种形状」歧义源
        self::assertSame(
            'media/2026/09/a.png',
            StorageManager::remoteObjectKey(['id' => 1, 'driver' => 's3', 'config' => ['prefix' => 'media']], 'uploads/2026/09/a.png')
        );
        // endpoint/bucket 缺失时不编造 URL：回空串，由调用方回落本地地址
        self::assertSame('', StorageManager::remoteObjectUrl(['endpoint' => '', 'bucket' => 'b'], 'media/a.png'));
        self::assertSame('', StorageManager::remoteObjectUrl(['endpoint' => 'https://e', 'bucket' => ''], 'media/a.png'));
    }

    /** 探针推送要落在自己的键命名空间里，别混进媒体目录。 */
    public function test_probe_push_can_override_the_object_key(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kode_gate_probe_');
        self::assertNotFalse($tmp);
        $this->tempFiles[] = $tmp;

        $seen = $this->captureDispatch(function () use ($tmp): void {
            StorageManager::pushRemote(
                ['id' => 7, 'driver' => self::PROBE_DRIVER, 'config' => self::remoteDisk()],
                $tmp,
                0,
                'kode-probe/' . basename($tmp)
            );
        });

        self::assertSame('kode-probe/' . basename($tmp), $seen[0]['key'] ?? null);
        self::assertSame($tmp, $seen[0]['path'], '已绝对的路径不该再拼到 storage/ 下');
    }

    // ─── 静态：不许有第二份公式 ────────────────────────────────────

    /**
     * 驱动侧不得再自带「对象键 / 访问 URL / 日期目录」的算法。
     *
     * 形状对齐用，不是装饰：第 3 号死因就是驱动自己算了一份带日期与 mediaId 的键，
     * 而展示侧按 `path` 算，两边谁都没错、只是不同解。
     */
    public function test_driver_does_not_keep_a_second_key_or_url_formula(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/plugins/FlysystemStoragePlugin.php');
        self::assertGreaterThan(4000, strlen($src), '没读到插件源码，本门禁会空转');
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $src);

        foreach (['remoteKey', 'buildUrl', 'pathPrefix', 'date('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $code, "驱动里不该再出现「{$forbidden}」：键与 URL 只在核心算一次");
        }
        self::assertStringNotContainsString(
            'use League\Flysystem\Config;',
            $src,
            'flysystem 1.x 的 Config 传参写法：v3 的 prefix 是 string、Filesystem 第二参是 array'
        );
        self::assertStringContainsString("'key'", $code, '驱动必须改成读派发下来的 key');
    }

    // ─── 夹具 ──────────────────────────────────────────────────────

    /** @return array<string, mixed> 一份填全的远程盘配置（平铺形态） */
    private static function remoteDisk(): array
    {
        return [
            'endpoint' => 'https://oss.example.invalid',
            'bucket'   => 'kode-test',
            'ak'       => 'AK',
            'sk'       => 'SK',
            'prefix'   => 'media',
            'region'   => 'cn-hangzhou',
        ];
    }

    /**
     * 真造一个本地文件：驱动侧的 is_file 判定要用真实文件，不能靠断言措辞。
     *
     * 恒重写 + 恒登记删除：若「已存在就复用」，上一轮残留的文件会让
     * `is_file($payload['path'])` 这条断言空转（本轮实测就这么留下过残file）。
     * 路径不会撞真实上传——`UploadController::fileName()` 出的是随机名。
     */
    private function mediaFile(): string
    {
        $dir = dirname($this->mediaAbs);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
            $this->createdDir = $dir;
        }
        file_put_contents($this->mediaAbs, 'gate probe');
        $this->tempFiles[] = $this->mediaAbs;

        return $this->mediaAbs;
    }

    /**
     * 注册一次性监听器执行 $run，收回每一次派发的原始数据。
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
