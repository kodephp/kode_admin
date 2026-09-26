<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\SessionController;
use app\auth\EncryptedJwtGuard;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Jwt\KodeJwt;
use Psr\Http\Message\ResponseInterface;

/**
 * 会话管理三个端点的回归（列表 / 强制下线 / 清理过期）。
 *
 * 两条独立的失效链都在这一个页面上：
 *  1. 列表 SQL 选了 `u.username / u.realname`，而 kode_users 只有 name/email —— 必然 500；
 *     且没有 `ll.status = 1`，登录失败记录也被当会话列出。
 *  2. 强制下线把数据库自增 id 原样传给 revokeUserTokens()，
 *     而令牌里落地的是 `u_` 不透明 uid、用户活跃 Token 列表键由它拼成，
 *     于是撤销打到一把不存在的键上恒返回 0，页面照样提示「已强制下线」。
 *     （ kode/jwt 的 redis 驱动在此之上还叠了一层：列表写成 LIST、读过去发 GET，
 *       WRONGTYPE 被静默吞成 false —— 见该包 v1.14.1 的 RedisStorageTokenListTest。）
 */
final class SessionRevokeTest extends TestCase
{
    protected bool $independentApp = true;

    /** 探针用户 id：不与任何真实账号冲突，也不需要真的建一行 kode_users */
    private const PROBE_UID = 987654001;
    private const PROBE_USER = 'zz-session-probe';

    /** @var list<int> 本用例插入的 kode_login_logs.id */
    private array $logIds = [];
    /** @var list<string> 本用例产生的黑名单 jti（撤销时会写，寿命 = ttl + refresh_ttl） */
    private array $blacklistedJti = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->logIds as $id) {
            Db::delete('DELETE FROM kode_login_logs WHERE id = ?', [$id]);
        }
        // 下线动作写的审计行按探针 uid 精确清除
        Db::delete(
            'DELETE FROM kode_system_logs WHERE module = ? AND message LIKE ?',
            ['session', '%uid=' . self::PROBE_UID . ' %']
        );
        foreach ($this->blacklistedJti as $jti) {
            try {
                resolve(EncryptedJwtGuard::class)->unblacklist($jti);
            } catch (\Throwable) {
            }
        }

        HttpRequest::clear();
        parent::tearDown();
    }

    /**
     * 列表必须打得开（修复前：列名不存在 → 500），且只列成功登录、只列真实字段。
     */
    public function test_index_lists_only_successful_logins(): void
    {
        $okId = $this->insertLog(1);
        $failId = $this->insertLog(0);

        $body = $this->call('GET', '/api/sessions', ['query' => ['size' => '100']]);

        self::assertSame(200, $body['status'], '会话列表端点未返回 200');
        $usernames = array_column($body['data'], 'username');
        self::assertContains(self::PROBE_USER, $usernames, '成功登录的会话应出现在列表里');

        $ids = array_column($body['data'], 'id');
        self::assertNotContains(
            \app\support\HashId::enc($failId),
            $ids,
            '登录失败记录（status=0）不是会话，不该列出来'
        );
        self::assertContains(\app\support\HashId::enc($okId), $ids);

        // 幻影列 realname 曾让整页 500，前端已去掉该列，后端也不该再造这个键
        self::assertArrayNotHasKey('realname', $body['data'][0]);
        self::assertSame(
            ['id', 'user_id', 'username', 'user_status', 'ip', 'user_agent', 'login_at', 'active', 'is_current'],
            array_keys($body['data'][0])
        );
    }

    /**
     * 关键词过滤要能按用户名/IP 命中（曾按 u.username、u.realname 过滤）。
     */
    public function test_index_keyword_filter_matches_the_probe_session(): void
    {
        $this->insertLog(1);

        $byName = $this->call('GET', '/api/sessions', ['query' => ['keyword' => 'session-probe', 'size' => '100']]);
        self::assertSame(200, $byName['status']);
        self::assertNotEmpty($byName['data'], '按用户名过滤应命中探针会话');

        $byIp = $this->call('GET', '/api/sessions', ['query' => ['keyword' => '203.0.113.', 'size' => '100']]);
        self::assertSame(200, $byIp['status']);
        self::assertNotEmpty($byIp['data'], '按 IP 过滤应命中探针会话');

        $miss = $this->call('GET', '/api/sessions', ['query' => ['keyword' => 'no-such-session-user', 'size' => '100']]);
        self::assertSame(200, $miss['status']);
        self::assertNotContains(self::PROBE_USER, array_column($miss['data'], 'username'));
    }

    /**
     * 核心：强制下线必须真的撤销，并把撤销条数回报出来。
     */
    public function test_revoke_invalidates_the_issued_token(): void
    {
        $guard = resolve(EncryptedJwtGuard::class);
        $token = $guard->issue([
            'uid' => self::PROBE_UID,
            'username' => self::PROBE_USER,
            'roles' => ['admin'],
        ], 'api');

        $jti = $guard->authenticate($token, 'api')->jti;
        $this->blacklistedJti[] = $jti;

        $logId = $this->insertLog(1);
        $body = $this->call('DELETE', '/api/sessions/' . \app\support\HashId::enc($logId), ['route' => $logId]);

        self::assertSame(200, $body['status'], '强制下线端点未返回 200：' . json_encode($body));
        self::assertGreaterThanOrEqual(1, (int) ($body['data']['revoked'] ?? 0), '撤销条数必须真实回报，不能空转回 0');

        $this->expectException(\Throwable::class);
        $guard->authenticate($token, 'api');
    }

    /**
     * 撤销走的是「外层不透明 uid」那把键：自增 id 直接传进去必然打不中。
     * 这条把映射的必要性钉住（守卫内部由 EncryptedJwtGuard::revokeUserTokens 完成映射）。
     */
    public function test_token_list_is_keyed_by_the_outer_uid_not_the_int(): void
    {
        $guard = resolve(EncryptedJwtGuard::class);
        $token = $guard->issue(['uid' => self::PROBE_UID, 'username' => self::PROBE_USER], 'api');
        $payload = $guard->authenticate($token, 'api');
        $this->blacklistedJti[] = $payload->jti;

        $platform = (string) (config('jwt.guards.api.platform') ?? 'web');
        $outer = EncryptedJwtGuard::outerUid(self::PROBE_UID);

        self::assertNotEmpty(
            KodeJwt::getUserTokens($outer, $platform),
            "签发后应能按外层 uid（{$outer}）枚举到令牌"
        );
        self::assertSame(
            [],
            KodeJwt::getUserTokens((string) self::PROBE_UID, $platform),
            '按数据库自增 id 枚举应为空（旧调用就是这么传的，撤销因此恒返回 0）'
        );
    }

    /**
     * 认不出的 uid 要报错，不能静默返回 0 让上层以为「已经没有令牌了」。
     */
    public function test_unrecognized_uid_refuses_to_revoke_silently(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        resolve(EncryptedJwtGuard::class)->revokeUserTokens('not-a-uid');
    }

    /**
     * 清理过期会话：撤销条数按令牌计、失败单独计数，且不再吞异常。
     */
    public function test_clear_all_reports_users_and_token_counts(): void
    {
        // 超过 24h 的登录行才会被清理：插一条昨天的
        $id = (int) Db::insert(
            'INSERT INTO kode_login_logs (user_id, username, ip, user_agent, device, status, fail_reason, created_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, NOW() - INTERVAL \'2 day\')',
            [self::PROBE_UID, self::PROBE_USER, '203.0.113.77', 'phpunit', 'CLI', 'probe']
        );
        self::assertGreaterThan(0, $id);
        $this->logIds[] = $id;

        $guard = resolve(EncryptedJwtGuard::class);
        $token = $guard->issue(['uid' => self::PROBE_UID, 'username' => self::PROBE_USER], 'api');
        $this->blacklistedJti[] = $guard->authenticate($token, 'api')->jti;

        $body = $this->call('POST', '/api/sessions/clear-all');
        self::assertSame(200, $body['status']);
        self::assertArrayHasKey('users', $body['data']);
        self::assertArrayHasKey('revoked', $body['data']);
        self::assertSame(0, (int) $body['data']['failed'], '清理过程不该失败：' . json_encode($body['data']));
        self::assertGreaterThanOrEqual(1, (int) $body['data']['revoked']);

        $this->expectException(\Throwable::class);
        $guard->authenticate($token, 'api');
    }

    /**
     * 失败路径不外泄内部信息：撤销时抛异常只回固定文案。
     * （用不存在的会话 id 走 404 分支，确认信封里没有异常消息。）
     */
    public function test_revoke_of_missing_session_is_404_without_internals(): void
    {
        $body = $this->call('DELETE', '/api/sessions/' . \app\support\HashId::enc(999999999), ['route' => 999999999]);

        self::assertSame(404, $body['status']);
        self::assertSame('会话不存在', $body['msg']);
        self::assertStringNotContainsString('kode_login_logs', json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    // ─── 辅助 ───────────────────────────────────────────────

    private function insertLog(int $status): int
    {
        $id = (int) Db::insert(
            'INSERT INTO kode_login_logs (user_id, username, ip, user_agent, device, status, fail_reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [self::PROBE_UID, self::PROBE_USER, '203.0.113.77', 'Mozilla/5.0 (phpunit)', 'CLI', $status, $status === 1 ? '' : 'probe']
        );
        self::assertGreaterThan(0, $id);
        $this->logIds[] = $id;

        return $id;
    }

    /**
     * @param array<string, array<string, string>> $options query 参数与路由参数
     * @return array{status: int, data?: mixed, msg?: string}
     */
    private function call(string $method, string $path, array $options = []): array
    {
        $request = new ServerRequest($method, $path);
        if (isset($options['query'])) {
            $request = $request->withQueryParams($options['query']);
        }
        if (array_key_exists('route', $options)) {
            $request = $request->withAttribute(
                '_route_params',
                ['id' => \app\support\HashId::enc((int) $options['route'])]
            );
        }
        HttpRequest::setRequest($request);

        $controller = new SessionController();
        $response = match ($method) {
            'DELETE' => $controller->revoke(),
            'POST' => $controller->clearAll(),
            default => $controller->index(),
        };
        self::assertInstanceOf(ResponseInterface::class, $response);

        $body = (array) json_decode((string) $response->getBody(), true);
        // 统一信封：code 恒等于 HTTP 状态
        $body['status'] = (int) ($body['code'] ?? $response->getStatusCode());

        return $body;
    }
}
