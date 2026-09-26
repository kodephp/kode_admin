<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\ConfigController;
use app\support\HotCache;
use app\support\PluginConfig;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * 配置中心 KV 写侧（POST/DELETE /api/configs）的归属与保留原值回归。
 *
 * 读侧对 secret 键恒出 `******`（见 ConfigSecretMaskTest）。这条掩码线本身就是
 * 一个破坏入口：整行 upsert 把「客户端没发 value」当成「要清空」，而列表页要渲染
 * 就得把掩码映射成空——于是管理端点一次「保存配置」，每一行看不见的凭证都被抹掉，
 * 下一次读仍然是掩码，页面上完全看不出改过什么。
 *
 * 两头都测：
 *  - plugin.* 归 PluginConfig 托管（按 schema 校验、认掩码、失效插件自己的缓存键），
 *    这个口写/删它一律 400；
 *  - 掩码字面量永不落库；缺 value = 保留原值，显式空串仍是清空。
 */
final class ConfigWriteOwnershipTest extends TestCase
{
    protected bool $independentApp = true;

    /** 看起来像真凭证的探针值：出现在响应或库里即说明泄漏/被改写。 */
    private const LEAK = 'AKIDwriteprobe1234567890';

    private const NEW_VALUE = 'AKIDreplacedbykvport0000';

    /** 插件 schema 声明的 secret 键（Flysystem 存储驱动）。 */
    private const PLUGIN_AK = 'plugin.storage_driver.ak';

    /** 手工建的、名字命中掩码词表的键。 */
    private const OWN_TOKEN = 'zzregression.access_token';

    /** 普通键：正对照，证明门禁没把整张表一起挡死。 */
    private const PLAIN = 'zzregression.plain_key';

    /** @var array<string, string|null> 改动前的 cvalue；null = 原本没有这一行 */
    private array $originals = [];

    /** @var array<string, string|null> 改动前的 remark（部分用例会改它，需一并归位） */
    private array $originalRemarks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->originals as $key => $before) {
            if ($before === null) {
                Db::delete('DELETE FROM kode_sys_configs WHERE ckey = ?', [$key]);
            } else {
                Db::update(
                    'UPDATE kode_sys_configs SET cvalue = ?, remark = ?, updated_at = NOW() WHERE ckey = ?',
                    [$before, (string) ($this->originalRemarks[$key] ?? ''), $key]
                );
            }
            $this->forgetBothNamespaces($key);
        }
        $this->originals = [];
        $this->originalRemarks = [];

        HttpRequest::clear();

        parent::tearDown();
    }

    public function test_writing_a_plugin_secret_through_the_kv_port_is_refused(): void
    {
        // 防空转：这一行确实归插件托管，且它当前存的是真凭证。
        $this->assertTrue(PluginConfig::isSecretKey(self::PLUGIN_AK), self::PLUGIN_AK . ' 不再是 schema secret，本测试失去对象');
        $this->seed(self::PLUGIN_AK, self::LEAK);

        $result = $this->set(['key' => self::PLUGIN_AK, 'value' => self::NEW_VALUE]);
        $this->assertSame(400, $result['status'], 'KV 口写掉了插件凭证：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('插件', (string) ($result['body']['msg'] ?? ''));
        $this->assertSame(self::LEAK, $this->stored(self::PLUGIN_AK), '被拒的写入仍然落了库');
    }

    public function test_deleting_a_plugin_config_row_through_the_kv_port_is_refused(): void
    {
        $this->seed(self::PLUGIN_AK, self::LEAK);

        $result = $this->remove(self::PLUGIN_AK);
        $this->assertSame(400, $result['status'], 'KV 口删掉了插件配置行：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(self::LEAK, $this->stored(self::PLUGIN_AK));
    }

    public function test_the_mask_sentinel_is_never_stored(): void
    {
        $this->seed(self::OWN_TOKEN, self::LEAK);

        // 编辑弹窗把行原样回填（含掩码）后保存——这就是「改个备注，凭证没了」。
        $result = $this->set(['key' => self::OWN_TOKEN, 'value' => PluginConfig::MASK, 'remark' => '只想改备注']);
        $this->assertSame(400, $result['status'], '掩码字面量被当成真值存回去了');
        $this->assertSame(self::LEAK, $this->stored(self::OWN_TOKEN));
    }

    public function test_absent_value_keeps_the_stored_secret_while_siblings_update(): void
    {
        $this->seed(self::OWN_TOKEN, self::LEAK);

        $result = $this->set(['key' => self::OWN_TOKEN, 'remark' => '只改备注', 'title' => '只改标题']);
        $this->assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(self::LEAK, $this->stored(self::OWN_TOKEN), '缺 value 被写成了空串');

        $row = Db::select('SELECT remark, ctitle FROM kode_sys_configs WHERE ckey = ?', [self::OWN_TOKEN])[0];
        $this->assertSame('只改备注', (string) $row['remark'], '兄弟字段没更新，保留原值等于整行拒绝更新');
        $this->assertSame('只改标题', (string) $row['ctitle']);
    }

    public function test_explicit_empty_value_still_clears(): void
    {
        $this->seed(self::OWN_TOKEN, self::LEAK);

        $result = $this->set(['key' => self::OWN_TOKEN, 'value' => '']);
        $this->assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('', (string) $this->stored(self::OWN_TOKEN), '「显式传空串才是清空」的口径被改坏了');
    }

    public function test_a_plain_key_still_writes_and_deletes_normally(): void
    {
        $this->seed(self::PLAIN, 'before');

        $result = $this->set(['key' => self::PLAIN, 'value' => 'after']);
        $this->assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('after', $this->stored(self::PLAIN));

        $removed = $this->remove(self::PLAIN);
        $this->assertSame(200, $removed['status'], json_encode($removed['body'], JSON_UNESCAPED_UNICODE));
        $this->assertNull($this->stored(self::PLAIN));
    }

    /** @param array<string, mixed> $payload @return array{status: int, body: array<string, mixed>} */
    private function set(array $payload): array
    {
        HttpRequest::setRequest(
            (new ServerRequest('POST', '/api/configs'))->withParsedBody($payload)
        );
        $response = (new ConfigController())->set();
        $this->assertInstanceOf(ResponseInterface::class, $response);

        return ['status' => $response->getStatusCode(), 'body' => (array) json_decode((string) $response->getBody(), true)];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function remove(string $key): array
    {
        HttpRequest::setRequest(
            (new ServerRequest('DELETE', '/api/configs/' . $key))
                ->withAttribute('_route_params', ['key' => $key])
        );
        $response = (new ConfigController())->destroy();
        $this->assertInstanceOf(ResponseInterface::class, $response);

        return ['status' => $response->getStatusCode(), 'body' => (array) json_decode((string) $response->getBody(), true)];
    }

    private function stored(string $key): ?string
    {
        $rows = Db::select('SELECT cvalue FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);

        return $rows === [] ? null : (string) $rows[0]['cvalue'];
    }

    /** 记下行原值再写入（不存在的键先建形，保证 tearDown 能精确归位）。 */
    private function seed(string $key, string $value): void
    {
        $rows = Db::select('SELECT cvalue, remark FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);
        $this->originals[$key] = $rows === [] ? null : (string) $rows[0]['cvalue'];
        $this->originalRemarks[$key] = $rows === [] ? null : (string) $rows[0]['remark'];

        if ($rows === []) {
            Db::insert(
                'INSERT INTO kode_sys_configs (ckey, cvalue, remark, ctype, ctitle, cgroup, sort, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$key, $value, 'regression probe', 'text', 'regression', 'custom', 0]
            );
        } else {
            Db::update('UPDATE kode_sys_configs SET cvalue = ? WHERE ckey = ?', [$value, $key]);
        }
        $this->forgetBothNamespaces($key);
    }

    /** 两个缓存命名空间都要失效：插件读的是 `plugin:config:{key}`。 */
    private function forgetBothNamespaces(string $key): void
    {
        HotCache::forget("config:{$key}");
        HotCache::forget("plugin:config:{$key}");
    }
}
