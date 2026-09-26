<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\AuthController;
use app\auth\EncryptedJwtGuard;
use app\services\UserService;
use app\support\SysConfig;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 「处置动作即时生效」回归：禁用 / 降权 / 重置密码 / 删除账号，都必须让在发令牌当场失效。
 *
 * 根因是一条安静的分层缝隙：`AuthMiddleware` 只验签、不回查数据库（kode/framework 的通用
 * 中间件，本就不该懂 kode_users.status），而 `RequireRole` 读的是**登录时铸造的** JWT 载荷。
 * 于是库里改了 status / kode_role_user，旧令牌照样带着 admin 一路跑到 exp（默认 3600s）——
 * 管理端提示「已禁用」「已降权」，被处置的人毫无感知。最危险的正是「越权账号被收回权限」
 * 与「离职账号被禁用」这两类操作。
 *
 * 修法在写侧：改动落库前撤销该用户全部在发令牌；读侧不加每请求查库（热路径红线）。
 * 同一条链路上 `AuthController::password()` 原本连失败计数都没有 —— 原密码字段就是一个
 * 无限次的口令 oracle，一并测。
 */
final class SessionInvalidationTest extends TestCase
{
    protected bool $independentApp = true;

    /** 探针账号名/邮箱前缀：不与真实账号冲突，tearDown 精确按此清理 */
    private const PROBE = 'zz-sess-inval-probe';

    private ?int $uid = null;

    /** @var list<string> 本用例产生的黑名单 jti */
    private array $blacklistedJti = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        if ($this->uid !== null) {
            $this->clearPasswordCounters($this->uid);
            Db::delete('DELETE FROM kode_role_user WHERE user_id = ?', [$this->uid]);
            Db::delete('DELETE FROM kode_login_logs WHERE user_id = ?', [$this->uid]);
            Db::delete('DELETE FROM kode_users WHERE id = ?', [$this->uid]);
            $this->uid = null;
        }
        foreach ($this->blacklistedJti as $jti) {
            try {
                resolve(EncryptedJwtGuard::class)->unblacklist($jti);
            } catch (\Throwable) {
            }
        }
        $this->blacklistedJti = [];
        HttpRequest::clear();

        parent::tearDown();
    }

    /** 改密计数按 uid + IP 记账；两个键都要归零，否则用例之间互相锁死 */
    private function clearPasswordCounters(int $uid): void
    {
        cache()->delete("pwd_fail_user:{$uid}");
        cache()->delete('pwd_fail_ip:' . self::ip());
    }

    private static function ip(): string
    {
        return (string) (HttpRequest::ip((array) config('security.trusted_proxies', [])) ?? 'unknown');
    }

    private function seedUser(int $status = 1): int
    {
        // 上次崩在中途留下的行会让唯一索引直接报错，先按探针名清一次
        Db::delete('DELETE FROM kode_users WHERE name = ?', [self::PROBE]);
        $this->uid = (int) Db::insert(
            'INSERT INTO kode_users (name, email, password, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())',
            [
                self::PROBE,
                self::PROBE . '@example.test',
                password_hash(self::OLD_PASSWORD, PASSWORD_DEFAULT),
                $status,
            ]
        );

        return $this->uid;
    }

    private const OLD_PASSWORD = 'ProbePass1!';

    /**
     * 铸一把「用起来」的令牌：authenticate() 才把它登记进用户活跃列表，
     * 这正是真实请求的路径（只 issue 不使用，撤销无从谈起）。
     */
    private function liveToken(int $uid): string
    {
        $guard = resolve(EncryptedJwtGuard::class);
        $token = $guard->issue(['uid' => $uid, 'username' => self::PROBE, 'roles' => ['admin']], 'api');
        $this->blacklistedJti[] = $guard->authenticate($token, 'api')->jti;

        return $token;
    }

    private function assertAlive(string $token): void
    {
        resolve(EncryptedJwtGuard::class)->authenticate($token, 'api');
        self::assertTrue(true);
    }

    private function assertDead(string $token, string $why): void
    {
        try {
            resolve(EncryptedJwtGuard::class)->authenticate($token, 'api');
        } catch (\Throwable) {
            return;
        }
        self::fail($why);
    }

    /**
     * 四个写侧入口逐个点名：漏掉任何一个，对应的处置动作都只是「改了库、没生效」。
     *
     * @param callable(UserService, int): mixed $action
     */
    #[DataProvider('disposalActions')]
    public function test_a_disposal_action_kills_the_live_token(callable $action, string $label): void
    {
        $uid = $this->seedUser();
        $token = $this->liveToken($uid);
        $this->assertAlive($token);

        $action(resolve(UserService::class), $uid);

        $this->assertDead($token, "{$label} 后旧令牌仍可通过鉴权：处置被 JWT 的有效期吞掉了");
    }

    /**
     * @return array<string, array{0: callable(UserService, int): mixed, 1: string}>
     */
    public static function disposalActions(): array
    {
        return [
            '禁用' => [static fn(UserService $s, int $id): mixed => $s->toggleStatus($id), '禁用账号'],
            '降权' => [static fn(UserService $s, int $id): mixed => $s->assignRoles($id, ['guanli']), '收回角色'],
            '重置密码' => [static fn(UserService $s, int $id): mixed => $s->resetPassword($id, 'Rotated1pass!'), '重置密码'],
            '删除' => [static fn(UserService $s, int $id): mixed => $s->remove($id), '删除账号'],
        ];
    }

    /** 反例护栏：重新启用是「放宽」，不该顺手把人踢下线。 */
    public function test_re_enabling_a_user_does_not_revoke_sessions(): void
    {
        $uid = $this->seedUser(0);
        $token = $this->liveToken($uid);

        resolve(UserService::class)->toggleStatus($uid);

        $this->assertAlive($token);
        self::assertSame(1, (int) Db::select('SELECT status FROM kode_users WHERE id = ?', [$uid])[0]['status']);
    }

    /**
     * 改密入口的失败计数：错到上限必须 429，且额度独立于登录（不连带把登录锁死）。
     */
    public function test_password_endpoint_throttles_wrong_old_password(): void
    {
        $uid = $this->seedUser();
        // 上限取实际生效值（可被 SysConfig 覆盖），不抄常量表
        $max = SysConfig::getInt(
            'auth.login_max_attempts',
            (int) (new \ReflectionClass(AuthController::class))->getConstant('MAX_ATTEMPTS'),
            3,
            100
        );

        $codes = [];
        for ($i = 0; $i <= $max; $i++) {
            $codes[] = $this->changePassword($uid, 'WrongOldPwd1!', 'FreshPass1!')['status'];
            if (in_array(429, $codes, true)) {
                break;
            }
        }

        self::assertSame(400, $codes[0], '首个错误应当是「原密码错误」，而不是直接拒绝');
        self::assertContains(429, $codes, '原密码可以被无限次尝试：登录有次数预算，这条线必须一样');
        self::assertLessThanOrEqual($max + 1, count($codes), '触发限流不应晚于配置的次数上限');
        self::assertSame(
            0,
            (int) cache()->get('login_fail_user:' . self::PROBE, 0),
            '改密的失败计数不该写进登录的键：否则试错密码会顺带锁死对方登录'
        );
    }

    /** 改密成功后连带作废当前这把令牌（换密的典型动机正是「号被人用了」）。 */
    public function test_changing_password_revokes_the_current_token(): void
    {
        $uid = $this->seedUser();
        $token = $this->liveToken($uid);

        $out = $this->changePassword($uid, self::OLD_PASSWORD, 'FreshPass1!');

        self::assertSame(200, $out['status'], $out['msg']);
        self::assertGreaterThanOrEqual(1, (int) $out['data']['revoked_tokens']);
        self::assertTrue((bool) ($out['data']['relogin'] ?? false), '响应要告诉客户端需要重新登录');
        $this->assertDead($token, '换密后旧令牌还在用：被接管的会话没随密码一起失效');
    }

    /**
     * @return array{status: int, msg: string, data: array<string, mixed>}
     */
    private function changePassword(int $uid, string $old, string $new): array
    {
        HttpRequest::setRequest((new ServerRequest(
            'POST',
            '/api/auth/password',
            [],
            ['content-type' => 'application/json'],
            (string) json_encode(['old_password' => $old, 'new_password' => $new])
        ))->withAttribute('auth', (object) ['uid' => $uid, 'username' => self::PROBE]));

        $response = (new AuthController())->password();
        $body = (array) json_decode((string) $response->getBody(), true);

        return [
            'status' => (int) ($body['code'] ?? $response->getStatusCode()),
            'msg' => (string) ($body['msg'] ?? ''),
            'data' => (array) ($body['data'] ?? []),
        ];
    }
}
