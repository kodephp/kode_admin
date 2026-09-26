<?php

declare(strict_types=1);

namespace app\middleware;

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
 *    形态）统一包成信封，已是信封的原样透传；载荷里除 message/errors 之外的键
 *    **搬进 data 而不是丢掉**（见下面的注释）。
 *  - 例外：`/health`、`/health/ready` 恒透传，见 SHAPE_STABLE。
 */
final class TimingMiddleware implements MiddlewareInterface
{
    /**
     * 自带响应形状的运维端点：绿灯和红灯必须是同一份结构。
     *
     * 监控探针只有一套解析代码（`curl /health | jq .status`），而「红灯」恰恰是它唯一
     * 会被真正读一次的时刻。把 degraded 的 503 归一成 `{code,msg}` 等于报告在它亮起时
     * 失踪：k8s 靠状态码摘流不受影响，但人来了看不到是哪个组件挂的。
     * 这两条也不含用户数据，透传不产生 §4 的暴露面（明细脱敏在探针自己那侧）。
     */
    private const SHAPE_STABLE = ['/health', '/health/ready'];

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

        $path = '/' . ltrim($request->getUri()->getPath(), '/');
        if (in_array($path, self::SHAPE_STABLE, true)) {
            return $response;
        }

        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body) || array_key_exists('code', $body)) {
            return $response;
        }

        $extra = [];
        if (isset($body['errors'])) {
            $extra['errors'] = $body['errors'];
        }
        // 处理器自己写的载荷不能被归一化吃掉：判定式只看 message，其余键此前随 body 一起丢弃，
        // 于是「4xx/5xx + 附带详情」（例如带冲突清单的 409）到客户端只剩「请求失败」四个字。
        // 框架的结构化错误体只有 {message}，那种情况下面的结果与改动前逐字节相同。
        if (($rest = array_diff_key($body, ['message' => 1, 'errors' => 1])) !== []) {
            $extra['data'] = $rest;
        }

        return ApiEnvelope::fail(
            (string) ($body['message'] ?? '请求失败'),
            $status,
            $t0,
            $extra
        );
    }
}
