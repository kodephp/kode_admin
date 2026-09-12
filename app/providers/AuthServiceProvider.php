<?php

declare(strict_types=1);

namespace app\providers;

use app\auth\EncryptedJwtGuard;
use Kode\Framework\Auth\JwtGuard;
use Kode\Framework\Contracts\AuthGuard;
use Kode\Framework\Providers\ServiceProvider;

/**
 * 鉴权替换：AuthGuard / jwt / JwtGuard 统一换成业务声明加密版。
 *
 * 对业务透明（AuthMiddleware 只依赖 AuthGuard 契约）。
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(EncryptedJwtGuard::class, function (): EncryptedJwtGuard {
            return new EncryptedJwtGuard((array) $this->config('jwt', []));
        });

        $guard = fn(): EncryptedJwtGuard => $this->container->get(EncryptedJwtGuard::class);
        $this->container->instance(AuthGuard::class, $guard());
        $this->container->instance('jwt', $guard());
        $this->container->instance(JwtGuard::class, $guard());
    }
}
