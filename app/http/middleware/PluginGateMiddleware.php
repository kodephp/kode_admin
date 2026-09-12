<?php

declare(strict_types=1);

namespace app\http\middleware;

use app\support\ApiEnvelope;
use app\support\PluginMarket;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 插件运行时门禁（全局，挂 AuthMiddleware 之外、计时之内均可）：
 * 命中 `/api/<name>/*` 且该插件状态非 enabled 时直接 404。
 *
 * 核心路由（/api/users 等）与未声明插件前缀的路径不受影响；
 * 状态读 redis 缓存（300s），开关即时生效，无需重启。
 */
final class PluginGateMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (preg_match('#^/api/([a-z0-9_]{2,32})(?:/|$)#', $path, $m)) {
            $discovered = PluginMarket::discover();
            $name = $m[1];
            if (isset($discovered[$name])) {
                $status = PluginMarket::statusOf($name);
                // 有安装行且非 enabled → 拦截；无安装行视为 enabled（兼容旧行为）。
                if ($status !== null && $status !== PluginMarket::ENABLED) {
                    return ApiEnvelope::fail(
                        $status === PluginMarket::PAUSED ? '插件已暂停' : '插件未安装',
                        404
                    );
                }
            }
        }

        return $handler->handle($request);
    }
}
