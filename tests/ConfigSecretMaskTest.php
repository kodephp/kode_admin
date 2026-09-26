<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\ConfigController;
use app\support\HotCache;
use app\support\PluginConfig;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Request as HttpRequest;
use Kode\Http\Psr7\Message\ServerRequest;

/**
 * 配置中心读接口的脱敏回归：插件 schema 的 `secret` 必须在 `/api/configs` 上落地。
 *
 * 为什么单独立一份而不并进 StorageConfigMaskTest：那一份治的是
 * `kode_storage_configs.config`（JSON 列，按字段名递归掩码），而插件配置的**值**
 * 落在 `kode_sys_configs`（cgroup=plugin），键形态是 `plugin.<name>.<field>`。
 * 这条链上曾经只有「键名子串」一条规则（secret|passwd|token|private|salt|credential），
 * 而 `ak` / `sk` 一个字母都不命中——于是任何拿着 `configs:view`（系统设置页）的非超管
 * 都能从整表列表里读到对象存储的 AccessKey/SecretKey，
 * 而同样的值在存储通道列表里是掩码的。两条规则缺一条都漏，所以两条都要有断言。
 *
 * 隔离性：本测试可能撞到库里真实存在的 `plugin.*` 行（管理员配过存储驱动），
 * 因此一律「先记录原值再改，tearDown 原值写回」而不是 DELETE——
 * 删掉别人的真实凭证属于用测试制造事故。
 */
final class ConfigSecretMaskTest extends TestCase
{
    /** 探针明文：任何一条泄漏断言都以「响应里不得出现它」为判据。 */
    private const LEAK = 'AKIDleakprobe1234567890';

    private const SECRET_KEY = 'plugin.storage_driver.ak';

    private const PLAIN_KEY = 'zzregression.plain_key';

    private const WORD_KEY = 'zzregression.api_token';

    /** @var array<string, string|null> 键 => 原始 cvalue（null = 原本不存在） */
    private array $originals = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->originals as $key => $value) {
            if ($value === null) {
                Db::delete('DELETE FROM kode_sys_configs WHERE ckey = ?', [$key]);
            } else {
                Db::update('UPDATE kode_sys_configs SET cvalue = ? WHERE ckey = ?', [$value, $key]);
            }
            HotCache::forget("config:{$key}");
        }
        $this->originals = [];

        parent::tearDown();
    }

    /**
     * 播种一个键，并记下原值（没有原值则记下 null，收尾时删行）。
     *
     * 先记后写：同一键被多个用例改写时，只有第一次记下的才是真实原值。
     */
    private function seed(string $key, string $value): void
    {
        $rows = Db::select('SELECT cvalue FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);
        if (!array_key_exists($key, $this->originals)) {
            $this->originals[$key] = $rows === [] ? null : (string) ($rows[0]['cvalue'] ?? '');
        }

        if ($rows === []) {
            Db::insert(
                'INSERT INTO kode_sys_configs (ckey, cvalue, remark, ctype, ctitle, cgroup, sort, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$key, $value, '脱敏回归临时', 'text', '脱敏回归', 'custom', 0]
            );
        } else {
            Db::update('UPDATE kode_sys_configs SET cvalue = ? WHERE ckey = ?', [$value, $key]);
        }
        HotCache::forget("config:{$key}");
    }

    /**
     * 前提不能空转：secret 集合必须真的来自插件 schema。
     *
     * 若有人把 configSchema() 里的 `secret` 摘掉，secretKeys() 会少一项，
     * 本用例先红——否则后面的掩码断言会因为「压根没这个键」而假绿。
     */
    public function test_secret_key_set_comes_from_plugin_schema(): void
    {
        $keys = PluginConfig::secretKeys();

        self::assertArrayHasKey(self::SECRET_KEY, $keys, 'storage_driver 的 ak 必须在 schema 里写着 secret');
        self::assertArrayHasKey('plugin.storage_driver.sk', $keys);
        self::assertArrayHasKey('plugin.flysystem_storage.ak', $keys, '远程驱动插件同样声明了 ak/sk');

        $schema = PluginConfig::schemaFor('storage_driver');
        self::assertTrue($schema['ak']['secret'] ?? false, 'secret 的 SoT 是插件代码，不是数据库');
    }

    public function test_list_endpoint_masks_plugin_secret_but_not_ordinary_keys(): void
    {
        $this->seed(self::SECRET_KEY, self::LEAK);
        $this->seed(self::PLAIN_KEY, '普普通通的站点文案');

        $body = (string) (new ConfigController())->index()->getBody();

        self::assertStringNotContainsString(self::LEAK, $body, '整表列表带出了明文 AccessKey');
        self::assertStringContainsString(PluginConfig::MASK, $body);
        self::assertStringContainsString('普普通通的站点文案', $body, '非 secret 键不能被连带抹掉');
    }

    public function test_single_key_read_masks_plugin_secret(): void
    {
        $this->seed(self::SECRET_KEY, self::LEAK);

        $body = (string) $this->read(self::SECRET_KEY)->getBody();

        self::assertStringNotContainsString(self::LEAK, $body, '单键读取带出了明文 AccessKey');
        self::assertStringContainsString(PluginConfig::MASK, $body);
    }

    /**
     * 负向对照：普通键必须**不**被掩码。
     *
     * 只有正向断言的话，「把所有值都掩掉」也能让整套用例绿——
     * 而那样配置页就再也读不回任何已保存的值了。
     */
    public function test_ordinary_key_is_still_readable(): void
    {
        $this->seed(self::PLAIN_KEY, '可读的默认值');

        $body = (string) $this->read(self::PLAIN_KEY)->getBody();

        self::assertStringContainsString('可读的默认值', $body);
        self::assertStringNotContainsString(PluginConfig::MASK, $body);
    }

    /**
     * 规则一（键名字样）与「password 刻意不入库」这条取舍。
     *
     * `auth.password_min_length` 是纯策略键，掩掉等于管理员再也看不出密码长度要求；
     * 所以词表用 `passwd` 而不是 `password`。两个方向都要钉住，
     * 否则下一次「顺手补全词表」就会把它掩进去。
     */
    public function test_word_list_rule_and_password_policy_exception(): void
    {
        $this->seed(self::WORD_KEY, self::LEAK);
        $this->seed('auth.password_min_length', '8');

        $leaked = (string) (new ConfigController())->index()->getBody();

        self::assertStringNotContainsString(self::LEAK, $leaked, '键名含 token 的键必须走词表规则掩码');
        self::assertStringContainsString('"ckey":"auth.password_min_length"',$leaked);
        self::assertStringContainsString('"cvalue":"8"', $leaked, 'password_min_length 属于策略键，不得被掩码');
    }

    /** 以 GET /api/configs/{key} 的形态调用控制器。 */
    private function read(string $key): \Psr\Http\Message\ResponseInterface
    {
        HttpRequest::setRequest(
            (new ServerRequest('GET', '/api/configs/' . $key))
                ->withAttribute('_route_params', ['key' => $key])
        );

        return (new ConfigController())->get();
    }
}
