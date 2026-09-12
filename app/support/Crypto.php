<?php

declare(strict_types=1);

namespace app\support;

/**
 * 敏感字段加解密（AES-256-GCM，随即 IV，篡改即失败）。
 *
 * 适用：身份证号/手机号等 PII 落库前加密、敏感配置下发。URL/查询串/普通 body
 * 不要加密（破坏缓存/日志/WAF/REST 语义，且 TLS 已保护传输）；主键 id 保持明文
 * （鉴权与审计依赖，安全靠鉴权不靠混淆）。实测 82B 载荷加解密往返约 1.5µs。
 *
 * 密钥：CRYPTO_KEY（32 字节，缺省由 JWT_SECRET 经 HKDF 派生；轮换后旧数据不可解）。
 */
final class Crypto
{
    public static function key(): string
    {
        $raw = (string) (env('CRYPTO_KEY', '') ?: env('JWT_SECRET', ''));
        if ($raw === '') {
            throw new \RuntimeException('加解密密钥未配置：请设置 CRYPTO_KEY 或 JWT_SECRET');
        }

        return hash_hkdf('sha256', $raw, 32, 'kode-field-enc-v1');
    }

    /** @return string base64(iv|tag|ct) */
    public static function enc(string $plain): string
    {
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('字段加密失败');
        }

        return base64_encode($iv . $tag . $ct);
    }

    public static function dec(string $enc): string
    {
        $raw = base64_decode($enc, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('字段解密失败');
        }
        $pt = openssl_decrypt(
            substr($raw, 28), 'aes-256-gcm', self::key(),
            OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)
        );
        if ($pt === false) {
            throw new \RuntimeException('字段解密失败');
        }

        return $pt;
    }
}
