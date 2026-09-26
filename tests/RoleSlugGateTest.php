<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\UserController;
use app\services\UserService;
use app\support\HashId;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 角色 slug 门禁：拼错的角色名不该被当成「授权成功」。
 *
 * 修复前 `assignRoles()` 把请求里的字符串原样写入 `kode_role_user` 并回显为已授予 ——
 * `adminn` 这种笔误会造出一条永远匹配不上的授权记录：界面上显示「角色: adminn」，
 * `RequireRole('admin')` 却不认，当事人一头雾水，管理员以为已经给过权限。
 * 批量接口更糟：每人抛一次异常、被循环里的 catch 吞掉，`updated: 0` 照样回 200。
 *
 * 现在写库前按 `kode_roles.slug` 白名单校验，整批先校验（不做半批），失败回 400 并列出不存在的名字。
 */
final class RoleSlugGateTest extends TestCase
{
    protected bool $independentApp = true;

    private const PROBE = 'zz-role-gate-probe';

    /** @var list<int> */
    private array $uids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->uids as $uid) {
            Db::delete('DELETE FROM kode_role_user WHERE user_id = ?', [$uid]);
            Db::delete('DELETE FROM kode_users WHERE id = ?', [$uid]);
        }
        $this->uids = [];
        HttpRequest::clear();

        parent::tearDown();
    }

    private function seedUser(string $suffix = ''): int
    {
        $name = self::PROBE . $suffix;
        Db::delete('DELETE FROM kode_users WHERE name = ?', [$name]);
        Db::delete('DELETE FROM kode_role_user WHERE user_id IN (SELECT id FROM kode_users WHERE name = ?)', [$name]);
        $uid = (int) Db::insert(
            'INSERT INTO kode_users (name, email, password, status, created_at, updated_at)
             VALUES (?, ?, ?, 1, NOW(), NOW())',
            [$name, $name . '@example.test', password_hash('ProbePass1!', PASSWORD_DEFAULT)]
        );
        $this->uids[] = $uid;

        return $uid;
    }

    /**
     * @return list<string>
     */
    private function rolesOf(int $uid): array
    {
        $slugs = array_column(
            Db::select('SELECT role_slug FROM kode_role_user WHERE user_id = ? ORDER BY role_slug', [$uid]),
            'role_slug'
        );
        sort($slugs);

        return array_values($slugs);
    }

    /**
     * @return list<string>
     */
    private function knownSlugs(int $need = 2): array
    {
        $slugs = array_column(Db::select('SELECT slug FROM kode_roles ORDER BY slug', []), 'slug');
        self::assertNotEmpty($slugs, 'kode_roles 为空说明门禁无从校验，测试环境不对');
        self::assertGreaterThanOrEqual($need, count($slugs), '需要至少 ' . $need . ' 个内置角色才能覆盖多角色用例');

        return array_slice(array_map('strval', $slugs), 0, $need);
    }

    public function test_an_unknown_slug_is_rejected_and_changes_nothing(): void
    {
        [$real] = $this->knownSlugs(1);
        $uid = $this->seedUser();
        resolve(UserService::class)->assignRoles($uid, [$real]);

        try {
            resolve(UserService::class)->assignRoles($uid, [$real, 'adminn']);
            self::fail('拼错的角色 slug 被接受了：它会被写进 kode_role_user 并回显为「已授权」');
        } catch (\RuntimeException $e) {
            self::assertSame(400, $e->getCode(), '业务校验失败应当回 400，而不是裸 500');
            self::assertStringContainsString('adminn', $e->getMessage(), '报错要点名不存在的角色');
        }

        self::assertSame([$real], $this->rolesOf($uid), '校验失败发生在写库前：原有授权不该被清掉');
    }

    public function test_known_slugs_are_granted_and_normalised(): void
    {
        [$first, $second] = $this->knownSlugs(2);
        $uid = $this->seedUser();

        $granted = resolve(UserService::class)->assignRoles($uid, [" {$first} ", $first, $second]);

        self::assertSame([$first, $second], $this->rolesOf($uid), 'slug 要清洗（去空白、去重）后落库');
        sort($granted);
        self::assertSame([$first, $second], $granted);
    }

    /** 清空角色是合法操作：空数组不能被白名单误判成「全部不存在」。 */
    public function test_empty_role_list_still_clears_grants(): void
    {
        [$real] = $this->knownSlugs(1);
        $uid = $this->seedUser();
        resolve(UserService::class)->assignRoles($uid, [$real]);

        self::assertSame([], resolve(UserService::class)->assignRoles($uid, []));
        self::assertSame([], $this->rolesOf($uid));
    }

    /**
     * 批量：整批先校验。放在循环里的话每人抛一次、被 catch 吞掉，
     * 接口回 `updated: 0` + 200，看起来像「没人被改」而不是「请求本身非法」。
     */
    public function test_batch_rejects_the_whole_request_before_touching_any_user(): void
    {
        [$real] = $this->knownSlugs(1);
        $a = $this->seedUser('a');
        $b = $this->seedUser('b');
        resolve(UserService::class)->assignRoles($a, [$real]);
        resolve(UserService::class)->assignRoles($b, [$real]);

        try {
            resolve(UserService::class)->batchAssignRoles([$a, $b], ['ghost-role']);
            self::fail('批量接口接受了不存在的角色');
        } catch (\RuntimeException $e) {
            self::assertSame(400, $e->getCode());
        }

        self::assertSame([$real], $this->rolesOf($a));
        self::assertSame([$real], $this->rolesOf($b));
    }

    /** 控制器映射：不存在的角色走 400 信封，不该冒到框架兜底变 500。 */
    public function test_controller_answers_400_instead_of_500(): void
    {
        $uid = $this->seedUser();
        HttpRequest::setRequest(new ServerRequest(
            'POST',
            '/api/users/batch-roles',
            [],
            ['content-type' => 'application/json'],
            (string) json_encode(['ids' => [HashId::enc($uid)], 'roles' => ['no-such-role']])
        ));

        $response = (new UserController())->batchAssignRoles();
        $body = (array) json_decode((string) $response->getBody(), true);

        self::assertSame(400, (int) ($body['code'] ?? 0), '批量指派非法角色应回 400 信封：' . (string) ($body['msg'] ?? ''));
        self::assertStringContainsString('no-such-role', (string) ($body['msg'] ?? ''));
        self::assertSame([], $this->rolesOf($uid));
    }
}
