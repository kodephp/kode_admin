<?php

declare(strict_types=1);

namespace app\middleware;

use app\support\ApiEnvelope;
use app\support\ApiSigner;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * API 签名校验中间件（按需挂载到需防篡改/防重放的路由）。
 *
 * 密钥来源：构造参数，或 API_SIGN_SECRET 环境变量（多 appKey 场景自行扩展为映射表）。
 * nonce 防重放为进程内存实现（多 worker 下跨进程重放有残留窗口；强需求请换 redis 实现）。
 *
 * 用法：->middleware([new AuthMiddleware(), new ApiSignMiddleware()])
 */
final class ApiSignMiddleware implements MiddlewareInterface
{
    /** @var array<string, true> 已见 nonce（值~=过期时间戳，惰性清理） */
    private static array $seen = [];

    private string $secret;

    public function __construct(?string $secret = null)
    {
        $this->secret = (string) ($secret ?? env('API_SIGN_SECRET', ''));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->secret === '') {
            return ApiEnvelope::fail('签名密钥未配置', 500);
        }

        $headers = [
            'key' => $request->getHeaderLine('X-Api-Key'),
            'ts' => $request->getHeaderLine('X-Api-Timestamp'),
            'nonce' => $request->getHeaderLine('X-Api-Nonce'),
            'sign' => $request->getHeaderLine('X-Api-Sign'),
        ];
        if ($headers['ts'] === '' || $headers['nonce'] === '' || $headers['sign'] === '') {
            return ApiEnvelope::fail('缺少签名参数', 401);
        }

        $ts = (int) $headers['ts'];
        if (abs(time() - $ts) > ApiSigner::WINDOW) {
            return ApiEnvelope::fail('签名已过期', 401);
        }

        $this->sweep();
        $nonceKey = $headers['nonce'];
        if (!$this->markNonce($nonceKey)) {
            return ApiEnvelope::fail('重放请求', 401);
        }

        parse_str((string) $request->getUri()->getQuery(), $query);
        $expect = ApiSigner::sign(
            $this->secret,
            $request->getMethod(),
            $request->getUri()->getPath(),
            is_array($query) ? $query : [],
            (string) $request->getBody(),
            $ts,
            $nonceKey
        );
        if (!hash_equals($expect, $headers['sign'])) {
            return ApiEnvelope::fail('签名无效', 401);
        }

        self::$seen[$nonceKey] = true;

        return $handler->handle($request);
    }

    /**
     * nonce 标记（true=首次见）：优先走共享缓存（file/redis 跨 worker），
     * 缓存不可用退化进程内存。file/add 非严格原子，同毫秒双交有理论窗口，
     * 强需求请换 redis SET NX 实现。
     */
    private function markNonce(string $nonce): bool
    {
        $key = 'nonce:' . $nonce;
        try {
            $store = cache();
            if (method_exists($store, 'add')) {
                return (bool) $store->add($key, 1, ApiSigner::WINDOW);
            }
            if (method_exists($store, 'has') && $store->has($key)) {
                return false;
            }
            if (method_exists($store, 'set')) {
                $store->set($key, 1, ApiSigner::WINDOW);
            }

            return true;
        } catch (\Throwable) {
            if (isset(self::$seen[$nonce])) {
                return false;
            }
            self::$seen[$nonce] = true;

            return true;
        }
    }

    private function sweep(): void
    {
        if (count(self::$seen) < 1000) {
            return;
        }
        // 惰性清理：量小直接按插入序丢一半（nonce 随机不可预测，重放窗口天然短）。
        self::$seen = array_slice(self::$seen, 500, null, true);
    }
}
