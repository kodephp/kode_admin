<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\concerns\ApiResponse;
use Kode\Database\Database\Migrations\Migrator;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;

/**
 * 迁移管理：列出状态、执行迁移、回滚迁移。
 *
 * 复用框架 Migrator 引擎，将 CLI 能力接入后台管理界面。
 */
final class MigrationController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/migrations — 迁移状态总览。
     *
     * 返回已执行和待执行的迁移列表，以及批次信息。
     */
    public function status()
    {
        $applied = $this->appliedMigrations();
        if ($applied === null) {
            return $this->fail('迁移记录读取失败（migrations 表查询异常）', 500);
        }
        $pending = $this->pendingMigrations();

        $batches = [];
        foreach ($applied as $row) {
            $batches[$row['batch']][] = $row;
        }

        return $this->ok([
            'applied_count' => count($applied),
            'pending_count' => count($pending),
            'batches' => array_values($batches),
            'pending' => array_map(static fn(array $p): array => [
                'name' => $p['name'],
                'file' => $p['file'],
            ], $pending),
        ]);
    }

    /**
     * POST /api/migrations/run — 执行待运行迁移。
     *
     * 请求体：{ step?: int } — 省略（或留空）= 执行全部待运行；给了就必须是正整数。
     */
    public function run()
    {
        // 守卫必须在引擎之前：`Migrator::run()` 走的是 `array_slice($pending, 0, $steps)`，
        // 畸形 step 不是「报错」而是跑出另一件事（见 runStepFor()）。
        $verdict = self::runStepFor($this->input('step'));
        if ($verdict['error'] !== null) {
            return $this->fail($verdict['error'], 400);
        }

        $migrator = $this->migrator();

        try {
            $executed = $migrator->run($verdict['steps']);
        } catch (\Throwable $e) {
            return $this->fail('迁移执行失败：' . $e->getMessage(), 500);
        }

        if ($executed === []) {
            return $this->ok(['executed' => [], 'message' => '没有需要执行的迁移']);
        }

        return $this->ok([
            'executed' => $executed,
            'count' => count($executed),
            'message' => '已执行 ' . count($executed) . ' 个迁移',
        ]);
    }

    /**
     * POST /api/migrations/rollback — 回滚最近若干批迁移。
     *
     * 请求体：{ step?: int } — 回滚批次数（默认 1 批，允许 1..已执行的最大批次号）
     */
    public function rollback()
    {
        $applied = $this->appliedMigrations();
        if ($applied === null) {
            return $this->fail('迁移记录读取失败（migrations 表查询异常）', 500);
        }

        // 守卫必须在引擎之前：越界的 step 一旦被交给 Migrator，「回滚最近一批」就是一次
        // 全库 reset（见 stepsFor()），那种损失没有「重新点一次」的补救。
        $maxBatch = 0;
        foreach ($applied as $row) {
            $maxBatch = max($maxBatch, (int) $row['batch']);
        }
        $step = self::stepsFor($this->input('step'), $maxBatch);
        if ($step === null) {
            return $this->fail(
                $maxBatch < 1
                    ? 'step 非法：当前没有已执行的迁移批次可回滚'
                    : "step 必须是 1..{$maxBatch} 的整数；回滚全部请走 POST /api/migrations/reset",
                400
            );
        }

        $migrator = $this->migrator();

        try {
            $rolledBack = $migrator->rollback($step);
        } catch (\Throwable $e) {
            return $this->fail('回滚失败：' . $e->getMessage(), 500);
        }

        return $this->ok([
            'rolled_back' => $rolledBack,
            'count' => count($rolledBack),
            'message' => '已回滚 ' . count($rolledBack) . ' 个迁移',
        ]);
    }

    /**
     * 回滚步长的入参判据（纯函数，可离线断言；见 `tests/MigrationRollbackStepsTest`）。
     *
     * 越界一律拒，**不夹取**（AGENTS.md §4「破坏性入参禁止静默夹取」）。这里的代价不是
     * 「数字不对」而是「库没了」，因为框架侧 `Migrator::rollbackOnConnection()` 算的是
     * `range($maxBatch - $steps + 1, $maxBatch)` 再滤掉 `< 1`，于是三种坏输入都不报错：
     *  - `step` 大于批次总数 → 得到 `[1..maxBatch]`，等于 `reset()`，全库的 `down()` 挨个执行；
     *  - `step = 0` 或负数 → `range(7, 6)` 是**倒着数**的（实测 `[7, 6]`），过滤后恰好等价于
     *    「回滚 1 批」—— 用户写 0 想说的是「别动」，得到的是一次真实回滚；
     *  - `step` 极大 → 那条算式溢出成 float，`range()` 抛 `ValueError`，被 handler 的
     *    `catch (\Throwable)` 拼进 500 文案，内部异常文本原样回给客户端。
     * 「回滚全部」这个动作只属于 `POST /api/migrations/reset`，它有自己的确认文案与入口。
     *
     * @param mixed $raw 请求带来的原始值（JSON 里可能是 int、数字串、浮点、甚至 bool/数组）
     * @param int   $maxBatch 已执行迁移的最大批次号（0 = 一个批次都没有）
     * @return ?int 允许的回滚批次数；null 表示拒绝，调用方转 400
     */
    public static function stepsFor(mixed $raw, int $maxBatch): ?int
    {
        if ($maxBatch < 1) {
            return null;
        }
        // 缺省 = 回滚最近一批（管理页那个按钮现在的行为）。
        if ($raw === null || $raw === '') {
            return 1;
        }

        $steps = self::positiveStep($raw);

        return ($steps !== null && $steps <= $maxBatch) ? $steps : null;
    }

    /**
     * 执行步长的入参判据（纯函数；见 `tests/MigrationRunStepsTest`）。
     *
     * 与 `stepsFor()` 的差别只在**策略**，形状规则两边共用 `positiveStep()`：
     *  - 这里没有上界：`Migrator::run()` 走 `array_slice($pending, 0, $steps)`，
     *    step 大于待执行数就是「全部」，不会像回滚那样被算式放大成 reset；
     *  - 缺省是 `null` = 执行全部（一个合法语义），因此畸形值**不能**也回落成 null ——
     *    那等于把「参数写错了」执行成「把你所有待执行的迁移全跑掉」。
     *
     * 旧写法 `(int) $this->input('step')` 的三种坏输入（全部实测）：
     *  - `0`、`'abc'`、`false` → 0 → 一个都不跑，而 handler 回「没有需要执行的迁移」，
     *    与「确实全部已应用」在响应上完全一样；
     *  - `-1` → `array_slice` 砍掉最后一个 → **部分执行**，批次号照写，响应里没有任何提示；
     *  - `'2.9'` → 2、`true` → 1 → 静默截断成另一个步长。
     *
     * @return array{steps: null|int, error: null|string}
     */
    public static function runStepFor(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['steps' => null, 'error' => null];
        }

        $steps = self::positiveStep($raw);
        if ($steps === null) {
            return [
                'steps' => null,
                'error' => 'step 必须是正整数；不传或留空表示执行全部待运行迁移',
            ];
        }

        return ['steps' => $steps, 'error' => null];
    }

    /**
     * 「什么算一个正整数步长」只写这一处（bool 必须在数字判定之前挡掉：
     * `(int) true === 1` 会让 `{"step": true}` 被当成「就要跑一个」）。
     *
     * @return ?int null = 不是正整数（含 0、负数、非整数字面量、bool/数组/对象）
     */
    private static function positiveStep(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw >= 1 ? $raw : null;
        }
        if (is_float($raw)) {
            return (is_finite($raw) && $raw === floor($raw) && $raw >= 1.0 && $raw <= (float) PHP_INT_MAX)
                ? (int) $raw
                : null;
        }
        if (is_string($raw)) {
            // 只认非负整数字面量（表单发来的就是这种形状）；'1.5'、'abc'、'-2' 一律拒。
            $trimmed = trim($raw);
            if (preg_match('/^\d+$/', $trimmed) !== 1) {
                return null;
            }
            $steps = (int) $trimmed;

            return $steps >= 1 ? $steps : null;
        }

        return null;
    }

    /**
     * POST /api/migrations/reset — 回滚所有迁移。
     */
    public function reset()
    {
        $migrator = $this->migrator();

        try {
            $rolledBack = $migrator->reset();
        } catch (\Throwable $e) {
            return $this->fail('重置失败：' . $e->getMessage(), 500);
        }

        return $this->ok([
            'rolled_back' => $rolledBack,
            'count' => count($rolledBack),
            'message' => '已重置 ' . count($rolledBack) . ' 个迁移',
        ]);
    }

    // ─── 私有辅助 ───────────────────────────────────────────

    private function migrator(): Migrator
    {
        $path = dirname(__DIR__, 3) . '/database/migrations';
        return new Migrator($path);
    }

    /**
     * 获取已执行的迁移记录；查询真失败时返回 null（调用方转 500）。
     *
     * 这里原本写的是 `\Kode\Framework\Database\Db::table(...)`，而这个类不存在
     * （真实门面是 `Kode\Database\Db\Db`），配上传 `catch (\Throwable) { return []; }`
     * —— Error 也是 Throwable，于是「类找不到」被当成「没有已执行迁移」。
     * 实测本机 migrations 表明明有行、批次号也连号，接口照样回 applied_count:0
     * 且把全部迁移文件报成待执行，管理页一直显示「需要迁移」。
     * 失败不再吞成空数组：只有表确实不存在时才认定 0 条。
     *
     * @return ?list<array{name: string, batch: int, created_at: string}>
     */
    private function appliedMigrations(): ?array
    {
        // 表名与框架 Migrator::$table 默认值一致（本控制器不会 setTable）
        if (!Db::tableExists('migrations')) {
            return [];
        }
        try {
            $rows = Db::select(
                'SELECT migration, batch, created_at FROM migrations ORDER BY batch DESC, id DESC'
            );
        } catch (\Throwable) {
            return null;
        }

        return array_map(static fn(array $r): array => [
            'name' => (string) ($r['migration'] ?? ''),
            'batch' => (int) ($r['batch'] ?? 0),
            'created_at' => (string) ($r['created_at'] ?? ''),
        ], $rows);
    }

    /**
     * 获取待执行的迁移文件列表。
     *
     * @return list<array{name: string, file: string}>
     */
    private function pendingMigrations(): array
    {
        $path = dirname(__DIR__, 3) . '/database/migrations';
        $files = glob($path . '/*.php') ?: [];
        $appliedNames = array_column($this->appliedMigrations() ?? [], 'name');

        $pending = [];
        foreach ($files as $file) {
            $basename = basename($file, '.php');
            if (!in_array($basename, $appliedNames, true)) {
                $pending[] = ['name' => $basename, 'file' => $basename . '.php'];
            }
        }

        usort($pending, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $pending;
    }
}