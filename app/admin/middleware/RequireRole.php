<?php

declare(strict_types=1);

namespace app\admin\middleware;

use Kode\Http\Request;
use Kode\Framework\Http\Resp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 角色门禁：挂在 AuthMiddleware 内层，要求 JWT 载荷 roles 命中其一。
 *
 * 用法：->middleware([new AuthMiddleware(), new RequireRole('admin')])
 */
final class RequireRole implements MiddlewareInterface
{
    /** @var list<string> */
    private array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = $roles;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $payload = Request::attr('auth');
        $has = $payload !== null ? (array) ($payload->roles ?? []) : [];

        foreach ($this->roles as $role) {
            if (in_array($role, $has, true)) {
                return $handler->handle($request);
            }
        }

        return Resp::error('权限不足', 403);
    }
}
