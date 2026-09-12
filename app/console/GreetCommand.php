<?php

declare(strict_types=1);

namespace app\console;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;

/**
 * 示例命令：php bin/console greet Kode --shout
 */
#[AsCommand(name: 'greet', description: '向指定的人打招呼', usage: 'greet {name?} {--shout:bool}')]
final class GreetCommand extends Command
{
    protected function handle(): int
    {
        $name = $this->arg('name') ?? 'World';
        $message = $this->flag('shout') ? strtoupper("Hello, {$name}!") : "Hello, {$name}!";

        $this->info($message);

        return 0;
    }
}
