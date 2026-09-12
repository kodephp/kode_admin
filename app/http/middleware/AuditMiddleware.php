<?php

declare(strict_types=1);

namespace app\http\middleware;

use Kode\Database\Db\Db;
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

            \app\support\Shard::ensureTables('kode_audit_logs');
            $payload = Request::attr('auth');
            $uid = (string) ($payload?->uid ?? 0);
            $ip = substr((string) (Request::ip() ?? ''), 0, 64);
            $table = \app\support\Shard::table('kode_audit_logs', $uid !== '' && $uid !== '0' ? $uid : $ip);
            Db::insert(
                "INSERT INTO {$table} (user_id, username, method, path, status, duration_ms, ip, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    $uid,
                    (string) ($payload?->username ?? 'guest'),
                    $request->getMethod(),
                    substr($path, 0, 255),
                    $response->getStatusCode(),
                    (int) round((microtime(true) - $t0) * 1000),
                    $ip,
                ]
            );
        } catch (\Throwable) {
            // 审计失败绝不影响主流程。
        }

        return $response;
    }
}
