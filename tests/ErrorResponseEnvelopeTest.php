<?php

declare(strict_types=1);

namespace app\tests;

use app\middleware\TimingMiddleware;
use Kode\Framework\Http\Resp;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 错误信封归一化不得吃掉处理器自己写的载荷。
 *
 * `TimingMiddleware` 的出口归一化只认一件事：把框架的 `{message}` 形态换成统一信封。
 * 但它此前是**重新造一个响应**，于是「4xx/5xx + 附带详情」的载荷在换壳时被整份丢弃：
 * 一个返回 `409 {message:'名称冲突', conflicts:[…]}` 的接口，客户端拿到的只有
 * `{code:409, msg:'名称冲突', dur}` —— 冲突清单没了；而 `/health` 那种
 * 「状态码即结论、报告即正文」的运维端点更糟：degraded 的 503 一到，
 * `status` 与 `components` 一起消失，监控只剩一个空壳
 * （实测：`{"code":503,"msg":"请求失败","dur":"0.0019s"}`）。
 * 红灯时刻恰好是唯一没人读到报告的时刻，这类缺陷平时完全看不见。
 *
 * 所以本类固化三件：
 *  1. message/errors 之外的键搬进 `data`，不再静默丢弃；
 *  2. 框架那种只有 `{message}` 的错误体，归一化结果与改动前逐字节同形（不许多出 data）；
 *  3. `/health`、`/health/ready` 恒透传 —— 绿灯与红灯必须是同一份结构，且状态码照常 503。
 *
 * 直接测 PSR-15 `process()`（不 bootApp、不碰 HTTP）：这里要判的是壳的形状，不是路由。
 */
final class ErrorResponseEnvelopeTest extends TestCase
{
    /** @param array<string, mixed> $body */
    private function handler(int $status, array $body): RequestHandlerInterface
    {
        return new class ($status, $body) implements RequestHandlerInterface {
            public function __construct(private readonly int $status, private readonly array $body)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Resp::json($this->body, $this->status);
            }
        };
    }

    /** @param array<string, mixed> $body */
    private function pipe(string $path, int $status, array $body): ResponseInterface
    {
        return (new TimingMiddleware())->process(
            new ServerRequest('GET', $path),
            $this->handler($status, $body)
        );
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $r): array
    {
        $out = json_decode((string) $r->getBody(), true);
        self::assertIsArray($out, '响应体不是 JSON：' . (string) $r->getBody());

        return $out;
    }

    public function test_extra_payload_survives_the_envelope_swap(): void
    {
        $body = $this->decode($this->pipe('/api/widgets', 409, [
            'message' => '名称冲突',
            'conflicts' => ['a.txt', 'b.txt'],
        ]));

        self::assertSame(409, $body['code'] ?? null);
        self::assertSame('名称冲突', $body['msg'] ?? null);
        self::assertArrayHasKey('dur', $body);
        self::assertSame(
            ['conflicts' => ['a.txt', 'b.txt']],
            $body['data'] ?? null,
            '处理器的详情被归一化吃掉了：客户端只剩一句「名称冲突」，无从定位是哪两个'
        );
    }

    public function test_errors_stay_a_sibling_key_not_buried_in_data(): void
    {
        // errors 是 §3 信封的既有同级键（422 校验失败就靠它），前端按老位置读，不许挪进 data。
        $body = $this->decode($this->pipe('/api/widgets', 422, [
            'message' => '参数校验失败',
            'errors' => ['name' => '不能为空'],
        ]));

        self::assertSame(['name' => '不能为空'], $body['errors'] ?? null);
        self::assertArrayNotHasKey('data', $body, '只有 message/errors 的形状不得凭空多出 data 键');
    }

    public function test_a_bare_framework_message_normalizes_exactly_as_before(): void
    {
        // 绝大多数错误（401/403/404）走的就是这一条，键集必须锁死：
        // 否则「保留未知键」会变成给全站错误响应加字段，前端逐键断言会莫名变红。
        $body = $this->decode($this->pipe('/api/users', 401, ['message' => 'Unauthenticated']));

        self::assertSame(['code', 'msg', 'dur'], array_keys($body));
        self::assertSame('Unauthenticated', $body['msg']);
    }

    public function test_an_already_enveloped_response_is_passed_through_untouched(): void
    {
        $payload = ['code' => 500, 'msg' => '服务器错误', 'dur' => '0.0001s', 'data' => ['x' => 1]];
        $response = $this->pipe('/api/jobs', 500, $payload);

        self::assertSame($payload, $this->decode($response));
        self::assertSame(500, $response->getStatusCode());
    }

    public function test_the_health_report_keeps_its_shape_when_it_turns_red(): void
    {
        $report = [
            'framework' => 'kode',
            'version' => '9.9.9', // 刻意用假版本：这里是合成载荷，不是框架版本自述面
            'status' => 'degraded',
            'components' => ['db' => 'error', 'app' => 'ok'],
        ];

        $response = $this->pipe('/health', 503, $report);

        self::assertSame(503, $response->getStatusCode(), '透传不等于改判：503 必须还在');
        self::assertSame($report, $this->decode($response));

        // 对照：同一条 503 报告换个路径就必须被归一化 —— 少了这条，「全部透传」也能过。
        $other = $this->decode($this->pipe('/healthz', 503, $report));
        self::assertArrayHasKey('msg', $other);
        self::assertArrayNotHasKey('components', $other);
        self::assertSame($report, $other['data'] ?? null);
    }

    public function test_the_ready_probe_is_exempt_too(): void
    {
        // /health/ready 是 k8s readinessProbe 真正打的那条，报告失踪的正是它。
        $report = ['status' => 'degraded', 'checks' => ['db' => 'error']];

        $response = $this->pipe('/health/ready', 503, $report);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame($report, $this->decode($response));
    }
}
