<?php

declare(strict_types=1);

namespace app\support;

/**
 * API 请求签名（淘宝 MTOP 式简化版）：防篡改 + 防重放，不加密 body。
 *
 * 签名内容（防 canonicalization 坑：body 取原始字符串，不解析重排）：
 *  METHOD + "\n" + PATH + "\n" + 排序后查询串 + "\n" + BODY原文 + "\n" + TIMESTAMP + "\n" + NONCE
 * 签名值 = HMAC-SHA256(secret) hex。
 *
 * 客户端请求头：X-Api-Key / X-Api-Timestamp（秒） / X-Api-Nonce / X-Api-Sign。
 * 服务端校验：时间窗 ±300s + nonce 未重复使用 + hash_equals 比对。
 */
final class ApiSigner
{
    public const WINDOW = 300;

    /**
     * @param array<string, string> $query 查询参数（未排序也可，内部排序）
     */
    public static function sign(
        string $secret,
        string $method,
        string $path,
        array $query,
        string $body,
        int $timestamp,
        string $nonce
    ): string {
        ksort($query);
        $payload = implode("\n", [
            strtoupper($method),
            $path,
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            $body,
            (string) $timestamp,
            $nonce,
        ]);

        return hash_hmac('sha256', $payload, $secret);
    }
}
