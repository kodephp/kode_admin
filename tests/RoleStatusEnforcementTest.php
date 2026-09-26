<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\RoleController;
use app\auth\EncryptedJwtGuard;
use app\support\RolePerms;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 角色 status 的真正落地：禁用/删除一个角色，必须当场让它的授权与成员会话一起失效。
 *
 * 修复前有两道各自的漏洞，合起来让角色页那个「禁用」开关等于没按：
 *  1. `RolePerms::forUser()` 只查 `kode_role_user`，从不看 `kode_roles.status` ——
 *     perms 与菜单身份列表照旧带上被禁用的角色；
 *  2. slug 铸在登录时签发的 JWT 载荷里，`RequireRole` 只读载荷不查库 ——
 *     即便 perms 侧改好了，禁用/删除角色也不掉任何人的 `RequireRole('xxx')` 门禁，
 *     要等令牌自然过期（默认 1 小时）。
 *
 * 顺带堵一处数据塌陷：`update()` 对 status/remark 用 array_key_exists 保留原值，
 * 唯独 perms 无条件覆盖 —— 只改名字的 PUT 会把权限点清成 []，整批成员当场掉光权限。
 */
final class RoleStatusEnforcementTest extends TestCase
{
    protected bool $independentApp = true;

    private const ROLE = 'zz-role-status-probe';

    private const PERM = 'zz:read';

    private const USER = 'zz-role-status-user';

    private ?int $uid = null;

    /** @var list<string> */
    private array $blacklistedJti = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));

        Db::delete('DELETE FROM kode_role_user WHERE role_slug = ?', [self::ROLE]);
        Db::delete('DELETE FROM kode_roles WHERE slug = ?', [self::ROLE]);
        Db::insert(
            'INSERT INTO kode_roles (name, slug, perms, status, remark, created_at, updated_at)
             VALUES (?, ?, ?, 1, ?, NOW(), NOW())',
            ['探针角色', self::ROLE, (string) json_encode([self::PERM]), '']
        );

        Db::delete('DELETE FROM kode_users WHERE name = ?', [self::USER]);
        $this->uid = (int) Db::insert(
            'INSERT INTO kode_users (name, email, password, status, created_at, updated_at)
             VALUES (?, ?, ?, 1, NOW(), NOW())',
            [self::USER, self::USER . '@example.test', password_hash('ProbePass1!', PASSWORD_DEFAULT)]
        );
        $this->grant();
    }

    protected function tearDown(): void
    {
        foreach ($this->blacklistedJti as $jti) {
            try {
                resolve(EncryptedJwtGuard::class)->unblacklist($jti);
            } catch (\Throwable) {
            }
        }
        $this->blacklistedJti = [];

        if ($this->uid !== null) {
            Db::delete('DELETE FROM kode_role_user WHERE user_id = ?', [$this->uid]);
            Db::delete('DELETE FROM kode_login_logs WHERE user_id = ?', [$this->uid]);
            Db::delete('DELETE FROM kode_users WHERE id = ?', [$this->uid]);
        }
        Db::delete('DELETE FROM kode_roles WHERE slug = ?', [self::ROLE]);
        HttpRequest::clear();

        parent::tearDown();
    }

    private function grant(string $slug = self::ROLE): void
    {
        Db::insert('INSERT INTO kode_role_user (user_id, role_slug) VALUES (?, ?)', [(int) $this->uid, $slug]);
    }

    /** @return array{roles: list<string>, perms: list<string>} */
    private function effective(): array
    {
        $out = RolePerms::forUser((int) $this->uid);

        return ['roles' => $out['roles'], 'perms' => $out['perms']];
    }

    /** 铸一把「用起来」的令牌（authenticate 才登记进活跃列表，撤销才有对象）。 */
    private function liveToken(): string
    {
        $guard = resolve(EncryptedJwtGuard::class);
        $token = $guard->issue(['uid' => (int) $this->uid, 'username' => self::USER, 'roles' => [self::ROLE]], 'api');
        $this->blacklistedJti[] = $guard->authenticate($token, 'api')->jti;

        return $token;
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
     * @param array<string, mixed> $body
     * @param array<string, mixed> $route
     *
     * @return array{status: int, msg: string, data: array<string, mixed>}
     */
    private function call(string $method, string $action, array $body = [], array $route = []): array
    {
        HttpRequest::setRequest((new ServerRequest(
            $method,
            '/api/roles/' . self::ROLE,
            [],
            ['content-type' => 'application/json'],
            (string) json_encode($body)
        ))->withAttribute('_route_params', $route + ['slug' => self::ROLE]));

        $response = (new RoleController())->{$action}();
        $decoded = (array) json_decode((string) $response->getBody(), true);

        return [
            'status' => (int) ($decoded['code'] ?? $response->getStatusCode()),
            'msg' => (string) ($decoded['msg'] ?? ''),
            'data' => (array) ($decoded['data'] ?? []),
        ];
    }

    private function setStatusInDb(int $status): void
    {
        Db::update('UPDATE kode_roles SET status = ? WHERE slug = ?', [$status, self::ROLE]);
    }

    public function test_an_enabled_role_grants_its_perms(): void
    {
        $effective = $this->effective();

        self::assertContains(self::ROLE, $effective['roles']);
        self::assertContains(self::PERM, $effective['perms']);
    }

    /** 核心一：读取侧认 status，禁用不再只是个显示字段。 */
    public function test_a_disabled_role_grants_nothing(): void
    {
        $this->setStatusInDb(0);

        $effective = $this->effective();

        self::assertNotContains(self::ROLE, $effective['roles'], '禁用角色的 slug 仍在身份列表里：菜单过滤照旧放行');
        self::assertNotContains(self::PERM, $effective['perms'], '禁用角色的权限点仍在并集里：RequirePerm 照旧放行');
    }

    /** 反例护栏：重新启用不该把人踢下线，但要重新给回权限。 */
    public function test_re_enabling_a_role_gives_the_grants_back(): void
    {
        $this->setStatusInDb(0);
        self::assertNotContains(self::PERM, $this->effective()['perms']);

        $out = $this->call('PUT', 'update', ['name' => '探针角色', 'status' => 1]);

        self::assertSame(200, $out['status'], $out['msg']);
        self::assertSame(0, (int) ($out['data']['revoked_sessions'] ?? -1), '放宽不该撤销任何会话');
        self::assertContains(self::PERM, $this->effective()['perms']);
    }

    /** 指向已删除角色的授权记录是死记录：不该继续出现在身份列表里。 */
    public function test_a_grant_pointing_at_a_missing_role_grants_nothing(): void
    {
        $this->grant('zz-role-that-was-deleted');

        self::assertNotContains('zz-role-that-was-deleted', $this->effective()['roles']);
    }

    /** 核心二：通过接口禁用角色 = 成员当场下线（slug 还铸在他们的令牌里）。 */
    public function test_disabling_through_the_api_kicks_members_offline(): void
    {
        $token = $this->liveToken();

        $out = $this->call('PUT', 'update', ['name' => '探针角色', 'status' => 0]);

        self::assertSame(200, $out['status'], $out['msg']);
        self::assertGreaterThanOrEqual(1, (int) ($out['data']['revoked_sessions'] ?? 0), '响应要回报撤销了几个人');
        $this->assertDead($token, '禁用角色后成员令牌仍可用：RequireRole 会一直放行到令牌过期');
        self::assertSame(0, (int) Db::select('SELECT status FROM kode_roles WHERE slug = ?', [self::ROLE])[0]['status']);
    }

    /**
     * 列表页那个状态开关的真实载荷：只有 `{status}`，不带 name。
     *
     * 早先 `name` 是 required，这个请求连门禁都过不去（422），
     * 所以「禁用角色」在界面上按一次弹一次错 —— status 被无视只是它的下一层。
     */
    public function test_the_status_switch_payload_from_the_list_page_is_accepted(): void
    {
        $token = $this->liveToken();

        $out = $this->call('PUT', 'update', ['status' => 0]);

        self::assertSame(200, $out['status'], '只带 status 的 PUT 必须可用：' . $out['msg']);
        $row = Db::select('SELECT name, perms, status FROM kode_roles WHERE slug = ?', [self::ROLE])[0];
        self::assertSame('探针角色', (string) $row['name'], '没发 name 就该保留原名');
        self::assertStringContainsString(self::PERM, (string) $row['perms'], '没发 perms 就该保留权限点');
        self::assertSame(0, (int) $row['status']);
        self::assertGreaterThanOrEqual(1, (int) ($out['data']['revoked_sessions'] ?? 0));
        $this->assertDead($token, '界面那条禁用链路仍要下线成员');
    }

    /**
     * 数据塌陷护栏：只改名字时 perms 必须原样保留。
     *
     * 修复前 `perms` 无条件取 `$data['perms'] ?? []`，缺键即写 '[]'，
     * 一次改名的 PUT 就把整个角色的权限点清空。
     */
    public function test_renaming_without_perms_keeps_the_permission_points(): void
    {
        $token = $this->liveToken();

        $out = $this->call('PUT', 'update', ['name' => '探针角色改名']);

        self::assertSame(200, $out['status'], $out['msg']);
        self::assertSame(
            [self::PERM],
            (array) (json_decode((string) Db::select('SELECT perms FROM kode_roles WHERE slug = ?', [self::ROLE])[0]['perms'], true) ?: []),
            '没带 perms 的改名请求把权限点清空了'
        );
        self::assertContains(self::PERM, $this->effective()['perms']);
        self::assertSame(0, (int) ($out['data']['revoked_sessions'] ?? 0), '没禁用就不该撤销会话');
        resolve(EncryptedJwtGuard::class)->authenticate($token, 'api');
    }

    /** 显式清空权限仍然要能清（array_key_exists 判断不能把合法清空也挡掉）。 */
    public function test_sending_an_empty_perms_array_clears_them(): void
    {
        $out = $this->call('PUT', 'update', ['name' => '探针角色', 'perms' => []]);

        self::assertSame(200, $out['status'], $out['msg']);
        self::assertNotContains(self::PERM, $this->effective()['perms']);
    }

    /** 核心三：删除角色同样要下线，否则令牌里的 slug 还是个能用的身份。 */
    public function test_destroying_a_role_kicks_its_members_offline(): void
    {
        $token = $this->liveToken();

        $out = $this->call('DELETE', 'destroy');

        self::assertSame(200, $out['status'], $out['msg']);
        self::assertGreaterThanOrEqual(1, (int) ($out['data']['revoked_sessions'] ?? 0));
        $this->assertDead($token, '删除角色后成员令牌仍可用');
        self::assertSame([], Db::select('SELECT id FROM kode_roles WHERE slug = ?', [self::ROLE]));
        self::assertSame([], Db::select('SELECT id FROM kode_role_user WHERE role_slug = ?', [self::ROLE]));
    }

    /** 挂着孤儿授权（用户早被删）时，禁用角色不该 500，人数按实际可撤销者计。 */
    public function test_orphan_grants_do_not_break_the_endpoint(): void
    {
        Db::insert('INSERT INTO kode_role_user (user_id, role_slug) VALUES (?, ?)', [999999999, self::ROLE]);

        $out = $this->call('PUT', 'update', ['name' => '探针角色', 'status' => 0]);

        self::assertSame(200, $out['status'], '有死授权时接口不该整条失败：' . $out['msg']);
        self::assertGreaterThanOrEqual(1, (int) ($out['data']['revoked_sessions'] ?? 0));

        Db::delete('DELETE FROM kode_role_user WHERE user_id = ?', [999999999]);
    }
}
