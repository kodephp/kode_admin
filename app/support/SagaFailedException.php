<?php

declare(strict_types=1);

namespace app\support;

/**
 * Saga 失败异常（携带已执行的补偿轨迹，供运维核对/手工补调）。
 */
final class SagaFailedException extends \RuntimeException
{
    /** @param list<string> $trail */
    public function __construct(string $message, private readonly array $trail, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @return list<string> */
    public function trail(): array
    {
        return $this->trail;
    }
}
