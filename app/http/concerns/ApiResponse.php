<?php

declare(strict_types=1);

namespace app\http\concerns;

use app\support\ApiEnvelope;
use Psr\Http\Message\ResponseInterface;

/**
 * 统一信封快捷方法（项目规范，见 AGENTS.md §3）：
 *  - ok($data)：{code:HTTP状态, msg, dur, data}
 *  - fail($msg, $status)：{code, msg, dur, errors}
 *  - page($items, $total, $page, $size)：分页信封。
 */
trait ApiResponse
{
    protected function ok(mixed $data = null, int $status = 200, array $extra = []): ResponseInterface
    {
        return ApiEnvelope::ok($data, null, $extra, $status);
    }

    protected function fail(string $msg, int $status = 400, array $extra = []): ResponseInterface
    {
        return ApiEnvelope::fail($msg, $status, null, $extra);
    }

    /** @param list<mixed> $items */
    protected function page(array $items, int $total, int $page, int $size): ResponseInterface
    {
        return ApiEnvelope::page($items, $total, $page, $size);
    }

    /**
     * 路由对外 ID（HashId）解码为内部自增 id，非法返回 null（调用方转 404）。
     * 对外永不暴露自增 id（防枚举），见 AGENTS.md §4。
     */
    protected function eid(string $name = 'id'): ?int
    {
        return \app\support\HashId::dec((string) $this->param($name));
    }
}
