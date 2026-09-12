<?php

declare(strict_types=1);

namespace app\admin\http\middleware;

use app\support\RolePerms;
use Kode\Http\Request;
use Kode\Framework\Http\Resp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 权限码门禁：挂在 AuthMiddleware 内层，要求当前用户 perms 命中其一。
 *
 * 为什么需要：插件 `permissions()` 声明的权限码进入权限目录供角色勾选，
 * 但路由只挂了 AuthMiddleware（登录门禁），权限码从未在运行时校验——
 * 任何已登录用户都能调 delete/update，属声明⟺实现漂移。本中间件补齐消费端。
 *
 * 用法：
 *   ->middleware([new AuthMiddleware(), new RequirePerm('notices:delete')])
 *   ->middleware([new AuthMiddleware(), new RequirePerm('notices:update', 'notices:delete')])
 *
 * 旁路：admin 角色 / uid=1 拥有全部权限码，自动放行。
 * 设计取舍：不走 RequireRole（角色≠权限码），直接查 RolePerms::forUser()
 * 的 perms 并集——与前端 v-access / authCode 比对用的是同一份数据源。
 */
final class RequirePerm implements MiddlewareInterface
{
    /** @var list<string> */
    private array $perms;

    public function __construct(string ...$perms)
    {
        $this->perms = $perms;
    }

    /**
     * 本门禁要求的权限码（任一命中即放行）。
     *
     * 供契约审计只读取用：`PluginContract::auditPermGates()` 遍历路由时
     * 需要拿到实际挂门禁的码，否则「门禁码未声明」的漂移无从发现。
     *
     * @return list<string>
     */
    public function perms(): array
    {
        return $this->perms;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $payload = Request::attr('auth');
        if ($payload === null) {
            return Resp::error('未认证', 401);
        }

        $uid = (int) ($payload->uid ?? 0);

        // 超管旁路：admin 角色或 uid=1
        $roles = (array) ($payload->roles ?? []);
        if (in_array('admin', $roles, true) || $uid === 1) {
            return $handler->handle($request);
        }

        $userPerms = RolePerms::forUser($uid)['perms'] ?? [];

        foreach ($this->perms as $code) {
            if (in_array($code, $userPerms, true)) {
                return $handler->handle($request);
            }
        }

        return Resp::error('权限不足', 403);
    }
}
