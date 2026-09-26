<?php

declare(strict_types=1);

namespace app\middleware;

use Kode\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 操作审计（只记元数据：谁、何时、什么方法、什么路径、状态、耗时、IP）。
 *
 * 不记录请求体（防密码/敏感数据落盘）；探针路径跳过。
 * 挂载见 app/bootstrap.php。
 */
final class AuditMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $skip = ['/health', '/health/live', '/health/ready', '/ping', '/metrics', '/favicon.ico'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $t0 = microtime(true);
        $response = $handler->handle($request);

        try {
            $path = $request->getUri()->getPath();
            foreach ($this->skip as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                    return $response;
                }
            }
            if (!str_starts_with($path, '/api/')) {
                return $response;
            }

            $payload = Request::attr('auth');
            $uid = (string) ($payload?->uid ?? 0);
            \app\support\AuditTrail::record(
                $uid,
                (string) ($payload?->username ?? 'guest'),
                $request->getMethod(),
                $path,
                $response->getStatusCode(),
                (int) round((microtime(true) - $t0) * 1000),
                \app\support\ClientIp::resolve()
            );
        } catch (\Throwable) {
            // 审计失败绝不影响主流程。
        }

        return $response;
    }
}
