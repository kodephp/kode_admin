<?php

declare(strict_types=1);

namespace app\events;

/**
 * 演示事件
 */
final class PingEvent
{
    public function __construct(public readonly string $message)
    {
    }
}
