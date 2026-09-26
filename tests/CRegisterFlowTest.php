<?php

declare(strict_types=1);

namespace app\tests;

use app\api\controllers\RegisterController;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Framework\Validation\ValidationException;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use app\support\HashId;
use Psr\Http\Message\ResponseInterface;

/**
 * C 端自助注册（`RegisterController::register()`）的行为回归。
 *
 * 修复前这条匿名写接口直接复用后台 `UserController::store()`：收参面跟着后台长
 * （`dept_id` 让外部把人塞进指定部门），且没有任何计数 —— 限流中间件默认关
 * （`LIMITING_ENABLED=false`），而 `user.created` 会被钱包插件消费，等于一个零成本的建号入口。
 *
 * 静态口径见 `ApiWriteGateTest`（那条读源码，所以环境开关遮不住它）；本类测行为：
 * 建出来的是「无角色、无部门、可登录」的裸账号，邮箱大小写不再绕开唯一索引，
 * 以及预算按 IP+邮箱两条线计**尝试**（成功也算一次）。
 */
final class CRegisterFlowTest extends TestCase
{
    protected bool $independentApp = true;

    /** 探针邮箱前缀：tearDown 精确按此清理。 */
    private const PROBE = 'zz-c-register-probe';

    /** 本类用过的邮箱标签（setUp 也要按它们清预算，见 clearRegisterBudget）。 */
    private const TAGS = ['a', 'b', 'c', 'd'];

    private const PASSWORD = 'RegressionPass123!';

    /** @var list<int> 本用例建出来的 uid */
    private array $uids = [];

    /** @var list<string> 本用例用过的邮箱（计数键 + 残留行都按它清） */
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        // 上一次崩在中途留下的预算会把本类全部用例锁成 429（看起来像「门禁生效」）。
        $this->clearRegisterBudget();
    }

    protected function tearDown(): void
    {
        foreach ($this->uids as $uid) {
            Db::delete('DELETE FROM kode_role_user WHERE user_id = ?', [$uid]);
            $this->deleteWalletRows((int) $uid);
            Db::delete('DELETE FROM kode_users WHERE id = ?', [$uid]);
        }
        $this->uids = [];
        foreach ($this->emails as $email) {
            Db::delete('DELETE FROM kode_users WHERE email = ?', [$email]);
        }
        $this->clearRegisterBudget();
        $this->emails = [];

        HttpRequest::clear();

        parent::tearDown();
    }

    public function test_it_creates_a_bare_account_and_returns_an_opaque_id(): void
    {
        $email = $this->email('a');
        $result = $this->register([
            'name' => self::PROBE . '-a',
            // 故意带大写：归一化必须在唯一性检查之前，见下一条用例。
            'email' => strtoupper(substr($email, 0, 4)) . substr($email, 4),
            'password' => self::PASSWORD,
        ]);

        self::assertSame(201, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $opaque = (string) ($result['body']['data']['id'] ?? '');
        self::assertNotSame('', $opaque, '出参没有 id');

        $uid = HashId::dec($opaque);
        self::assertIsInt($uid, '对外 id 解不回整数：' . $opaque);
        // 不是「解不出来」而是「解出来不是同一个串」：AGENTS §4 要求对外防枚举。
        self::assertNotSame((string) $uid, $opaque, '对外 id 就是库内自增主键');
        $this->uids[] = (int) $uid;

        $row = Db::select(
            'SELECT name, email, password, status, dept_id, remark FROM kode_users WHERE id = ?',
            [(int) $uid]
        )[0] ?? [];
        self::assertSame($email, (string) ($row['email'] ?? ''), '邮箱没有小写归一：' . (string) ($row['email'] ?? ''));
        self::assertSame(1, (int) ($row['status'] ?? 0), '注册建出禁用账号 = 当事人拿到一个登不进去的号');
        self::assertSame(0, (int) ($row['dept_id'] ?? -1), '匿名注册不该落进任何部门');
        self::assertSame('', (string) ($row['remark'] ?? '-'), 'remark 只能由后台写');
        self::assertTrue(password_verify(self::PASSWORD, (string) ($row['password'] ?? '')), '密码不是 password_hash 的产物');
        self::assertStringNotContainsString(self::PASSWORD, json_encode($result['body'], JSON_UNESCAPED_UNICODE));

        // 无角色：匿名建号若带上任何 role_slug，RequireRole 立刻认它。
        self::assertSame([], Db::select('SELECT role_slug FROM kode_role_user WHERE user_id = ?', [(int) $uid]));
    }

    public function test_mixed_case_email_does_not_open_a_second_account(): void
    {
        $base = $this->email('b');
        $mixed = strtoupper(substr($base, 0, 2)) . substr($base, 2);
        $first = $this->register(['name' => self::PROBE . '-b1', 'email' => $mixed, 'password' => self::PASSWORD]);
        self::assertSame(201, $first['status'], json_encode($first['body'], JSON_UNESCAPED_UNICODE));
        $this->trackId($first);

        $second = $this->register(['name' => self::PROBE . '-b2', 'email' => $base, 'password' => self::PASSWORD]);
        self::assertSame(409, $second['status'], '大写邮箱绕开了唯一性检查，建出第二个账号');

        $rows = Db::select('SELECT id FROM kode_users WHERE lower(email) = ?', [$base]);
        self::assertCount(1, $rows, '同一邮箱存在多行：' . json_encode($rows));
        $this->uids[] = (int) ($rows[0]['id'] ?? 0);
    }

    public function test_validation_rejects_before_touching_the_table(): void
    {
        $email = $this->email('c');
        $before = self::userCount();

        try {
            $this->register(['name' => self::PROBE . '-c', 'email' => $email, 'password' => 'abc']);
            self::fail('弱口令没有被校验拦下');
        } catch (ValidationException) {
            // 由 TimingMiddleware 转 422 信封；单测里直接抛，说明校验确实先于建号。
        }

        self::assertSame($before, self::userCount(), '校验失败前已经写了库');
        self::assertSame([], Db::select('SELECT id FROM kode_users WHERE email = ?', [$email]));
    }

    public function test_attempt_budget_locks_the_endpoint_after_ten_tries(): void
    {
        $email = $this->email('d');
        // 预先占掉这个邮箱：每次注册都撞 409，既计一次尝试又不真的建号。
        Db::insert(
            'INSERT INTO kode_users (name, email, password, status, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())',
            [self::PROBE . '-d', $email, password_hash(self::PASSWORD, PASSWORD_DEFAULT)]
        );
        $this->emails[] = $email;

        $limit = (new \ReflectionClass(RegisterController::class))->getConstant('MAX_ATTEMPTS');
        self::assertIsInt($limit, 'AttemptGuard 的预算常量不见了，本用例失去对象');

        for ($i = 1; $i <= $limit; $i++) {
            $result = $this->register(['name' => self::PROBE . '-d', 'email' => $email, 'password' => self::PASSWORD]);
            self::assertSame(409, $result['status'], "第 {$i} 次就该是 409，实际：{$result['status']}");
        }

        $locked = $this->register(['name' => self::PROBE . '-d', 'email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(429, $locked['status'], '预算用尽后仍可继续建号尝试：' . json_encode($locked['body'], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('频繁', (string) ($locked['body']['msg'] ?? ''));
    }

    /* ==================== 工具 ==================== */

    /** @param array<string, string> $payload @return array{status: int, body: array<string, mixed>} */
    private function register(array $payload): array
    {
        HttpRequest::setRequest(
            (new ServerRequest('POST', '/c-api/auth/register'))->withParsedBody($payload)
        );
        $response = (new RegisterController())->register();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }

    /** @param array{status: int, body: array<string, mixed>} $result */
    private function trackId(array $result): void
    {
        $uid = HashId::dec((string) ($result['body']['data']['id'] ?? ''));
        if ($uid !== null) {
            $this->uids[] = (int) $uid;
        }
    }

    private function email(string $tag): string
    {
        $email = self::PROBE . '-' . strtolower($tag) . '@example.test';
        $this->emails[] = $email;

        return $email;
    }

    /** 两条计数键都要归零：只按 IP 会被 NAT 绕过，只按身份则别人能替全员锁死（见 AttemptGuard）。
     *  身份键按**标签全集**清，不看 $this->emails —— 上一次崩在中途时，预算正是留着的那一份。 */
    private function clearRegisterBudget(): void
    {
        $ip = (string) (HttpRequest::ip((array) config('security.trusted_proxies', [])) ?? 'unknown');
        cache()->delete("register_ip:{$ip}");
        foreach (self::TAGS as $tag) {
            cache()->delete('register_user:' . self::PROBE . '-' . $tag . '@example.test');
        }
    }

    private static function userCount(): int
    {
        return (int) (Db::select('SELECT COUNT(*) AS c FROM kode_users')[0]['c'] ?? 0);
    }

    /**
     * 注册会派发 `user.created`，钱包插件据此开一行账户 —— 插件是可选的，
     * 表在才清，否则本类在未装钱包的库上直接报错。
     */
    private function deleteWalletRows(int $uid): void
    {
        $present = Db::select(
            "SELECT 1 FROM information_schema.tables WHERE table_name = 'kode_wallets'"
        ) !== [];
        if ($present) {
            Db::delete('DELETE FROM kode_wallets WHERE member_id = ?', [$uid]);
        }
    }
}
