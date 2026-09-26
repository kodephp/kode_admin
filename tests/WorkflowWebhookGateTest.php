<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Testing\TestCase;
use Kode\Plugins\frontend\WorkflowBridgeController;

/**
 * Webhook 触发器的准入回归。
 *
 * `POST /api/workflow/webhook/{name}` 是插件里唯一「故意不挂 JWT」的执行入口（外部系统
 * 调它）。旧实现把签名校验写在 `if ($webhook_secret !== '')` 里面，于是**没配密钥**不是
 * 配置提醒而是完全敞开：任何能连到后台的调用方都能拉起一条会写库、会出网、会跑 on_fail
 * 分支的流程；IP 白名单同样可以留空，两道都空就是匿名执行。
 *
 * 口径（判据只在 `webhookAdmission()` 一份）：secret 与 ip 至少要有一道；两道都配了就必须
 * 都过；签名比较恒走 hash_equals。
 */
final class WorkflowWebhookGateTest extends TestCase
{
    private const SECRET = 's3cret-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    /* ==================== 判定本身 ==================== */

    public function test_workflow_without_any_credential_is_refused_instead_of_running(): void
    {
        $denied = $this->admission('', '', '203.0.113.7', '', '{"a":1}');

        self::assertNotNull($denied, '两道凭证都没配时必须拒，而不是放行');
        self::assertSame(403, (int) $denied['code']);
        self::assertStringContainsString('未配置', (string) $denied['msg'], '理由要指到「去配一项」，别只说无权限');
    }

    public function test_whitespace_only_credentials_do_not_count_as_configured(): void
    {
        // 表里存了个空格串就以为开了防护，等于没开（判定端自己 trim，才不靠调用方记得 trim）
        $denied = $this->admission('   ', '   ', '203.0.113.7', '', '');

        self::assertStringContainsString('未配置', (string) $denied['msg'], '要落到「去配一项」那条理由上');
    }

    public function test_blank_entries_in_the_ip_list_admit_nobody(): void
    {
        // `"203.0.113.7, "` 是配置框里最常见的写法；空项留在表里就会顺手放行 REMOTE_ADDR 为空的请求。
        self::assertNotNull($this->admission('', '203.0.113.7, ', '', '', '{}'), '空 IP 不得命中白名单');
        self::assertNotNull($this->admission('', ',,', '203.0.113.7', '', '{}'), '整张表都是空项时等于没配，必须全拒');
    }

    public function test_ip_allowlist_admits_only_listed_clients(): void
    {
        self::assertNull($this->admission('', '203.0.113.7', '203.0.113.7', '', '{}'), '白名单内应放行');

        $denied = $this->admission('', '203.0.113.7', '198.51.100.4', '', '{}');
        self::assertNotNull($denied);
        self::assertSame(403, (int) $denied['code']);
        self::assertStringContainsString('198.51.100.4', (string) $denied['msg'], '被拒的要回显来源 IP，否则排查只能靠猜');
    }

    public function test_ip_list_tolerates_the_spacing_people_actually_type(): void
    {
        // 配置框里写 "10.0.0.1, 10.0.0.2" 是最自然的输入；不 trim 就是第二台机器永远进不来
        $list = ' 203.0.113.7 , 198.51.100.4 ';
        self::assertNull($this->admission('', $list, '198.51.100.4', '', '{}'));
        self::assertNull($this->admission('', $list, '203.0.113.7', '', '{}'));
    }

    public function test_ip_allowlist_matches_whole_entries_not_substrings(): void
    {
        // 前缀匹配会让 "203.0.113.7" 顺手放行 203.0.113.70（同网段的另一台机器）
        self::assertNotNull($this->admission('', '203.0.113.7', '203.0.113.70', '', '{}'));
    }

    public function test_signature_must_be_the_hmac_of_the_exact_body(): void
    {
        $body = '{"params":{"amount":1}}';
        $ok = hash_hmac('sha256', $body, self::SECRET);

        self::assertNull($this->admission(self::SECRET, '', '203.0.113.7', $ok, $body));

        // 逐个列成 [签名, 理由]，不写成以签名为键的字面量数组：十六进制串里全是数字时
        // PHP 会把键转成 int，两条用例撞成一条，漏掉的那条就没人验了。
        foreach ([
            ['', '不带签名'],
            [str_repeat('0', 64), '乱填签名'],
            [substr($ok, 0, 63) . '0', '改过末位'],
        ] as [$sig, $why]) {
            $denied = $this->admission(self::SECRET, '', '203.0.113.7', $sig, $body);
            self::assertNotNull($denied, $why . ' 不得放行');
            self::assertSame(401, (int) $denied['code'], $why . '：签名问题是 401 不是 403');
        }
    }

    public function test_a_signature_does_not_carry_over_to_a_different_body(): void
    {
        // 抓到一次合法签名后改金额重放：body 变了 HMAC 必须失效
        $sig = hash_hmac('sha256', '{"params":{"amount":1}}', self::SECRET);
        $denied = $this->admission(self::SECRET, '', '203.0.113.7', $sig, '{"params":{"amount":9999}}');

        self::assertNotNull($denied);
        self::assertSame(401, (int) $denied['code']);
    }

    public function test_configuring_both_credentials_keeps_both_checks(): void
    {
        $body = '{}';
        $sig = hash_hmac('sha256', $body, self::SECRET);

        self::assertNull($this->admission(self::SECRET, '203.0.113.7', '203.0.113.7', $sig, $body), '两道都过才放行');
        self::assertSame(
            403,
            (int) $this->admission(self::SECRET, '203.0.113.7', '198.51.100.4', $sig, $body)['code'],
            'IP 不在白名单时不必再算签名：先挡在门口'
        );
        self::assertSame(
            401,
            (int) $this->admission(self::SECRET, '203.0.113.7', '203.0.113.7', 'nope', $body)['code'],
            'IP 放行不等于免签：白名单机器也要带正确签名'
        );
    }

    /* ==================== 接线：判定真的挂在执行之前 ==================== */

    public function test_route_gates_before_anything_is_executed(): void
    {
        $body = $this->methodBody('webhookTrigger');

        $gate = strpos($body, '$this->webhookAdmission(');
        $run = strpos($body, '$this->executeWorkflowInternal(');
        self::assertNotFalse($gate, 'webhookTrigger 必须调判定，否则它只是文档');
        self::assertNotFalse($run);
        self::assertLessThan($run, $gate, '必须先判定再执行：反过来等于先跑完再问该不该跑');

        // 「调了判定」不等于「听了判定」：拒判必须紧接着无条件 return，
        // 否则 $denied 拿到手却没人处理，执行照旧往下走（形状写歪就当场红）。
        self::assertMatchesRegularExpression(
            '/\$denied = \$this->webhookAdmission\((.*?)\);\s*if \(\$denied !== null\) \{\s*'
                . 'return \$this->error\(\$denied\[.msg.\], \$denied\[.code.\]\);\s*\}/s',
            $body,
            'webhookAdmission 的拒绝结果必须原样直接返回'
        );
    }

    public function test_credentials_are_read_before_the_gate_knows_the_config(): void
    {
        // 签名与原始体要在进判定前就取好：写在 `if ($secret !== '')` 里会因为
        // 读不到 body 而「验签通过」（空签名对空 HMAC 也可能撞），且 PSR-7 流只能读一次。
        $body = $this->methodBody('webhookTrigger');
        $gate = strpos($body, '$this->webhookAdmission(');
        $head = substr($body, 0, $gate);

        self::assertGreaterThan(0, $gate);
        self::assertStringContainsString('X-Workflow-Signature', $head, '签名头要在进判定前读');
        self::assertStringContainsString("php://input", $head, '原始体要在进判定前读');
    }

    public function test_signature_comparison_uses_hash_equals(): void
    {
        $body = $this->methodBody('webhookAdmission');

        self::assertStringContainsString('hash_equals(', $body);
        // 任何形式比较 `$signature` 的运算符都不许出现（`!=` 与 `==` 一样能在等长前缀上撞开，
        // 而且松等号在 PHP 8 里对数字串还会先转数值）：只留 hash_equals 一条路。
        self::assertSame(
            0,
            (int) preg_match('/(\$signature\s*[!=]==?)|([!=]==?\s*\$signature)/', $body),
            '签名比较必须走 hash_equals，不能换成 ==/!=/===/!=='
        );
    }

    public function test_uncredentialed_workflow_is_reported_before_the_author_hits_it(): void
    {
        // 判定改成「没配就拒」之后，作者第一次点 webhook 会拿到一句看不懂的 403。
        // 安全审计必须同时把「为什么被拒 + 怎么放开」说清楚，否则这个收口会被当成 bug 回退。
        $audit = $this->methodBody('securityAudit');

        self::assertStringContainsString("'check' => 'open_webhook_trigger'", $audit);
        self::assertStringContainsString("'severity' => 'critical'", $audit);
        self::assertStringContainsString('webhook_ips', $audit, '审计判据要同时看两道凭证');
    }

    /* ==================== 工具 ==================== */

    /**
     * @return array{code:int, msg:string}|null
     */
    private function admission(string $secret, string $ipList, string $clientIp, string $signature, string $rawBody): ?array
    {
        $method = new \ReflectionMethod(WorkflowBridgeController::class, 'webhookAdmission');
        $method->setAccessible(true);

        /** @var array{code:int, msg:string}|null $r */
        $r = $method->invoke(new WorkflowBridgeController(), $secret, $ipList, $clientIp, $signature, $rawBody);

        return $r;
    }

    private function methodBody(string $method): string
    {
        $ref = new \ReflectionMethod(WorkflowBridgeController::class, $method);
        $file = (string) $ref->getFileName();
        $lines = file($file);
        self::assertIsArray($lines);

        $start = (int) $ref->getStartLine() - 1;
        $end = (int) $ref->getEndLine();

        return implode('', array_slice($lines, $start, max(0, $end - $start)));
    }
}
