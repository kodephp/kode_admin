<?php

declare(strict_types=1);

namespace app\middleware;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Doctrine UnitOfWork 收口：响应产出后 clear()，防长驻进程身份映射堆积。
 *
 * 挂载见 app/bootstrap.php（全局最内层，异常时 finally 照常执行）。
 */
final class DoctrineClearMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } finally {
            try {
                resolve(EntityManagerInterface::class)->clear();
            } catch (\Throwable) {
                // EM 未初始化等极端情况不阻断主流程。
            }
        }
    }
}
