<?php

declare(strict_types=1);

namespace Kode\Plugins\demo;

final class DemoService
{
    public function greet(): string
    {
        return 'hello from demo plugin';
    }
}
