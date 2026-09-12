<?php

declare(strict_types=1);

namespace app\services;

/**
 * 演示服务：用于展示 AOP 织入（kode/aop）。
 *
 * 注意：AOP 代理会生成「继承此类」的子类，因此目标类不能被声明为 final。
 */
class Greeter
{
    public function hello(string $name): string
    {
        return "Hello, {$name}!";
    }
}
