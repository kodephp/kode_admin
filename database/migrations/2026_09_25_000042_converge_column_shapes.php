<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use app\support\SchemaShape;
use app\support\SysLog;

/**
 * 把存量库的列形状收敛到 `database/sql/schema.sql` 声明的形状（只收紧、不放宽）。
 *
 * 起因：`kode/database` 的建表器在 v1.25.0 之前**无法表达 NOT NULL**（`nullable` 选项
 * 被 `toSql()` 忽略，`setNullable(false)` 是空操作），所以凡是由 `Schema::create()`
 * 建出的列，在跑过迁移的库里一律「可空 + 无默认」；而同一张表在 schema.sql 与插件
 * install() 里写的是 `NOT NULL DEFAULT ...`。本机实测 44 列漂移，最坏的一条是
 * `kode_menus.plugin` —— 可空且没有默认值，于是任何一条不写这一列的 INSERT
 * 都会静默落进 NULL（库里就躺着一条 id=49 的 NULL 行），而读侧全按字符串用它。
 *
 * 三条口径：
 *  1. 只补 `SET DEFAULT` / `SET NOT NULL`，绝不 `DROP DEFAULT`、绝不放宽非空 ——
 *     放宽会把「新环境装出来的形状」改掉，那是回退而不是收敛；
 *  2. 有 NULL 数据的列**不动**，只进 blocked 报告。回填成什么是业务决定
 *     （例如 `kode_menus.plugin` 该回成 'system' 还是它真实归属的插件名），
 *     本迁移不替人做这个决定（AGENTS §4：破坏性入参禁止静默夹取）；
 *  3. 幂等：形状已经对上的列直接跳过，所以新环境（本来就没有漂移）跑它是空操作。
 *
 * 判据不在本文件里 —— 比较与 DDL 全部走 {@see SchemaShape::reconcile()}，
 * 只读诊断（`report()`）与本迁移共用同一条线。
 *
 * `down()` 是空操作：这里没有「收紧之前是什么形状」的账可回，
 * 而放宽一个本来就该非空的列不是回滚，是重新制造漂移。
 */
final class ConvergeColumnShapes extends Migration
{
    public function up(): void
    {
        $applied = 0;
        $blocked = [];
        foreach (SchemaShape::declaredTables() as $table) {
            $report = SchemaShape::reconcile($table, true);
            $applied += count($report['applied']);
            $blocked = array_merge($blocked, $report['blocked']);
        }

        if ($applied > 0 || $blocked !== []) {
            SysLog::info('schema', '列形状收敛：收紧 ' . $applied . ' 列，阻塞 ' . count($blocked) . ' 列', [
                'blocked' => $blocked,
            ]);
        }
    }

    public function down(): void
    {
        // 有意为空：见类注释。
    }
}
