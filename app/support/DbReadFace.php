<?php

declare(strict_types=1);

namespace app\support;

/**
 * 「一座库没读到」与「这台系统没有这些数据」的唯一判定与唯一出参形状。
 *
 * 这一族口径原先只在 `MonitorController` 那四条数据库腿里有（`dbDegradeReason()` /
 * `dbDegrade()`，见 tests/MonitorDbDiagnosticsTest.php）。framework v1.14.0/v1.15.0 把
 * `ScheduleDispatcher::stats()`/`runHistory()`/`tenantRunHistory()` 的「读失败吞成空结果」
 * 改成抛异常之后，管理端多出六个读入口要接住它 —— 各自再 match 一遍 SQLSTATE 就是七份实现，
 * 而本仓这条线反复踩过「只改对一个」的坑（AGENTS §3「一个判定只许有一处实现」）。所以映射搬到这里，
 * `MonitorController` 与 `ScheduleController` 都只转发。
 *
 * 三条硬口径（与原处一致，搬动没改语义）：
 *  - 响应永不含驱动原文。PDO 串里带着 `connection to server at "127.0.0.1", port 5432 failed`，
 *    那是内网拓扑（同 `Shard::failureReason()` 点名的那条红线），原文只进文件日志；
 *  - `$extension` 只有「查可选统计视图」的入口才传。42P01 在这一类入口的含义是
 *    「这台没开这项观测」（本机 `kode_app` 就是这档，实测 0 行 / 42P01），在别处是
 *    「视图不该不存在却不见了」，归成同一句话会把排障方向指反；
 *  - 出参形状不随成败变：`available`/`reason` **恒在**（正常时 `reason` 是 null，不是缺键），
 *    缺键与「后端版本旧」在客户端无法区分，而前端图表按固定键画图。
 *
 * 数字类指标读不到时由调用方回 **null 而不是 0**（AGENTS §3 聚合口径：0 会被念成「这台系统一个都没有」），
 * `readable()`/`unreadable()` 只负责 `available`/`reason` 这两个键，管不到载荷里的数字。
 *
 * 注意与 `Shard::failureReason()` 的区别：那份说的是「这一**片**怎么了」（分片库不可达 / 片表缺列 /
 * 无权访问该片），处置项是 `php kode shard:init`；本份说的是「整座库/某个视图怎么了」，处置项是
 * 装扩展、补迁移、查账号可见 schema。两套词汇是**有意分开**的，不要合并成一份。
 */
final class DbReadFace
{
    /**
     * SQLSTATE → 结论口径（纯函数，可离线断言）。
     *
     * SQLSTATE 只能经 `Shard::sqlState()` 沿异常链取：pdo_pgsql 把所有驱动错误码回吐成 7，
     * 于是连接类故障的 `getCode()` 是 int 7 而不是 `'08006'`，只看它则「不可达」这一档永不命中。
     */
    public static function reason(\Throwable $e, ?string $extension = null): string
    {
        $state = Shard::sqlState($e);

        return match (true) {
            str_starts_with($state, '08') || str_starts_with($state, 'HYT') => '数据库不可达',
            $state === '42501' => '当前库账号无权读取统计视图',
            $state === '42P01' && $extension !== null => $extension . ' 扩展未安装（CREATE EXTENSION ' . $extension . '）',
            $state === '55000' && $extension !== null => $extension . ' 扩展未装载（写进 postgresql.conf 的 shared_preload_libraries 并重启实例）',
            $state === '42P01' => '统计视图或表不存在（跑 php kode migrate 补迁移，或检查账号可见 schema）',
            default => '读取失败',
        };
    }

    /**
     * 一次成功的读：`available: true` + `reason: null`（键必须在，不能省）。
     *
     * 声明键排在右侧，所以调用方的载荷里如果混进同名字段会被如实覆盖 —— 这两个键只由本类产出。
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function readable(array $payload = []): array
    {
        return array_merge($payload, ['available' => true, 'reason' => null]);
    }

    /**
     * 一次失败的读：结论进响应，原文进日志。
     *
     * 「扩展没装/没装载」这一档不打日志：面板每次被点开都会撞一次 42P01，那是配置事实
     * （响应里已经写着怎么修），按访问量刷进日志文件只会把真信号埋掉，也顺带避开请求内的文件 I/O。
     *
     * @param string             $face      读入口标识（只进日志，不进响应）
     * @param array<string, mixed> $payload 失败时的载荷形状（清单键回空数组，数字键回 null）
     *
     * @return array<string, mixed>
     */
    public static function unreadable(\Throwable $e, string $face, array $payload = [], ?string $extension = null): array
    {
        $state = Shard::sqlState($e);
        $benign = $extension !== null && ($state === '42P01' || $state === '55000');
        if (!$benign) {
            logger()->error('数据库读取失败', [
                'face' => $face,
                'code' => $state,
                'error' => $e->getMessage(),
            ]);
        }

        return array_merge($payload, ['available' => false, 'reason' => self::reason($e, $extension)]);
    }
}
