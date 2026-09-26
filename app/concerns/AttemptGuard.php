<?php

declare(strict_types=1);

namespace app\concerns;

use app\support\ClientIp;

/**
 * 失败/尝试计数闸门（匿名与自助入口共用）。
 *
 * 为什么单独成一份：这条线原先只有 `AuthController` 里的三个私有方法，
 * 每多一个「不验身份就能写库」的入口（登录、改密、C 端自助注册）就多一份
 * 「要不要计数、按什么键计数、预算多少」的临时决定。判据写两份时，
 * 迟早只改对一个 —— 与 `RetentionGuard` 同一个理由。
 *
 * 键形状是**两条**：IP 与身份各一份，任一到预算即限流。
 * 只按 IP 会被 NAT/机房出口绕过（同一账号换 IP 慢爆），
 * 只按身份则由攻击者替全员锁死账号（拒绝服务）。
 *
 * 计数走 `cache()`，与业务库无关：缓存不可用时**放行**（宁可不限流，
 * 也不把 redis 故障升级成全站登录不进去）。
 */
trait AttemptGuard
{
    /** 默认预算（可被 `kode_sys_configs` 的 auth.login_max_attempts 覆盖）。 */
    private const MAX_ATTEMPTS = 10;

    /** 默认窗口秒数（可被 auth.login_decay_seconds 覆盖）。 */
    private const DECAY_SECONDS = 600;

    /** 登录入口的计数前缀（历史键名，改它等于清空现有预算）。 */
    private const PREFIX_LOGIN = 'login_fail';

    /** 改密入口（原密码是口令 oracle，预算与登录同源）。 */
    private const PREFIX_PWD = 'pwd_fail';

    /** C 端自助注册：计的是**尝试**而不是失败，成功建号也要 +1（否则预算等于没有）。 */
    private const PREFIX_REGISTER = 'register';

    /** 计数：一次尝试/失败给两条键各 +1，窗口内累计。 */
    protected function hitAttempt(string $prefix, string $ip, string $identity): void
    {
        try {
            foreach ($this->attemptKeys($prefix, $ip, $identity) as $key) {
                // 读-改-写不是原子的，但这里的预算是「量级」而不是「精确次数」：
                // 并发少计一两次不影响挡爆破，换来的是不依赖 redis 专属动词。
                cache()->set($key, (int) cache()->get($key, 0) + 1, self::decaySeconds());
            }
        } catch (\Throwable) {
        }
    }

    /** 到预算了吗？（缓存不可用一律视为「没到」，见类注释） */
    protected function tooManyAttempts(string $prefix, string $ip, string $identity): bool
    {
        try {
            $limit = self::maxAttempts();
            foreach ($this->attemptKeys($prefix, $ip, $identity) as $key) {
                if ((int) cache()->get($key, 0) >= $limit) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    /** 作废预算（登录成功、改密成功后调用，别让上一次失败跟着人走）。 */
    protected function clearAttempts(string $prefix, string $ip, string $identity): void
    {
        try {
            foreach ($this->attemptKeys($prefix, $ip, $identity) as $key) {
                cache()->delete($key);
            }
        } catch (\Throwable) {
        }
    }

    /** 请求方 IP（口径见 `app\support\ClientIp`，计数键要的非空回落留在这一层）。 */
    protected function clientIp(string $fallback = 'unknown'): string
    {
        return ClientIp::resolve($fallback);
    }

    /** @return list<string> */
    private function attemptKeys(string $prefix, string $ip, string $identity): array
    {
        return ["{$prefix}_ip:{$ip}", "{$prefix}_user:{$identity}"];
    }

    private static function maxAttempts(): int
    {
        return \app\support\SysConfig::getInt('auth.login_max_attempts', self::MAX_ATTEMPTS, 3, 100);
    }

    private static function decaySeconds(): int
    {
        return \app\support\SysConfig::getInt('auth.login_decay_seconds', self::DECAY_SECONDS, 60, 3600);
    }
}
