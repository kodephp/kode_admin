<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\StorageController;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 存储通道配置的脱敏回归：列表接口不得带出云凭证，掩码回传不得覆盖真值。
 *
 * 两处漂移各自成灾，合起来正好把「脱敏」这件事变成假的：
 *  - `GET /api/storage/{id}/config` 一路掩码，而 `GET /api/storage`（任何登录用户可读）
 *    把 `kode_storage_configs.config` 原样吐出去——ak/sk 是云账号凭证，
 *    列表页就是最省事的取密入口；
 *  - 前端编辑表单的初值恰恰来自列表：一旦列表改成掩码，整体 PUT 回来就会把
 *    `AKID****` 当成新密钥写库，通道当场失效（与 PluginConfig 的 secret 语义同源）。
 *
 * 因此两件事必须同时成立：出去的是掩码，回来是掩码时按「保持不变」处理。
 */
final class StorageConfigMaskTest extends TestCase
{
    protected bool $independentApp = true;

    private const SECRET_AK = 'AKIDEXAMPLE123456';

    private const SECRET_SK = 'SuperSecretKeyValue';

    private static ?int $rowId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        if (self::$rowId !== null) {
            Db::delete('DELETE FROM kode_storage_configs WHERE id = ?', [self::$rowId]);
            self::$rowId = null;
        }

        parent::tearDown();
    }

    /** 反射调用私有静态工具，避免为了测试把它们抬成公共 API */
    private static function invoke(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(StorageController::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs(null, $args);
    }

    public function test_mask_hides_credentials_but_keeps_layout_fields(): void
    {
        $masked = self::invoke('maskConfig', [[
            'endpoint' => 'oss-cn-hangzhou.aliyuncs.com',
            'bucket' => 'my-bucket',
            'ak' => self::SECRET_AK,
            'sk' => self::SECRET_SK,
            'nested' => ['access_key_secret' => 'nested-secret-value'],
        ]]);

        self::assertSame('oss-cn-hangzhou.aliyuncs.com', $masked['endpoint'], '非敏感字段照常回显');
        self::assertSame('my-bucket', $masked['bucket']);
        self::assertStringNotContainsString(self::SECRET_AK, json_encode($masked) ?: '');
        self::assertStringNotContainsString('nested-secret-value', json_encode($masked) ?: '');
        self::assertSame('AKID' . str_repeat('*', strlen(self::SECRET_AK) - 4), $masked['ak'], '掩码须保留前 4 位便于辨认');
    }

    public function test_masked_round_trip_preserves_the_stored_secret(): void
    {
        $stored = ['ak' => self::SECRET_AK, 'sk' => self::SECRET_SK, 'bucket' => 'old-bucket'];
        $masked = self::invoke('maskConfig', [$stored]);

        // 用户只改了 bucket，密钥字段原样提交掩码
        $incoming = ['ak' => $masked['ak'], 'sk' => $masked['sk'], 'bucket' => 'new-bucket'];
        $merged = self::invoke('restoreMasked', [$incoming, $stored]);

        self::assertSame(self::SECRET_AK, $merged['ak'], '掩码回传等于「保持不变」');
        self::assertSame(self::SECRET_SK, $merged['sk']);
        self::assertSame('new-bucket', $merged['bucket'], '非敏感字段的修改必须落地');
    }

    /**
     * 真正改写密钥、或库里没有该项时，掩码合并不得吞掉用户输入。
     *
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $stored
     */
    #[DataProvider('nonMergeCases')]
    public function test_mask_merge_only_applies_to_unchanged_secrets(array $incoming, array $stored, array $expected): void
    {
        self::assertSame($expected, self::invoke('restoreMasked', [$incoming, $stored]));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    public static function nonMergeCases(): array
    {
        $stored = ['ak' => self::SECRET_AK, 'sk' => self::SECRET_SK, 'nested' => ['sk' => 'inner-secret-value']];

        return [
            '换新密钥' => [['ak' => 'NEWKEY0987654321'], $stored, ['ak' => 'NEWKEY0987654321']],
            '清空密钥' => [['ak' => ''], $stored, ['ak' => '']],
            '库里无此键' => [['token' => 'AAAA' . str_repeat('*', 4)], $stored, ['token' => 'AAAA' . str_repeat('*', 4)]],
            '嵌套内保持' => [['nested' => ['sk' => self::invoke('maskConfig', [$stored['nested']])['sk']]], $stored, ['nested' => ['sk' => 'inner-secret-value']]],
            '嵌套内改写' => [['nested' => ['sk' => 'brand-new-inner-secret']], $stored, ['nested' => ['sk' => 'brand-new-inner-secret']]],
            '类型不符' => [['ak' => ['x']], $stored, ['ak' => ['x']]],
        ];
    }

    public function test_list_endpoint_does_not_leak_secrets(): void
    {
        $config = json_encode(['ak' => self::SECRET_AK, 'sk' => self::SECRET_SK, 'bucket' => 'mask-probe']);
        self::$rowId = (int) Db::insert(
            'INSERT INTO kode_storage_configs (name, driver, config, is_default, created_at, updated_at) VALUES (?, ?, ?, 0, NOW(), NOW())',
            ['mask-probe-disk', 'local', $config]
        );

        $response = (new StorageController())->index();
        $body = (string) $response->getBody();

        self::assertStringNotContainsString(self::SECRET_AK, $body, '列表接口带出了明文 ak');
        self::assertStringNotContainsString(self::SECRET_SK, $body, '列表接口带出了明文 sk');
        self::assertStringContainsString('mask-probe-disk', $body, '列表本身仍应返回该通道');
        self::assertStringContainsString('"bucket":"mask-probe"', $body, '非敏感字段不应被连带抹掉');
    }

    /**
     * 完整一次「列表 → 编辑 → 保存」：掩码回传后库里的真密钥必须还在。
     *
     * 这是脱敏改造唯一会引入的新故障模式（把 `AKID****` 写成密钥），
     * 所以必须走真实的 update() 路径验证，而不是只测合并函数本身。
     */
    public function test_saving_a_masked_form_keeps_the_real_secret(): void
    {
        $config = json_encode(['ak' => self::SECRET_AK, 'sk' => self::SECRET_SK, 'bucket' => 'mask-probe']);
        self::$rowId = (int) Db::insert(
            'INSERT INTO kode_storage_configs (name, driver, config, is_default, created_at, updated_at) VALUES (?, ?, ?, 0, NOW(), NOW())',
            ['mask-roundtrip-disk', 'local', $config]
        );

        $masked = self::invoke('maskConfig', [
            json_decode((string) Db::select(
                'SELECT config FROM kode_storage_configs WHERE id = ? LIMIT 1',
                [self::$rowId]
            )[0]['config'], true),
        ]);

        // 用户在表单里只改 bucket，ak/sk 原样回传掩码
        $config = $masked;
        $config['bucket'] = 'renamed-bucket';
        $payload = ['name' => 'mask-roundtrip-disk', 'config' => $config];

        HttpRequest::setRequest(
            (new ServerRequest(
                'PUT',
                '/api/storage/' . \app\support\HashId::enc(self::$rowId),
                [],
                ['content-type' => 'application/json'],
                (string) json_encode($payload)
            ))->withAttribute('_route_params', ['id' => \app\support\HashId::enc(self::$rowId)])
        );

        $response = (new StorageController())->update();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $stored = json_decode((string) Db::select(
            'SELECT config FROM kode_storage_configs WHERE id = ? LIMIT 1',
            [self::$rowId]
        )[0]['config'], true);

        self::assertSame(self::SECRET_AK, $stored['ak'], '掩码回传把真 ak 覆盖成了 AKID****');
        self::assertSame(self::SECRET_SK, $stored['sk']);
        self::assertSame('renamed-bucket', $stored['bucket'], '非敏感字段的修改要生效');
    }
}
