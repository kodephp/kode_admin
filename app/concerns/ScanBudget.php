<?php

declare(strict_types=1);

namespace app\concerns;

use Kode\Database\Db\Db;

/**
 * 「把整列 JSON 拉进 PHP 再聚合」这类扫描的唯一派发口。
 *
 * 为什么单独成一份：`kode_lowcode_workflow_executions.step_results_json` 是工作流诊断面板
 * 十几处聚合的共同数据源，而它的代价不是「数字偏小」是「worker 怎么死」。本机实测
 * （2026-09，73 行）：该列均值 1958 B、**最大 37801 B**，而 `json_decode(..., true)` 出来的
 * PHP 数组占的字节是 JSON 原文的 **8~9 倍**（同一行实测 37802 B → 356040 B）。
 * HTTP worker 的 `memory_limit` 是 128M，且 `Db::select()` 会把结果集**整个**取回内存，
 * 于是「一条没有行预算的 SELECT」只要窗口里有几万条执行记录就把 worker 撞成内存 fatal ——
 * 而这些入口的 `days` 上限各写一遍（365 / 90 / 30），传一个 `?days=365` 就能挑中最贵的那条路，
 * 一枚有效 token 即可（AGENTS.md §5：热路径的等待与代价必须有界）。
 *
 * 三件套一次给全，缺一个都不算收口：
 *  - `window_rows` —— **同一条 WHERE** 上的 SQL `COUNT(*)`，也就是「这个窗口真有多少行」；
 *  - `scanned_rows` —— 真正进 PHP 聚合的行数（抽样规模）；
 *  - `truncated` —— 前者大于后者。
 * 只回其中任何一件都会被读成另一件：少了 `truncated`，「窗口 12 万条只聚合 2000 条」和
 * 「一共就 2000 条」在页面上长得一样；少了 `window_rows`，用户无从知道分布覆盖了多少数据。
 *
 * 与 §4「跨分片聚合不许按行抽样」的分工：能下沉进 SQL 的聚合（计数、分组、百分位）必须下沉；
 * 留在 PHP 的那部分是**判据本身只在 PHP 有一份**（错误文本规范化、步骤树递归、URL 归一化），
 * 搬进 SQL 等于写第二份判据。这类扫描允许抽样，代价是必须自己把抽样规模说出来。
 */
trait ScanBudget
{
    /**
     * 展开型聚合单次最多取回多少行。
     *
     * 2000 行按实测均值是 ~4 MB 原文、按实测最大行是 ~74 MB —— 都在 128M 之内，
     * 而 2000 条执行记录足够让任何「Top N 步骤 / 错误模式 / 依赖健康」的分布收敛。
     * 与 `WorkflowBridgeController::WEBHOOK_SAMPLE_ROWS` 同量级，不是随手取的数。
     */
    private const STEP_ROWS = 2000;

    /**
     * 扫描一张表：先数窗口全集，再取有预算的那一批行。
     *
     * `$where` 必须是**完整的** `WHERE ...` 子句（含 `WHERE` 关键字），两条腿共用同一份
     * 字符串与同一份绑定 —— 各写一遍就会漂（这正是 webhookDlq 曾经「筛了 A 的列表、
     * 排行还数着 B」的成因）。
     *
     * `$rowBudget` 只能**调小**不能调大（内部一律 `min($rowBudget, STEP_ROWS)`）：
     * 有的入口按「每条展示项配 N 行」取样，但天花板必须由这一份判据统一给，
     * 否则「谁能传多大的 limit」又变成第二个预算。
     *
     * @param list<mixed> $params
     * @return array{rows: list<array>, window_rows: int, scanned_rows: int, truncated: bool}
     */
    private function scanBounded(string $table, string $select, string $where, array $params = [], string $order = '', int $rowBudget = self::STEP_ROWS): array
    {
        $budget = max(1, min(self::STEP_ROWS, $rowBudget));

        $counted = (array) (Db::select("SELECT COUNT(*) AS total FROM {$table} {$where}", $params)[0] ?? []);
        $window = (int) ($counted['total'] ?? 0);

        $rows = Db::select(
            "{$select} FROM {$table} {$where}" . ($order !== '' ? " {$order}" : '') . ' LIMIT ' . $budget,
            $params
        );
        $scanned = count($rows);

        return [
            'rows' => $rows,
            'window_rows' => $window,
            'scanned_rows' => $scanned,
            'truncated' => $window > $scanned,
        ];
    }

    /**
     * 工作流执行记录（step_results_json 那一族）的扫描：最常用的那条预设。
     *
     * @param list<mixed> $params
     * @return array{rows: list<array>, window_rows: int, scanned_rows: int, truncated: bool}
     */
    private function scanExecutions(string $select, string $where, array $params = [], string $order = 'ORDER BY id DESC', int $rowBudget = self::STEP_ROWS): array
    {
        return $this->scanBounded('kode_lowcode_workflow_executions', $select, $where, $params, $order, $rowBudget);
    }

    /**
     * 把扫描结果里的三个声明键搬进响应。
     *
     * 恒在：空窗口也要回 `window_rows: 0, scanned_rows: 0, truncated: false`，
     * 缺键与「老版本后端」在客户端无法区分，而面板按固定形状画图。
     *
     * @param array{window_rows: int, scanned_rows: int, truncated: bool} $scan
     * @return array{window_rows: int, scanned_rows: int, truncated: bool}
     */
    private static function scanDeclared(array $scan): array
    {
        return [
            'window_rows' => (int) $scan['window_rows'],
            'scanned_rows' => (int) $scan['scanned_rows'],
            'truncated' => (bool) $scan['truncated'],
        ];
    }
}
