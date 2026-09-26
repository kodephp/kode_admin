<?php

declare(strict_types=1);

namespace app\support;

use Kode\Http\Request;

/**
 * 请求方 IP 的唯一口径。
 *
 * 「要不要采信转发头」由 `security.trusted_proxies` 决定，这个判定此前散在 5 处各写一遍
 * （AuditMiddleware、FeatureFlagController、AuthController 的登录日志、SysLog、AttemptGuard）。
 * 少写一份参数的两种后果都不响亮：不传参数 = 永远不采信转发头，真实来路全记成代理机 IP
 * （登录计数变成「一台代理锁住全站账号」）；随手采信 = 客户端自己写 X-Forwarded-For，
 * 审计与限流里的身份想是谁就是谁。
 */
final class ClientIp
{
    /**
     * 当前请求的来路 IP；取不到时回落到 $fallback。
     *
     * 落库列（审计、登录日志）传空串，计数键要传 'unknown'：空串会让多个「未知来源」
     * 共用同一份失败预算。
     */
    public static function resolve(string $fallback = ''): string
    {
        return (string) (Request::ip((array) config('security.trusted_proxies', [])) ?? $fallback);
    }
}
