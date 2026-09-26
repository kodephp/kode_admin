<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\DbController;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * 连接名白名单回归：`?connection=` 只能是**已注册**的连接。
 *
 * 旧实现只做格式校验（`^[a-z0-9_]{1,64}$`），任意通过格式的名字都会交给
 * Db::connection()。而 Db::getConnection() 对未知名字不会失败，它静默回落到
 * 默认配置（Db.php:528 `$connections[$name] ?? self::$config`），于是
 * `?connection=anything` 用主库凭证建了一条真连接，并永久写进
 * self::$connectionCache[$name]（worker 存活期内没有淘汰路径）。
 *
 * 实测（本机 pgsql，max_connections=100）：12 个随意连接名把 kode_app 的
 * pg_stat_activity 后端数从 2 抬到 14 且不回落；key 全部由请求方指定。
 * 一个能登录管理端的账号轮流打 11 个 worker 就能耗尽服务端连接，
 * 影响面是所有用户，而不只是这个面板。顺带，面板标题写着「连接 X」，
 * 读到的其实全是主库——这是错的数，比报错更糟。
 */
final class DbConnectionAllowlistTest extends TestCase
{
    protected bool $independentApp = true;

    /** 不在任何配置里的名字，但完全符合旧的正则 */
    private const PHANTOM = 'phantom_connection';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        HttpRequest::clear();

        parent::tearDown();
    }

    /**
     * 绑定请求。ServerRequest 的构造不会自己拆查询串（真实链路由
     * LazyServerRequest::getQueryParams 懒解析），测试里必须显式 withQueryParams，
     * 否则 $this->query('connection') 恒为 null，测的就不是那条分支了。
     */
    private function bind(string $uri): void
    {
        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        HttpRequest::setRequest(
            (new ServerRequest('GET', $uri))->withQueryParams($query)
        );
    }

    /** @return array<string, mixed> 响应信封 */
    private function call(string $method, string $uri): array
    {
        $this->bind($uri);
        $response = (new DbController())->{$method}();
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => (array) $decoded];
    }

    /** Db::$connectionCache 的当前 key（泄漏面就直接看这里）。 */
    private function cachedConnections(): array
    {
        $property = new \ReflectionProperty(Db::class, 'connectionCache');
        $property->setAccessible(true);

        return array_keys((array) $property->getValue());
    }

    public function test_phantom_name_is_rejected_before_any_connection_is_opened(): void
    {
        $this->bind('/api/db/tables?connection=' . self::PHANTOM);
        $before = $this->cachedConnections();
        $this->assertNotContains(self::PHANTOM, $before, '前置：测试进程本就带着幻影连接，取证无效');

        $result = $this->call('tables', '/api/db/tables?connection=' . self::PHANTOM);
        $this->assertSame(400, $result['status']);
        $this->assertEnvelopeMsg($result['body']);
        $this->assertNotContains(self::PHANTOM, $this->cachedConnections(), '拒绝必须发生在建连之前');
    }

    public function test_every_connection_entry_point_rejects_phantom_name(): void
    {
        foreach (['tables', 'activity', 'locks', 'topTables'] as $method) {
            $result = $this->call($method, '/api/db/' . $method . '?connection=' . self::PHANTOM);
            $this->assertSame(400, $result['status'], $method . ' 未拒绝未知连接');
        }
        $this->assertNotContains(self::PHANTOM, $this->cachedConnections());
    }

    public function test_sql_endpoint_rejects_phantom_name(): void
    {
        $this->bind('/api/db/sql?connection=' . self::PHANTOM);
        $response = (new DbController())->sql();
        $this->assertSame(400, $response->getStatusCode());
        $this->assertNotContains(self::PHANTOM, $this->cachedConnections());
    }

    /** 表级入口（columns/rows/indexes 走 table() 白名单）也不能建幻影连接。 */
    public function test_table_scoped_endpoints_reject_phantom_name(): void
    {
        foreach (['columns', 'rows', 'indexes', 'constraints'] as $method) {
            $this->bind('/api/db/kode_users/' . $method . '?connection=' . self::PHANTOM);
            $response = (new DbController())->{$method}();
            $this->assertSame(400, $response->getStatusCode(), $method . ' 未拒绝未知连接');
        }
        $this->assertNotContains(self::PHANTOM, $this->cachedConnections());
    }

    public function test_registered_connection_still_works(): void
    {
        $result = $this->call('tables', '/api/db/tables?connection=pgsql');
        $this->assertSame(200, $result['status']);
        $this->assertNotEmpty($result['body']['data'] ?? []);
    }

    public function test_missing_connection_still_uses_default(): void
    {
        $result = $this->call('tables', '/api/db/tables');
        $this->assertSame(200, $result['status']);
    }

    /** 未知但格式合法的邻居名不能因为「像分片名」就放行。 */
    public function test_shard_like_name_is_rejected_when_not_registered(): void
    {
        $this->assertTrue(Db::hasConnection('pgsql'));
        $this->assertFalse(Db::hasConnection('shard_99'));
        $result = $this->call('activity', '/api/db/activity?connection=shard_99');
        $this->assertSame(400, $result['status']);
    }

    /** 断言 msg 落在信封里（code/msg 是本项目契约，不能只回状态码）。 */
    private function assertEnvelopeMsg(array $body): void
    {
        $this->assertArrayHasKey('msg', $body);
        $this->assertStringContainsString('未知数据库连接', (string) $body['msg']);
    }
}
