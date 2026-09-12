<?php

declare(strict_types=1);

namespace app\aop;

use Kode\Aop\Attribute\Aspect;
use Kode\Aop\Attribute\Before;
use Kode\Aop\Runtime\JoinPoint;

/**
 * 日志切面示例
 *
 * 在 \app\services\Greeter::hello 执行前打印日志。
 * 通过 Aop::register($this) 注册，并以 Aop::proxy(Greeter::class) 获取代理对象后生效。
 */
#[Aspect]
final class LogAspect
{
    #[Before('execution(* \app\services\Greeter->hello(..))')]
    public function beforeHello(JoinPoint $joinPoint): void
    {
        logger()->info(sprintf(
            'AOP[before] %s::%s args=%s',
            $joinPoint->getClassName(),
            $joinPoint->getMethodName(),
            json_encode($joinPoint->getArguments(), JSON_UNESCAPED_UNICODE)
        ));
    }
}
