<?php

declare(strict_types=1);

namespace app\http\middleware;

use app\support\ApiEnvelope;
use Kode\Framework\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 请求计时 + 错误响应统一信封化（挂 ExceptionMiddleware 内侧，见 app/bootstrap.php）。
 *
 *  - 入口打 `_t0` 时间戳，信封 dur 据此计算；
 *  - 仅捕获 ValidationException 转 422 统一信封（必须比 ExceptionMiddleware 更靠近
 *    handler，否则异常先被它接走），其余异常原样上抛走框架结构化错误。
 *  - 出口归一化：框架层直接返回的错误响应（鉴权 401、门禁 403、404 等 `{message}`
 *    形态）统一包成信封，已是信封的原样透传。
 */
final class TimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $t0 = microtime(true);
        $request = $request->withAttribute('_t0', $t0);

        try {
            $response = $handler->handle($request);
        } catch (ValidationException $e) {
            return ApiEnvelope::fail(
                $e->getMessage() ?: '参数校验失败',
                422,
                $t0,
                ['errors' => $e->errors()]
            );
        }

        $status = $response->getStatusCode();
        if ($status < 400) {
            return $response;
        }
        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body) || array_key_exists('code', $body)) {
            return $response;
        }

        return ApiEnvelope::fail(
            (string) ($body['message'] ?? '请求失败'),
            $status,
            $t0,
            isset($body['errors']) ? ['errors' => $body['errors']] : []
        );
    }
}
