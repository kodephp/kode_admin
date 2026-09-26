<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\PluginController;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 批量卸载的参数门禁：不支持的 `purge` 必须被拒绝，而不是收下不办事。
 *
 * 单插件 `POST /api/plugins/{name}/uninstall` 认 purge（真删表），
 * 批量接口 `POST /api/plugins/batch-uninstall` 只做状态翻转 —— 但它照单收下 purge，
 * 响应 200、`uninstalled` 列了一串名字，表却全在。运维以为清库了，实际什么都没删：
 * 与框架侧「`migrate --pretend` 静默放行」同一类缺陷（kode/framework v1.9.0 的教训），
 * 只是这次发生在写接口上，且方向更危险（留下了本该消失的数据）。
 *
 * 现在直接 400 并把正确路径写在消息里。
 */
final class PluginBatchUninstallGateTest extends TestCase
{
    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        HttpRequest::clear();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, msg?: string}
     */
    private function call(array $payload): array
    {
        HttpRequest::setRequest(new ServerRequest(
            'POST',
            '/api/plugins/batch-uninstall',
            [],
            ['content-type' => 'application/json'],
            (string) json_encode($payload)
        ));

        $response = (new PluginController())->batchUninstall();
        $body = (array) json_decode((string) $response->getBody(), true);

        // 统一信封：code 恒等于 HTTP 状态
        return ['status' => (int) ($body['code'] ?? $response->getStatusCode()), 'msg' => (string) ($body['msg'] ?? '')];
    }

    public function test_purge_is_refused_and_points_at_the_real_endpoint(): void
    {
        $out = $this->call(['names' => [], 'confirm' => true, 'purge' => true]);

        self::assertSame(400, $out['status'], '批量卸载收了 purge 却删不了表，必须显式拒绝：' . $out['msg']);
        self::assertStringContainsString('/uninstall', $out['msg'], '拒绝消息要给出真正的删表入口');
    }

    /**
     * 门禁顺序：confirm 在前。否则 `purge=1` 无 confirm 的请求会先被 purge 挡下，
     * 报错信息与用户真正漏掉的参数无关。
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('confirmFirstCases')]
    public function test_confirm_check_precedes_the_purge_gate(array $payload, string $expected): void
    {
        $out = $this->call($payload);

        self::assertSame(400, $out['status']);
        self::assertStringContainsString($expected, $out['msg']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function confirmFirstCases(): array
    {
        return [
            '只缺 confirm' => [['names' => ['zz-nope']], 'confirm'],
            '缺 confirm 且带 purge' => [['names' => ['zz-nope'], 'purge' => true], 'confirm'],
            'confirm 有但 purge 非法值' => [['names' => [], 'confirm' => true, 'purge' => 'yes'], '/uninstall'],
        ];
    }
}
