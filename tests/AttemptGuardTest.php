<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\AuthController;
use app\api\controllers\RegisterController;
use app\support\SysConfig;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * `AttemptGuard` 的接线回归（登录/改密/C 端注册共用这一份判据）。
 *
 * 这条线原先是 `AuthController` 的三个私有方法，抽成 trait 之后「抽错了」的失败模式很安静：
 * 前缀串写歪、只按 IP 计（NAT 后等于没有）、或者把注册并入登录的同一个预算
 * —— 三者都不会让任何现有用例变红，只会让某个入口悄悄回到无限次。
 * 所以这里测的是**键的形状**：往「trait 约定会读的那条键」上灌满，看入口是否真的挡人。
 *
 * 只灌**身份键**，不灌满 IP 键：本机所有调试请求同属 127.0.0.1，
 * 把 `login_fail_ip:127.0.0.1` 顶到上限会把当事人自己的后台登录锁 10 分钟。
 */
final class AttemptGuardTest extends TestCase
{
    protected bool $independentApp = true;

    /** 不存在的账号名：永远进不到「密码比对成功」的分支，也不碰真实账号的预算。 */
    private const PROBE = 'zz-attempt-guard-probe';

    /** @var list<string> 本用例写过的计数键（tearDown 精确删，漏删就是给别人留锁） */
    private array $keys = [];

    /** 注册用例占下的邮箱（tearDown 按它删行 + 清键）。 */
    private string $probeEmail = '';

    private string $ip = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        $this->ip = (string) (HttpRequest::ip((array) config('security.trusted_proxies', [])) ?? 'unknown');
        // 开局清一遍：上一条用例（或上一次崩在中途）留下的计数会让本类的「正好 1 次」断言失真。
        $this->clearKnownKeys();
    }

    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            cache()->delete($key);
        }
        $this->keys = [];
        $this->clearKnownKeys();
        if ($this->probeEmail !== '') {
            Db::delete('DELETE FROM kode_users WHERE email = ?', [$this->probeEmail]);
            $this->probeEmail = '';
        }
        // 401 分支会写一条失败登录日志（user_id=0），按探针名清掉自己那几行。
        Db::delete('DELETE FROM kode_login_logs WHERE username = ?', [self::PROBE]);

        HttpRequest::clear();

        parent::tearDown();
    }

    /**
     * 本类会碰到的全部键。失败分支自己 +1 的那些不在 $this->keys 里（没经我们手写），
     * 所以必须按名字清单删干净 —— 漏一条就是给下一个用例留一份「已经用过 1 次」的预算。
     * 身份清单也写全：注册用邮箱、登录用账号名，两个都可能是键的一部分。
     *
     * @return list<string>
     */
    private function knownKeys(): array
    {
        $identities = [self::PROBE, self::PROBE . '@example.test'];
        $out = ['login_fail_ip:' . $this->ip, 'register_ip:' . $this->ip];
        foreach ($identities as $identity) {
            $out[] = 'login_fail_user:' . $identity;
            $out[] = 'pwd_fail_user:' . $identity;
            $out[] = 'register_user:' . $identity;
        }

        return $out;
    }

    private function clearKnownKeys(): void
    {
        foreach ($this->knownKeys() as $key) {
            cache()->delete($key);
        }
    }

    public function test_login_is_throttled_by_the_identity_key_alone(): void
    {
        $limit = SysConfig::getInt('auth.login_max_attempts', 10, 3, 100);
        // 键名形状与 AttemptGuard::attemptKeys 一致；它改了这里就必须跟着红，
        // 否则「按账号锁」这条线会在没人察觉时消失。
        $this->seed("login_fail_user:" . self::PROBE, $limit);

        $result = $this->login(self::PROBE);
        self::assertSame(429, $result['status'], '身份键到预算没有挡下登录：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('频繁', (string) ($result['body']['msg'] ?? ''));
    }

    public function test_the_register_budget_does_not_lock_the_admin_login(): void
    {
        // 反对照：注册入口自己把预算打满，不该把后台登录一起锁死（前缀混用 = 一个脚本能打停管理端）。
        // 必须**用真入口**把预算花掉：直接往 register_ 键上灌满的话，注册一旦改成读别的键，
        // 这条断言就变成「什么都没锁 → 登录当然通」的空对空。
        $email = self::PROBE . '@example.test';
        $this->probeEmail = $email;
        // 名字另起一个：登录探针用的是 PROBE，这里不能让它查得到账号，否则 401 走的是另一条分支。
        Db::insert(
            'INSERT INTO kode_users (name, email, password, status, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())',
            [self::PROBE . '-taken', $email, password_hash('ProbePass123!', PASSWORD_DEFAULT)]
        );

        $limit = SysConfig::getInt('auth.login_max_attempts', 10, 3, 100);
        for ($i = 1; $i <= $limit; $i++) {
            self::assertSame(409, $this->register($email)['status'], "注册第 {$i} 次应当仍是 409");
        }
        // 先证明注册预算真的用满了，否则下面的「登录没被锁」没有任何含义。
        self::assertSame(429, $this->register($email)['status'], '注册预算没被打满，本用例的隔离断言是空的');

        $result = $this->login(self::PROBE);
        self::assertSame(401, $result['status'], '注册的预算串到了登录：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE));
    }

    public function test_a_login_failure_consumes_both_keys(): void
    {
        // 只按身份计数会被「同一账号换 IP」绕过，只按 IP 计数则攻击者能替全员锁号 ——
        // 两条键都得在失败时落到，这条断言才挡得住有人把它改成一条。
        $this->login(self::PROBE);

        self::assertSame(1, $this->attempts('login_fail_ip:' . $this->ip));
        self::assertSame(1, $this->attempts('login_fail_user:' . self::PROBE));
    }

    /* ==================== 工具 ==================== */

    /** @return array{status: int, body: array<string, mixed>} */
    private function register(string $email): array
    {
        HttpRequest::setRequest(
            (new ServerRequest('POST', '/c-api/auth/register'))->withParsedBody([
                'name' => self::PROBE,
                'email' => $email,
                'password' => 'ProbePass123!',
            ])
        );
        $response = (new RegisterController())->register();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function login(string $username): array
    {
        HttpRequest::setRequest(
            (new ServerRequest('POST', '/api/auth/login'))->withParsedBody([
                'username' => $username,
                'password' => 'not-the-real-one',
            ])
        );
        $response = (new AuthController())->login();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }

    private function seed(string $key, int $value): void
    {
        $this->keys[] = $key;
        cache()->set($key, $value, 120);
    }

    private function attempts(string $key): int
    {
        return (int) cache()->get($key, 0);
    }
}
