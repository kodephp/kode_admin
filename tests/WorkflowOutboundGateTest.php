<?php

declare(strict_types=1);

namespace app\tests;

use app\support\RemoteFetch;
use app\tests\Support\LocalFetchServer;
use Kode\Framework\Testing\TestCase;
use Kode\Plugins\frontend\WorkflowBridgeController;

/**
 * 服务端出网闸门对「自己拿 curl」的调用方的接线回归。
 *
 * `RemoteFetch::toFile()` 早就逐跳过闸了，但工作流里的 webhook 与 api_call 节点是
 * 另起炉灶的 `curl_init($用户URL)`，三件事各自漏过（详见 RemoteFetch::safeOpts 注释）：
 *  1. 协议没限制 —— 实测 `file:///etc/passwd` 配 `RETURNTRANSFER` 会把文件内容读进响应体，
 *     而 api_call 节点的响应正是回给调用方的（`.env` 就这么被带走）；
 *  2. `CURLOPT_NOPROXY` 被当成内网防线 —— 它只决定「要不要经代理」，直连 169.254.169.254 照样通；
 *  3. 告警 webhook 那处把 `SSL_VERIFYPEER` 关了（中间人可改告警内容）。
 *
 * 本测试锁两层：闸门选项本身正确；三个调用点真的把它用上了（含「变量替换之后才过闸」）。
 */
final class WorkflowOutboundGateTest extends TestCase
{
    private ?LocalFetchServer $server = null;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            $this->server->stop();
            $this->server = null;
        }
        foreach ($this->envBackup as $key => $value) {
            $this->putEnv($key, $value);
        }
        $this->envBackup = [];

        parent::tearDown();
    }

    /* ==================== RemoteFetch::safeOpts()：纯规则 ==================== */

    public function test_safe_opts_refuses_non_web_schemes_without_handing_back_options(): void
    {
        foreach (['file:///etc/passwd', 'phar:///x', 'gopher://127.0.0.1:11211/', 'dict://i:1'] as $url) {
            $r = RemoteFetch::safeOpts($url);
            self::assertFalse($r['ok'], '应当拒绝：' . $url);
            self::assertArrayNotHasKey('opts', $r, '拒收时不得给出可合并的选项');
        }

        // 拒绝的理由要能指到点上：`phar:///x` 连 parse_url 都读不动（curl 却认得它），
        // 报「无法解析」是对的；剩下三个 parse_url 认得，就必须说是协议越界，
        // 否则用户改了半天地址格式，真正被拦的是 scheme。
        foreach (['file:///etc/passwd', 'gopher://127.0.0.1:11211/', 'dict://i:1'] as $url) {
            self::assertStringContainsString('http(s)', (string) RemoteFetch::safeOpts($url)['msg'], $url);
        }
        self::assertStringContainsString('无法解析', (string) RemoteFetch::safeOpts('phar:///x')['msg']);
    }

    public function test_safe_opts_refuses_internal_targets_by_default(): void
    {
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'false');
        $r = RemoteFetch::safeOpts('http://169.254.169.254/latest/meta-data/');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('内网', (string) $r['msg']);
    }

    public function test_safe_opts_carries_the_whole_safety_set_when_it_lets_a_host_through(): void
    {
        // 用 localhost 而不是编一个公网域名：本例只验「放行之时给全了哪几件护具」，
        // 不需要真出站，而 resolve() 要走通才有 opts（离线环境里 example.com 也解析不了）。
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'true');
        $r = RemoteFetch::safeOpts('http://localhost:8080/hook');

        self::assertTrue($r['ok'], (string) ($r['msg'] ?? ''));
        $opts = $r['opts'];
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $opts[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $opts[CURLOPT_REDIR_PROTOCOLS]);
        self::assertTrue($opts[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $opts[CURLOPT_SSL_VERIFYHOST]);
        // 主机名必须钉住解析结果；不钉的话过一次闸、连的时候又解析一次（DNS 重绑定）
        self::assertCount(1, $opts[CURLOPT_RESOLVE], '主机名目标必须钉一条 IP');
        self::assertStringStartsWith('localhost:8080:', (string) $opts[CURLOPT_RESOLVE][0], '钉 IP 形状应为 host:port:ip');
        self::assertNotFalse(
            filter_var(substr((string) $opts[CURLOPT_RESOLVE][0], strlen('localhost:8080:')), FILTER_VALIDATE_IP),
            '钉的必须是一个真 IP，不是又一次解析的占位'
        );
    }

    public function test_safe_opts_skips_the_pin_for_ip_literal_hosts_but_still_gates_protocols(): void
    {
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'true');
        $r = RemoteFetch::safeOpts('http://127.0.0.1:9/hook');

        self::assertTrue($r['ok'], (string) ($r['msg'] ?? ''));
        self::assertSame([], $r['opts'][CURLOPT_RESOLVE], 'IP 字面量没有二次解析可钉');
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $r['opts'][CURLOPT_PROTOCOLS]);
    }

    /* ==================== 调用点接线（反射直调，不碰网络） ==================== */

    public function test_api_call_node_refuses_a_file_scheme_target(): void
    {
        $r = $this->apiCall(['url' => 'file:///etc/passwd', 'method' => 'GET']);

        self::assertTrue((bool) ($r['success'] ?? false) === false, 'file:// 目标不得算成功');
        self::assertStringContainsString('http(s)', (string) ($r['error'] ?? ''));
        self::assertArrayNotHasKey('data', $r, '被拒的节点不得带回任何响应体');
    }

    public function test_api_call_node_refuses_internal_targets_when_the_switch_is_off(): void
    {
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'false');
        $server = $this->server();

        $r = $this->apiCall(['url' => $server->url('/pkg.zip'), 'method' => 'GET']);

        self::assertStringContainsString('内网', (string) ($r['error'] ?? ''));
    }

    public function test_api_call_node_vets_the_url_after_variable_substitution(): void
    {
        // `http://{{host}}/pkg.zip` 的真实目标要等替换完才知道：闸门若在替换前跑，
        // 看到的是字面量 `{{host}}`，拒绝原因只会是「解析失败」；
        // 这里断言的是「内网」，等于证明过闸用的是替换后的那个环回地址。
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'false');
        $server = $this->server();

        $r = $this->apiCall(
            ['url' => 'http://{{host}}/pkg.zip', 'method' => 'GET'],
            ['host' => $server->host()]
        );

        self::assertStringContainsString('内网', (string) ($r['error'] ?? ''));
    }

    public function test_api_call_node_still_reaches_a_public_like_target(): void
    {
        // 开关放开后必须真的能发出去：闸门不能只是把出网全关掉（那等于功能没了）
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'true');
        $server = $this->server();
        $server->put('pkg.zip', 'hook-body');

        $r = $this->apiCall(['url' => $server->url('/pkg.zip'), 'method' => 'GET']);

        self::assertTrue((bool) ($r['success'] ?? false), json_encode($r, JSON_UNESCAPED_UNICODE));
        self::assertSame(200, (int) ($r['http_code'] ?? 0));
        self::assertSame('hook-body', trim((string) ($r['data'] ?? '')));
    }

    public function test_webhook_delivery_refuses_targets_the_gate_rejects(): void
    {
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'false');
        $server = $this->server();

        foreach ([
            'file:///etc/passwd' => 'http(s)',
            $server->url('/pkg.zip') => '内网',
            'http://root:hunter2@' . $server->host() . '/pkg.zip' => '凭证',
        ] as $url => $why) {
            $r = $this->deliverWebhook($url, '{"a":1}', '');
            self::assertSame('failed', $r['status'], '应当拒收：' . $url);
            self::assertStringContainsString($why, (string) $r['error']);
            self::assertSame(0, (int) $r['http_code'], '拒收不得产生任何连接');
        }
    }

    public function test_webhook_delivery_signs_and_posts_when_the_target_is_allowed(): void
    {
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'true');
        $server = $this->server();

        $r = $this->deliverWebhook($server->url('/status?code=200'), '{"a":1}', 's3cret');

        self::assertSame('delivered', $r['status'], json_encode($r));
        self::assertSame(200, (int) $r['http_code']);
    }

    /* ==================== 静态门禁：不许再有未设防的裸 curl ==================== */

    public function test_every_raw_curl_site_in_user_url_callers_is_guarded(): void
    {
        // 工作流的每个 curl_init 目标都来自用户配置：新增一处就必须多一处 safeOpts，
        // 数量对齐是最省事的不变量（少一个就是有人开了后门）。
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/plugins/frontend/WorkflowBridgeController.php'
        );
        self::assertSame(
            substr_count($src, 'curl_init('),
            substr_count($src, 'RemoteFetch::safeOpts('),
            'WorkflowBridge 里出现未过闸的裸 curl_init'
        );
        self::assertStringNotContainsString(
            'CURLOPT_SSL_VERIFYPEER => false',
            $src,
            '出网请求不得关掉服务端证书校验'
        );
    }

    /* ==================== 工具 ==================== */

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function apiCall(array $config, array $context = []): array
    {
        $method = new \ReflectionMethod(WorkflowBridgeController::class, 'executeApiCall');
        $method->setAccessible(true);

        /** @var array<string, mixed> $r */
        $r = $method->invoke(new WorkflowBridgeController(), $config, $context);

        return $r;
    }

    /** @return array<string, mixed> */
    private function deliverWebhook(string $url, string $body, string $secret): array
    {
        $method = new \ReflectionMethod(WorkflowBridgeController::class, 'deliverWebhook');
        $method->setAccessible(true);

        /** @var array<string, mixed> $r */
        $r = $method->invoke(new WorkflowBridgeController(), $url, $body, $secret);

        return $r;
    }

    private function server(): LocalFetchServer
    {
        if ($this->server === null) {
            $root = sys_get_temp_dir() . '/kode-wf-root-' . bin2hex(random_bytes(6));
            mkdir($root, 0775, true);
            try {
                $this->server = new LocalFetchServer($root);
                $this->server->start();
            } catch (\Throwable $e) {
                $this->removeTree($root);
                self::markTestSkipped('本地 php -S 起不来：' . $e->getMessage());
            }
        }

        return $this->server;
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->envBackup)) {
            $this->envBackup[$key] = getenv($key);
        }
        $this->putEnv($key, $value);
    }

    private function putEnv(string $key, string|false $value): void
    {
        // env() 读 $_ENV / $_SERVER / getenv 三处，必须一起改，否则留着上一例的值
        if ($value === false) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
