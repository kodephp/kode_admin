<?php

declare(strict_types=1);

namespace app\support;

use Kode\Framework\Http\Resp;
use Kode\Http\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * 统一响应信封（短字段）：
 *  - 成功：{code:HTTP状态(默认200), msg, dur, data?}
 *  - 失败：{code:HTTP状态, msg, dur, errors?}
 *  - 分页：在成功体同级加 total/page/size，data 为当页数组。
 *  - dur：请求处理耗时，如 "0.0003s"（取 TimingMiddleware 打的 _t0）。
 *
 * 常见 code/msg（开发者可按业务替换增改，code 恒等于 HTTP 状态）：
 *  200 ok · 201 已创建 · 400 参数错误 · 401 未认证 · 403 权限不足 ·
 *  404 不存在 · 409 已存在 · 422 参数校验失败 · 429 过于频繁 · 500 服务器错误
 */
final class ApiEnvelope
{
    public static function duration(?float $t0 = null): string
    {
        $t0 ??= (float) Request::attr('_t0', microtime(true));

        return sprintf('%.4fs', max(0, microtime(true) - $t0));
    }

    /**
     * @param mixed $data 数据（列表或其他）
     * @param array<string, mixed> $extra 同级附加字段（如 total/page/size）
     */
    public static function ok(mixed $data = null, ?float $t0 = null, array $extra = [], int $status = 200): ResponseInterface
    {
        $body = ['code' => $status, 'msg' => 'ok', 'dur' => self::duration($t0)];
        if ($data !== null) {
            $body['data'] = $data;
        }

        return Resp::json($body + $extra, $status);
    }

    /**
     * @param array<string, mixed> $extra 同级附加字段（如 errors）
     */
    public static function fail(string $msg, int $status = 400, ?float $t0 = null, array $extra = []): ResponseInterface
    {
        $body = ['code' => $status, 'msg' => $msg, 'dur' => self::duration($t0)] + $extra;

        return Resp::json($body, $status);
    }

    /**
     * 分页信封：{code, msg, dur, data, total, page, size} + 调用方补充的同级键。
     *
     * $extra 是给「这一页只是部分口径」这类元信息的落点（如 unreadable_shards）：
     * 它们与 total/page/size 同级、恒在，缺键会让客户端分不清「老后端」与「一切正常」。
     *
     * @param list<mixed> $items
     * @param array<string, mixed> $extra
     */
    public static function page(array $items, int $total, int $page, int $size, array $extra = [], ?float $t0 = null): ResponseInterface
    {
        return self::ok($items, $t0, ['total' => $total, 'page' => $page, 'size' => $size] + $extra);
    }
}
