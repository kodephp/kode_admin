<?php

declare(strict_types=1);

namespace app\auth;

use Kode\Framework\Auth\JwtGuard;
use Kode\Framework\Contracts\AuthGuard;
use Kode\Jwt\Token\Payload;

/**
 * 业务声明二次加密的 JWT 守卫（实现 AuthGuard，对业务透明）。
 *
 * 标准 JWT 载荷只是 base64，任何人解开即见 username/roles 等业务数据。本守卫签发时把
 * 除 uid 外的业务声明经 AES-256-GCM 加密后装入 `enc`；uid 本体以 `u_` + HashId 不透明串
 * 落 token 明文（顺序数字永不出现，可防枚举；KodeJwt 只要求非空字符串即可）。
 * 验签通过后解密并比对外层 uid 与密文内 uid（不一致即拼凑攻击），再重建完整 Payload。
 * 篡改密文/身份不一致 → 抛异常 → 中间件 401；密钥由 JWT_SECRET 经 HKDF 派生。
 */
final class EncryptedJwtGuard implements AuthGuard
{
    /** @var array<string, mixed> */
    private array $config;

    private JwtGuard $inner;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->inner = new JwtGuard($config);
    }

    public function issue(array $claims, string $guard = 'api'): string
    {
        $guardConfig = $this->guardConfig($guard);
        $ttl = (int) ($guardConfig['ttl'] ?? 3600);
        $iat = (int) ($claims['iat'] ?? time());

        $plain = [
            'uid' => isset($claims['uid']) ? (int) $claims['uid'] : null,
            'username' => $claims['username'] ?? null,
            'roles' => $claims['roles'] ?? null,
            'perms' => $claims['perms'] ?? null,
            'custom' => $claims['custom'] ?? [],
            'sub' => $claims['sub'] ?? $claims['subject'] ?? null,
        ];

        return $this->inner->issue([
            // 外层 uid 放不透明串（u_ + HashId），顺序数字永不落地 token；
            // KodeJwt 要求 uid 非空，字符串合法，验签只认签名不认格式。
            'uid' => $plain['uid'] === null ? null : self::outerUid($plain['uid']),
            'platform' => $claims['platform'] ?? $guardConfig['platform'] ?? 'default',
            'exp' => $claims['exp'] ?? ($iat + $ttl),
            'iat' => $iat,
            'jti' => $claims['jti'] ?? null,
            'nonce' => $claims['nonce'] ?? null,
            'aud' => $claims['aud'] ?? $claims['audience'] ?? null,
            'iss' => $claims['iss'] ?? $claims['issuer'] ?? null,
            'custom' => ['enc' => $this->encrypt($plain, (string) ($guardConfig['secret'] ?? ''))],
        ], $guard);
    }

    public function authenticate(string $token, string $guard = 'api'): Payload
    {
        // 先走标准验签（签名/过期/jti/黑名单），不通过直接抛。
        $outer = $this->inner->authenticate($token, $guard);

        $enc = is_array($outer->custom) ? ($outer->custom['enc'] ?? null) : null;
        if (!is_string($enc) || $enc === '') {
            throw new \RuntimeException('令牌缺失加密载荷');
        }

        $plain = $this->decrypt($enc, (string) ($this->guardConfig($guard)['secret'] ?? ''));
        if (!is_array($plain)) {
            throw new \RuntimeException('令牌载荷解密失败');
        }

        // 外层 uid 必须是不透明串并与密文内 uid 一致，否则拒绝
        //（防“明文 uid + 伪造 enc”拼凑；旧数字 uid 直接 fail-closed，需重登）。
        $uid = self::parseOuterUid((string) ($outer->uid ?? ''));
        if ($uid === null || (int) ($plain['uid'] ?? -1) !== $uid) {
            throw new \RuntimeException('令牌身份不一致');
        }

        return new Payload(
            uid: $uid,
            username: $plain['username'] ?? null,
            platform: $outer->platform,
            exp: $outer->exp,
            iat: $outer->iat,
            jti: $outer->jti,
            roles: $plain['roles'] ?? null,
            perms: $plain['perms'] ?? null,
            custom: is_array($plain['custom'] ?? null) ? $plain['custom'] : [],
            nonce: $outer->nonce,
            audience: $outer->audience,
            issuer: $outer->issuer,
            subject: $plain['sub'] ?? null,
        );
    }

    public function refresh(string $token, string $guard = 'api'): array
    {
        // 基类 refresh 基于旧 token 明文载荷重签，会丢失 enc 业务声明；
        // 此处先解密还原完整身份再重签，并作废旧 token（防双 token 并存）。
        $payload = $this->authenticate($token, $guard);
        $guardConfig = $this->guardConfig($guard);
        $ttl = (int) ($guardConfig['ttl'] ?? 3600);
        $iat = time();

        $new = $this->issue([
            'uid' => $payload->uid,
            'username' => $payload->username,
            'roles' => $payload->roles,
            'perms' => $payload->perms,
            'custom' => $payload->custom,
            'platform' => $payload->platform,
            'subject' => $payload->subject,
            'aud' => $payload->audience,
            'iss' => $payload->issuer,
        ], $guard);
        try {
            $this->inner->invalidate($token, $guard);
        } catch (\Throwable) {
        }

        return ['token' => $new, 'exp' => $iat + $ttl];
    }

    public function invalidate(string $token, string $guard = 'api'): bool
    {
        return $this->inner->invalidate($token, $guard);
    }

    public function revokeToken(string $token, string $guard = 'api'): bool
    {
        return $this->inner->revokeToken($token, $guard);
    }

    public function isBlacklisted(string $jti, string $guard = 'api'): bool
    {
        return $this->inner->isBlacklisted($jti, $guard);
    }

    public function unblacklist(string $jti, string $guard = 'api'): bool
    {
        return $this->inner->unblacklist($jti, $guard);
    }

    /**
     * 强制下线：撤销某用户的全部令牌，返回撤销条数。
     *
     * 令牌里落地的是 `u_` 不透明 uid，用户活跃 Token 列表的键也由它拼成
     * （`user:u_xxx:web:tokens`）。直接把数据库自增 id 传进去只会查到一把不存在的键，
     * 撤销恒返回 0 且毫无报错——所以这里负责把内部 id 换成外层串。
     *
     * @param int|string $uid      内部自增 id，或已是外层不透明串
     * @param string|null $platform 限定平台；null 表示 config(jwt.platforms) 里的全部平台
     *
     * @throws \InvalidArgumentException uid 两种形态都对不上时（宁可报错也不要静默返回 0）
     */
    public function revokeUserTokens(int|string $uid, ?string $platform = null, string $guard = 'api'): int
    {
        $outer = match (true) {
            is_int($uid), ctype_digit($uid) => self::outerUid((int) $uid),
            str_starts_with($uid, 'u_') => $uid,
            default => throw new \InvalidArgumentException('无法识别的用户标识，拒绝撤销（避免空转返回 0）'),
        };

        return $this->inner->revokeUserTokens($outer, $platform, $guard);
    }

    private function encKey(string $secret): string
    {
        return hash_hkdf('sha256', $secret, 32, 'kode-jwt-enc-v1');
    }

    /**
     * 内部 id → 对外不透明串（u_ 前缀 + HashId）。
     */
    public static function outerUid(int $uid): string
    {
        return 'u_' . \app\support\HashId::enc($uid);
    }

    /**
     * 对外不透明串 → 内部 id；格式非法返回 null（调用方转 401）。
     */
    public static function parseOuterUid(string $outerUid): ?int
    {
        if (!str_starts_with($outerUid, 'u_')) {
            return null;
        }

        return \app\support\HashId::dec(substr($outerUid, 2));
    }

    /** @param array<string, mixed> $plain */
    private function encrypt(array $plain, string $secret): string
    {
        $iv = random_bytes(12);
        $ct = openssl_encrypt(
            (string) json_encode($plain),
            'aes-256-gcm',
            $this->encKey($secret),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($ct === false) {
            throw new \RuntimeException('令牌载荷加密失败');
        }

        return base64_encode($iv . $tag . $ct);
    }

    /** @return array<string, mixed> */
    private function decrypt(string $enc, string $secret): array
    {
        $raw = base64_decode($enc, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('令牌载荷解密失败');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $pt = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            $this->encKey($secret),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($pt === false) {
            throw new \RuntimeException('令牌载荷解密失败');
        }
        $data = json_decode($pt, true);

        if (!is_array($data)) {
            throw new \RuntimeException('令牌载荷解密失败');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function guardConfig(string $guard): array
    {
        $guards = is_array($this->config['guards'] ?? null) ? $this->config['guards'] : [];
        if (!isset($guards[$guard]) || !is_array($guards[$guard])) {
            throw new \InvalidArgumentException(
                "未知 JWT 守卫：{$guard}（请在 config/jwt.php 的 guards 中声明）"
            );
        }

        return $guards[$guard];
    }
}
