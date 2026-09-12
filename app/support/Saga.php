<?php

declare(strict_types=1);

namespace app\support;

/**
 * Saga 分布式事务协调器（跨库/跨片写操作的最终一致性）。
 *
 * 不用 XA/2PC（长事务锁 + 恢复复杂）：每步本地事务提交，失败按逆序执行补偿。
 * 幂等要求：do/undo 必须可重入（补偿可能因进程崩溃被运维手动补调）。
 * 强一致场景请走单库事务；本协调器适用于跨片业务（如 A 片扣减 + B 片落单）。
 */
final class Saga
{
    /**
     * @param list<array{name: string, do: callable, undo?: callable}> $steps
     * @return list<mixed> 各步返回值
     * @throws SagaFailedException 失败时（已补偿，内含 trail）
     */
    public static function run(array $steps): array
    {
        $done = [];
        $results = [];
        try {
            foreach ($steps as $i => $step) {
                $results[$i] = ($step['do'])();
                $done[] = $step;
            }

            return $results;
        } catch (\Throwable $e) {
            $trail = [];
            foreach (array_reverse($done) as $step) {
                $name = (string) ($step['name'] ?? '?');
                try {
                    if (isset($step['undo']) && is_callable($step['undo'])) {
                        ($step['undo'])();
                    }
                    $trail[] = "{$name}:compensated";
                } catch (\Throwable $ce) {
                    // 补偿失败必须人工介入：记录 trail 后继续补后面步骤，绝不中断。
                    $trail[] = "{$name}:COMPENSATION_FAILED:" . $ce->getMessage();
                    try {
                        logger()->error("Saga 补偿失败：{$name}", ['error' => $ce->getMessage()]);
                    } catch (\Throwable) {
                    }
                }
            }

            throw new SagaFailedException($e->getMessage(), $trail, $e);
        }
    }
}
