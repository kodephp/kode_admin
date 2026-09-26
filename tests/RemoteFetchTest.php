<?php

declare(strict_types=1);

namespace app\tests;

use app\support\RemoteFetch;
use app\tests\Support\LocalFetchServer;
use Kode\Framework\Testing\TestCase;

/**
 * 服务端出网闸门：`vet()` 的规则 + `toFile()` 的逐跳/预算行为（真 HTTP 服务，非 stub）。
 *
 * 修的是三件各自漏过的事（详见 RemoteFetch 类注释）：
 *  1. 只查第一跳 —— 302 到内网元数据端点由 curl 自己跟，没人复核；
 *  2. 查完让 curl 再解析一次 —— 两次解析之间换 A 记录就是 DNS 重绑定，所以要钉住 IP；
 *  3. 下完才判体积 —— 对方可以先把磁盘灌满，现在边下边算、超预算立刻断线。
 */
final class RemoteFetchTest extends TestCase
{
    private ?LocalFetchServer $server = null;

    private string $dest = '';

    private string $docRoot = '';

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dest = sys_get_temp_dir() . '/kode-fetch-' . bin2hex(random_bytes(6)) . '.bin';
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'true');
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            $this->server->stop();
            $this->server = null;
        }
        @unlink($this->dest);
        if ($this->docRoot !== '') {
            $this->removeTree($this->docRoot);
            $this->docRoot = '';
        }
        foreach ($this->envBackup as $key => $value) {
            $this->putEnv($key, $value);
        }
        $this->envBackup = [];
        parent::tearDown();
    }

    /* ==================== vet()：纯规则，不连网 ==================== */

    public function test_vet_refuses_every_non_web_scheme(): void
    {
        // 消息也断言：`file:///etc/passwd` 的 host 是空串，判序写反会把它报成「无法解析」，
        // 协议门禁就退化成一条看不见的规则。
        foreach ([
            'file:///etc/passwd',
            'gopher://127.0.0.1:11211/',
            'ftp://host/x.zip',
            'dict://127.0.0.1:6379/',
            'data://text/plain,<?php%20echo%201;',
        ] as $url) {
            $r = RemoteFetch::vet($url);
            self::assertFalse($r['ok'], '应当拒绝：' . $url);
            self::assertStringContainsString('http(s)', (string) $r['msg'], $url . ' 的拒绝原因应是协议门禁');
        }
    }

    public function test_vet_refuses_malformed_addresses(): void
    {
        foreach ([
            'http://',
            'not a url',
            'http:///no-host',
            'http://host:0/',
            'http://host:99999/x',
            // parse_url 直接判死的多协议地址：拒绝理由不必统一，但绝不能放过
            'phar:///x',
            'php://filter/read=convert.base64-encode/resource=/etc/passwd',
        ] as $url) {
            self::assertFalse(RemoteFetch::vet($url)['ok'], '应当拒绝：' . $url);
        }
    }

    public function test_vet_refuses_credentials_in_the_url(): void
    {
        // user:pass@ 会把凭证带进 Basic Auth，也可能被日志/审计原样记下
        $r = RemoteFetch::vet('http://root:hunter2@127.0.0.1:8080/x.zip');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('凭证', (string) $r['msg']);
    }

    public function test_vet_refuses_internal_and_reserved_addresses_by_default(): void
    {
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'false');
        foreach ([
            'http://127.0.0.1/x.zip',
            'http://10.0.0.5/x.zip',
            'http://192.168.1.1/x.zip',
            'http://172.16.9.9/x.zip',
            'http://169.254.169.254/latest/meta-data/',
            'http://0.0.0.0/x.zip',
            'http://[::1]/x.zip',
        ] as $url) {
            $r = RemoteFetch::vet($url);
            self::assertFalse($r['ok'], '内网/保留地址必须默认拒绝：' . $url);
            self::assertStringContainsString('内网', (string) $r['msg']);
        }
    }

    public function test_allow_private_switch_actually_lets_loopback_through(): void
    {
        // 开关不能只是「读了但没用」：本地联调自建市场全靠它
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'false');
        self::assertFalse(RemoteFetch::vet('http://127.0.0.1:9/x.zip')['ok']);

        $this->setEnv('MARKET_ALLOW_PRIVATE', 'true');
        $r = RemoteFetch::vet('http://127.0.0.1:9/x.zip');
        self::assertTrue($r['ok']);
        self::assertSame('127.0.0.1', $r['ip']);
        self::assertSame(9, $r['port']);
    }

    public function test_vet_pins_a_resolved_ip_for_the_hostname(): void
    {
        // 钉 IP 是 DNS 重绑定的唯一解药：vet 不返回 ip，toFile 就无从钉住，
        // 「过闸的地址」和「真正连的地址」会是两次解析的结果。
        $r = RemoteFetch::vet('http://localhost/pkg.zip');
        if (!$r['ok']) {
            self::markTestSkipped('本机无法解析 localhost：' . $r['msg']);
        }
        self::assertNotSame('', (string) $r['ip']);
        self::assertNotSame('localhost', $r['ip'], '应当给出解析结果而不是主机名');
        self::assertNotFalse(filter_var($r['ip'], FILTER_VALIDATE_IP), '钉住的必须是 IP 字面量');
    }

    /* ==================== toFile()：真服务、真重定向 ==================== */

    public function test_download_lands_byte_for_byte(): void
    {
        $server = $this->server();
        $server->put('pkg.zip', "zip-bytes\n");

        $r = RemoteFetch::toFile($server->url('/pkg.zip'), $this->dest);

        self::assertTrue($r['ok'], (string) ($r['msg'] ?? ''));
        self::assertSame("zip-bytes\n", file_get_contents($this->dest));
        self::assertSame(10, $r['bytes']);
    }

    public function test_failed_download_leaves_no_partial_file(): void
    {
        $r = RemoteFetch::toFile($this->server()->url('/nope.zip'), $this->dest);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('404', (string) $r['msg']);
        self::assertFileDoesNotExist($this->dest, '失败不能留下半截产物');
    }

    public function test_internal_address_is_refused_at_the_first_hop_when_the_switch_is_off(): void
    {
        $this->setEnv('MARKET_ALLOW_PRIVATE', 'false');
        $server = $this->server();

        $r = RemoteFetch::toFile($server->url('/pkg.zip'), $this->dest);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('内网', (string) $r['msg']);
        self::assertFileDoesNotExist($this->dest);
    }

    public function test_second_hop_is_revalidated(): void
    {
        // 入口是环回（本例开着 MARKET_ALLOW_PRIVATE），跳转目标换成「带凭证的地址」：
        // 只有真的逐跳复核过，才会拒在第二跳上。curl 自己 FOLLOWLOCATION 时没人复核，
        // 这条断言就正好是「302 → 内网元数据端点」那类攻击的等价类。
        $server = $this->server();
        $evil = 'http://root:hunter2@' . $server->host() . '/pkg.zip';

        $r = RemoteFetch::toFile($server->url('/redirect?to=' . rawurlencode($evil)), $this->dest);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('凭证', (string) $r['msg']);
        self::assertFileDoesNotExist($this->dest);
    }

    public function test_redirect_to_non_http_scheme_is_refused(): void
    {
        $server = $this->server();
        $r = RemoteFetch::toFile(
            $server->url('/redirect?to=' . rawurlencode('file:///etc/passwd')),
            $this->dest
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('http(s)', (string) $r['msg']);
        self::assertFileDoesNotExist($this->dest, '跳转为 file:// 时绝不能把系统文件带回来');
    }

    public function test_relative_redirect_is_resolved_against_the_current_url(): void
    {
        $server = $this->server();
        $server->put('real.zip', 'payload');

        $r = RemoteFetch::toFile($server->url('/redirect?to=' . rawurlencode('/real.zip')), $this->dest);

        self::assertTrue($r['ok'], (string) ($r['msg'] ?? ''));
        self::assertSame('payload', file_get_contents($this->dest));
    }

    public function test_protocol_relative_redirect_inherits_the_current_scheme(): void
    {
        // `Location: //host/x` 是协议相对地址。糊成当前主机的一段路径会「碰巧没出事」，
        // 但真实语义是换主机——必须按原义拼回来，才能交给 vet() 判（换到内网照样拒）。
        $server = $this->server();
        $server->put('real.zip', 'payload');

        $r = RemoteFetch::toFile(
            $server->url('/redirect?to=' . rawurlencode('//' . $server->host() . '/real.zip')),
            $this->dest
        );

        self::assertTrue($r['ok'], (string) ($r['msg'] ?? ''));
        self::assertSame('payload', file_get_contents($this->dest));
    }

    public function test_redirect_loop_stops_at_the_hop_budget(): void
    {
        $server = $this->server();
        $server->loopHits(true);

        $r = RemoteFetch::toFile($server->url('/loop'), $this->dest);
        $hits = $server->loopHits();

        self::assertFalse($r['ok']);
        self::assertStringContainsString('重定向', (string) $r['msg']);
        self::assertFileDoesNotExist($this->dest);
        // 入口 + MAX_HOPS 次跟随；不设预算的话这个数会一直涨到超时
        self::assertLessThanOrEqual(4, $hits, '跳数预算没有生效');
    }

    public function test_oversized_body_is_aborted_while_streaming(): void
    {
        // 2MB 不限长流 + 64KB 预算：必须在下载中途断线，而不是收完再判
        $server = $this->server();
        $server->streamSent(true);
        $t0 = microtime(true);
        $r = RemoteFetch::toFile($server->url('/stream?bytes=2097152'), $this->dest, 65536, 20);
        $took = microtime(true) - $t0;
        $sent = $server->streamSent();

        self::assertFalse($r['ok']);
        self::assertStringContainsString('体积上限', (string) $r['msg']);
        self::assertFileDoesNotExist($this->dest);
        self::assertLessThan(5.0, $took, '超预算应当当场断线，不该等整包传完');
        // 预算 64KB；只留下 socket 缓冲那点余量。传完 2MB 才判 = 「对方可以先把磁盘灌满」
        self::assertLessThan(
            300000,
            $sent,
            '服务端发出了 ' . $sent . ' 字节：预算没能在传输途中生效（应约 64KB 处断线）'
        );
    }

    public function test_empty_body_is_reported_as_failure(): void
    {
        $r = RemoteFetch::toFile($this->server()->url('/empty'), $this->dest);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('为空', (string) $r['msg']);
        self::assertFileDoesNotExist($this->dest);
    }

    public function test_client_error_status_is_reported_with_the_code(): void
    {
        $r = RemoteFetch::toFile($this->server()->url('/status?code=403'), $this->dest);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('403', (string) $r['msg']);
    }

    /* ==================== 工具 ==================== */

    private function server(): LocalFetchServer
    {
        if ($this->server === null) {
            $root = sys_get_temp_dir() . '/kode-fetch-root-' . bin2hex(random_bytes(6));
            mkdir($root, 0775, true);
            $candidate = new LocalFetchServer($root);
            try {
                $candidate->start();
                $this->server = $candidate;
                $this->docRoot = $root;
            } catch (\Throwable $e) {
                $candidate->stop();
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
