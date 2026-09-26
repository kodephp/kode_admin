<?php

declare(strict_types=1);

namespace app\concerns;

/**
 * 分页窗口入参闸门（列表类接口共用）。
 *
 * 为什么单独成一份：判定原本在 16 个列表入口各写一份 `max(1, (int) query('page', 1))` +
 * `min(100, max(1, (int) query('size', 20)))`，而 offset 的乘法又在控制器、服务层、仓储层
 * 各写了一遍（`UserService::list` / `UserRepository::page` / `WalletService::list`）。
 * 实测后果是 500 而不是空列表：`page` 只做了下限保护，`9223372036854775807` 经 `(int)`
 * 饱和成 `PHP_INT_MAX`，乘上 `size` 就溢出成 float，绑进 `LIMIT ? OFFSET ?` 后 pgsql 报
 * `SQLSTATE[22P02] 无效的类型 bigint 输入语法`。而且 `OFFSET` 的深度没有任何上限，
 * pgsql 是「真的扫弃那么多行」才继续 —— 深度无界等于一个数就能让 DB 白干一次全表级扫描。
 *
 * 两条口径分工明确：
 *  - `size` 夹到上限是**保护**（限的是本次返回行数，不改变用户要的东西），沿用旧行为；
 *  - `page` 越过深度上限一律出 error 由调用方转 400（口径同 `RetentionGuard`：不替用户
 *    决定他想翻到哪，也绝不静默夹成一个「看着像数据」的页码）。
 *
 * `offset` 只由这里算。调用方拿到的一定是 int，且一定在 `MAX_ROW_DEPTH` 之内，
 * 于是「一个查询参数打崩列表接口」和「一次请求的 DB 开销无界」两件事同时封顶。
 */
trait PageWindow
{
    /** 单页行数默认上限；确需更大的入口显式传 `$maxSize`（如 DB 表格浏览、钱包流水）。 */
    private const DEFAULT_MAX_SIZE = 100;

    /** 漏传 `size` 时的每页行数（16 个入口以前各写一遍这个 20，现在只此一处）。 */
    private const DEFAULT_SIZE = 20;

    /**
     * 允许翻到的最大行深（即 offset 上限）。
     *
     * 十万行是「人还会一页页翻过去」的量级上限，再深就该用筛选条件而不是让数据库逐行数过去
     * （pgsql 的 `OFFSET n` 要真的取回并丢弃 n 行）。
     */
    private const MAX_ROW_DEPTH = 100000;

    /**
     * 归并型分页的行预算（跨分片「每片各取 page*size 行进 PHP 再排序切片」的入口用）。
     *
     * 这类入口的代价不是 OFFSET 而是**行数 × 分片数**：真实审计分片表 6435 行实测，
     * 一行经 `Db::select()`（`PDO::FETCH_ASSOC`）落进 PHP 数组净占约 885 B（JSON 载荷才 175 B，
     * 差额是 PHP 数组自身的桶 + zval），worker 的 `memory_limit` 是 128M（`php -i` 实测，
     * HTTP worker 不改这份 ini），`usort` 还要再拷一份 ——
     * 沿用 `MAX_ROW_DEPTH` 等于允许 `100000 × 4 片 ≈ 354 MB`，
     * 一枚有效 token 一个查询参数就能把 worker 撞成内存 fatal。
     * 2 万行（≈17.7 MB）是「一页页翻得完、又远不到 OOM」的量级。
     */
    private const MAX_MERGED_ROWS = 20000;

    /**
     * 判定 `page` / `size`：合法返回窗口三件套，非法返回原因（两者互斥）。
     *
     * @param int $maxDepth 行深预算；归并型入口走 mergedWindow()，不要自己传这个数
     *
     * @return array{page: int, size: int, offset: int, error: string|null}
     */
    protected function pageWindow(
        mixed $rawPage,
        mixed $rawSize,
        int $maxSize = self::DEFAULT_MAX_SIZE,
        int $maxDepth = self::MAX_ROW_DEPTH
    ): array {
        $cap = max(1, $maxSize);
        $depth = max(1, $maxDepth);
        $size = min($cap, max(1, (int) ($rawSize ?? self::DEFAULT_SIZE)));
        $page = max(1, (int) $rawPage);

        // 先比深度再相乘：乘完再判就正好踩在「乘法本身溢出」这个 bug 上。
        $deepest = intdiv($depth, $size) + 1;
        if ($page > $deepest) {
            return [
                'page' => $page,
                'size' => $size,
                'offset' => 0,
                'error' => sprintf(
                    'page 超出可翻页范围：每页 %d 条最多翻到第 %d 页（行预算 %d 行），请用筛选条件缩小范围',
                    $size,
                    $deepest,
                    $depth
                ),
            ];
        }

        return ['page' => $page, 'size' => $size, 'offset' => ($page - 1) * $size, 'error' => null];
    }

    /**
     * 归并型分页：跨 N 片各取 `page*size` 行到 PHP 里再切片的入口走这里。
     *
     * 预算按分片数摊薄，因为真正进内存的是 `page * size * shards` 行。
     * `shards <= 1` 时它退回 `pageWindow()`：单表分页的代价在 SQL 侧的 OFFSET 扫描，
     * 不是 PHP 里的行数，不该被这份内存预算误伤。
     *
     * @return array{page: int, size: int, offset: int, error: string|null}
     */
    protected function mergedWindow(
        mixed $rawPage,
        mixed $rawSize,
        int $shards,
        int $maxSize = self::DEFAULT_MAX_SIZE
    ): array {
        if ($shards <= 1) {
            return $this->pageWindow($rawPage, $rawSize, $maxSize);
        }

        return $this->pageWindow(
            $rawPage,
            $rawSize,
            $maxSize,
            intdiv(self::MAX_MERGED_ROWS, $shards)
        );
    }
}
