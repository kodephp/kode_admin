<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;

/**
 * 树形表「上级 id」的解析与成环判定（菜单树 / 部门树共用）。
 *
 * 为什么只留一份：`MenuController` 原本自己写了 `resolveParent()` + `isDescendant()`，
 * 而 `DeptController::update()` 压根没有上级这一条腿（`store()` 另写了一份内联判定）。
 * 把部门那条链接上时若照抄一份，就是第三份「什么算合法上级」的实现 —— 而这三张表
 * 迟早还要各自长（新增/编辑/批量移动），各写一遍必然分岔。
 *
 * 两种「不行」分开点名，因为它们的处置完全不同：
 *  - `UNKNOWN`：eid 解不开、或库里没有这一行。多半是前端拿到了过期/别处的 id，
 *    调用方转 404，**绝不能当成「挪到顶级」**（一次猜错的 eid 把节点拍平到根上，
 *    用户看到的是「我的部门不见了」而不是报错）。
 *  - `CYCLE`：上级是自己或自己的子孙。树形渲染只从 `parent_id = 0` 出发往下走，
 *    成环的那一枝会**整枝从列表里消失**（数据还在、接口还在，但没人能点进去，
 *    只能改库救回来），所以必须拒。
 */
final class TreeParent
{
    public const OK = 'ok';

    /** eid 非法或库里没有这一行。 */
    public const UNKNOWN = 'unknown';

    /** 挂到自己或自己的子孙下面。 */
    public const CYCLE = 'cycle';

    /** 上溯的最大层数（远超任何真实树深，只用来兜住「库里已经有环」时的死循环）。 */
    private const MAX_DEPTH = 100;

    /**
     * 把请求带来的 eid 解析成内部 parent_id。
     *
     * `$selfId` 只在编辑时传（新建传 0）：它是环检测的终点，也是「上级不能是自己」的比对对象。
     *
     * @return array{verdict: string, parent_id: int}
     */
    public static function resolve(string $table, mixed $raw, int $selfId = 0): array
    {
        // 顶级在表单里的形状：清空选择框（''、null）或显式 0。
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return ['verdict' => self::OK, 'parent_id' => 0];
        }
        // 数组/对象一类的形状不是任何真实客户端会发的，直接算非法而不是 (string) 强转后
        // 拿着 "Array" 去解码：那条路的报错信息会把人指向「eid 过期」。
        if (!is_scalar($raw)) {
            return ['verdict' => self::UNKNOWN, 'parent_id' => 0];
        }

        $id = HashId::dec((string) $raw);
        if ($id === null || $id <= 0) {
            return ['verdict' => self::UNKNOWN, 'parent_id' => 0];
        }
        if ($id === $selfId) {
            return ['verdict' => self::CYCLE, 'parent_id' => $id];
        }
        if (Db::select("SELECT id FROM {$table} WHERE id = ? LIMIT 1", [$id]) === []) {
            return ['verdict' => self::UNKNOWN, 'parent_id' => $id];
        }

        return self::isDescendant($table, $id, $selfId)
            ? ['verdict' => self::CYCLE, 'parent_id' => $id]
            : ['verdict' => self::OK, 'parent_id' => $id];
    }

    /**
     * 两种「不行」的响应口径（状态码只在这里定一次）。
     *
     * 每张树各自写一遍映射，迟早出现「部门成环回 400、菜单成环回 404」这种分岔 ——
     * 而同一种坏法在前端只有一条错误提示能显示。
     *
     * @param array{verdict: string, parent_id: int} $resolved
     * @return ?array{status: int, msg: string} 判定通过时返回 null
     */
    public static function failureFor(array $resolved, string $noun): ?array
    {
        if ($resolved['verdict'] === self::OK) {
            return null;
        }

        return $resolved['verdict'] === self::CYCLE
            ? ['status' => 400, 'msg' => "上级{$noun}不能是自己或自己的下级"]
            : ['status' => 404, 'msg' => "上级{$noun}不存在"];
    }

    /** `$pid` 是不是 `$id` 的子孙（把它挂上去就成环）。 */
    public static function isDescendant(string $table, int $pid, int $id): bool
    {
        $seen = 0;
        while ($pid > 0 && $seen++ < self::MAX_DEPTH) {
            if ($pid === $id) {
                return true;
            }
            $row = Db::select("SELECT parent_id FROM {$table} WHERE id = ? LIMIT 1", [$pid]);
            if ($row === []) {
                return false;
            }
            $pid = (int) ($row[0]['parent_id'] ?? 0);
        }

        return $pid > 0;
    }
}
