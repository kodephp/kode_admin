<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Plugins\frontend\WorkflowBridgeController;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * 工作流只读汇总入口的「统计口径」（AGENTS.md §4「跨分片聚合不许按行抽样」的插件侧分支）。
 *
 * 这批入口把**被 limit 截断的抽样行**当成全集去数，各处各歪一点：
 *  - `alertHistory`：`total => count($result)` 恒等于本页行数（`limit` 默认 100，前端可传 1），
 *    `level_counts` 同样只数这几十行 —— 页面上「告警总数」和两张分布卡于是**静默偏小**；
 *    `top_workflows` 更糟：`krsort($workflowCounts, SORT_NUMERIC)` 拿数值规则比字符串键
 *    （实测两个不同工作流名都折算成 0，等于没排），所谓「告警 TOP 工作流」其实只是插入顺序，
 *    告警最多的那个可能压根不在前 10；
 *  - `stepRegressionHistory`：同款 `total`/`level_counts`；
 *  - `webhookDlq`：`by_workflow` 是 SQL 侧算的（口径正确），但**不带上方的 workflow 筛选条件**，
 *    于是筛了工作流 A 之后排行里还列着 B —— 与 license 卡片「stats 不跟筛选走」是同一条反例。
 *
 * 现在的口径：分布与总数由 SQL 侧在**同一份 WHERE** 上算（`COUNT(*) FILTER` / `GROUP BY ... ORDER BY cnt DESC`），
 * 行数据才是 limit 截断的那一页，并且 `truncated` 明说列表被截过（缺了它，「总共 5 条只列 2 条」
 * 和「总共 2 条」在页面上长得一样）。
 *
 * 后两条（`failurePatterns` / `webhookDeliveryStats`）是同一族里剩下两处漏口。它们的抽样上限
 * 写死在 SQL 的 `LIMIT` 上（500 / 2000），所以「总数」一旦被 `count($rows)` 顶掉就是**恒等于那个上限**，
 * 页面再增长也看不见 —— 比前三条更安静，因为 limit 参数还改不动它。
 * 归一化错误模式（`normalizeErrorPattern`）只在 PHP 里有一份判据，搬进 SQL 等于写第二份，
 * 所以那两个入口的**分布**允许来自抽样，代价是必须把抽样规模如实报出来（`scanned_rows` + `truncated`）。
 *
 * 自己造的行自己删（按主键精删，批量种子按自己的工作流名删），不建库不建表；
 * 查询一律带 workflow 过滤，避免撞上库存量行。
 */
final class WorkflowSummaryScopeTest extends TestCase
{
    protected bool $independentApp = true;

    private const TAG = 'zz-wfsum';

    /** failurePatterns 行抽样上限（与生产 SQL 里的 LIMIT 同源；只用于算种子条数） */
    private const PATTERN_SAMPLE_ROWS = 500;

    /** webhookDeliveryStats 行抽样上限 = WorkflowBridgeController::WEBHOOK_SAMPLE_ROWS */
    private const WEBHOOK_SAMPLE_ROWS = 2000;

    /** 展开 step_results_json 的聚合共用的行预算（= ScanBudget::STEP_ROWS），见下面那条用例的注释 */
    private const STEP_SCAN_ROWS = 2000;

    /** @var list<int> */
    private array $alertIds = [];

    /** @var list<int> */
    private array $regressionIds = [];

    /** @var list<int> */
    private array $logIds = [];

    /** @var list<string> 批量种子按工作流名归属，清理时按名精删自己造的行 */
    private array $executionSeeds = [];

    /** @var list<string> */
    private array $webhookSeeds = [];

    /** @var list<int> 本轮造的工作流定义行（死代码榜的对照面） */
    private array $defIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->alertIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_workflow_alerts WHERE id = ?', [$id]);
        }
        foreach ($this->regressionIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_workflow_step_regressions WHERE id = ?', [$id]);
        }
        foreach ($this->logIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_workflow_webhook_logs WHERE id = ?', [$id]);
        }
        $this->alertIds = $this->regressionIds = $this->logIds = [];

        // 批量种子按工作流名精删：只删本轮自己造的那几个名字，绝不用 LIKE 'zz-%' 扫全表
        foreach ($this->executionSeeds as $wf) {
            Db::delete('DELETE FROM kode_lowcode_workflow_executions WHERE workflow_name = ?', [$wf]);
        }
        foreach ($this->webhookSeeds as $wf) {
            Db::delete('DELETE FROM kode_lowcode_workflow_webhook_logs WHERE workflow_name = ?', [$wf]);
        }
        $this->executionSeeds = $this->webhookSeeds = [];
        foreach ($this->defIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_workflows WHERE id = ?', [$id]);
        }
        $this->defIds = [];

        HttpRequest::clear();

        parent::tearDown();
    }

    /* ==================== /api/workflow/alerts ==================== */

    /** total 与 level_counts 必须是时间窗 + 筛选后的全集，不是 limit 截断的那几行。 */
    public function test_the_alert_summary_describes_the_window_not_the_returned_page(): void
    {
        $wf = self::TAG . '-window';
        // 种子形状是被测断言的一半：1 warning + 2 critical，而 limit=2 抽到的恰是后两条 critical。
        // 于是「数全集」得 warning=1/critical=2，「数这一页」得 warning=0/critical=2，
        // 「数这一页的行数」得 warning=2 —— 三种错法都落不进期望值里。
        $this->seedAlert($wf, 'warning');
        $this->seedAlert($wf, 'critical');
        $this->seedAlert($wf, 'critical');

        $res = $this->call('alertHistory', ['workflow' => $wf, 'limit' => 2]);

        self::assertSame(200, $res['status']);
        $data = $res['data'];
        self::assertNotSame([], $data, '响应没有 data 节点');
        self::assertSame(2, count($data['alerts'] ?? []), 'limit 窗口本身失效');
        self::assertSame(3, $data['total'] ?? null, 'total 数的是抽样行：页面上的「告警总数」会静默偏小');
        self::assertSame(
            ['warning' => 1, 'critical' => 2],
            $data['level_counts'] ?? null,
            'level_counts 跟着 limit 走，没跟筛选后的全集走'
        );
        self::assertTrue($data['truncated'] ?? null, '列表被截断却没说出来：5 条只列 2 条看着像只有 2 条');
    }

    /** 没截断时 truncated 必须是 false，否则「全部已列出」这个信息就没了。 */
    public function test_the_alert_summary_reports_no_truncation_when_everything_fit(): void
    {
        $wf = self::TAG . '-fit';
        $this->seedAlert($wf, 'warning');

        $data = $this->call('alertHistory', ['workflow' => $wf])['data'];

        self::assertSame(1, $data['total'] ?? null);
        self::assertFalse($data['truncated'] ?? null);
    }

    /**
     * 「告警 TOP 工作流」必须按条数降序。
     * 故意让告警多的那个**后插入**：插入顺序（旧 krsort 的实际结果）与条数顺序相反，
     * 名字字典序也相反（`later` > `early`），两条假序都被挡下。
     */
    public function test_the_alert_ranking_is_ordered_by_alert_count(): void
    {
        $few = self::TAG . '-early';
        $many = self::TAG . '-later';
        $this->seedAlert($few, 'warning');
        for ($i = 1; $i <= 3; $i++) {
            $this->seedAlert($many, $i === 3 ? 'critical' : 'warning');
        }

        $data = $this->call('alertHistory', ['limit' => 500])['data'];
        self::assertArrayHasKey('top_workflows', $data, 'top_workflows 缺键');
        $names = array_column($data['top_workflows'], 'workflow_name');

        $posFew = array_search($few, $names, true);
        $posMany = array_search($many, $names, true);
        self::assertNotFalse($posMany, '告警 3 条的工作流没进排行');
        self::assertNotFalse($posFew, '对照用的工作流没进排行（造行失败）');
        self::assertLessThan(
            $posFew,
            $posMany,
            '排行没有按条数降序：krsort(SORT_NUMERIC) 比字符串键等于没排'
        );

        $counts = array_column($data['top_workflows'], 'count');
        $sorted = $counts;
        rsort($sorted);
        self::assertSame($sorted, $counts, 'top_workflows 的 count 列不是降序');
    }

    /* ==================== /api/workflow/step-regressions ==================== */

    public function test_the_regression_summary_describes_the_window_not_the_returned_page(): void
    {
        $wf = self::TAG . '-reg';
        // 1 critical + 2 warning，limit=1 抽到最后那条 warning：
        // 数全集得 {critical:1, warning:2, info:0}，数这一页得 {critical:0, warning:1, info:0}。
        $this->seedRegression($wf, 'critical');
        $this->seedRegression($wf, 'warning');
        $this->seedRegression($wf, 'warning');

        $data = $this->call('stepRegressionHistory', ['workflow' => $wf, 'limit' => 1])['data'];

        self::assertSame(1, count($data['regressions'] ?? []));
        self::assertSame(3, $data['total'] ?? null, 'total 数的是抽样行');
        self::assertSame(
            ['critical' => 1, 'warning' => 2, 'info' => 0],
            $data['level_counts'] ?? null,
            '三张等级卡只统计了这一页'
        );
        self::assertTrue($data['truncated'] ?? null);
    }

    /* ==================== /api/workflow/webhook-dlq ==================== */

    /** 死信总数必须是全量死信，排行必须带上同一个 workflow 筛选。 */
    public function test_the_dlq_summary_is_the_filtered_set_and_says_so(): void
    {
        $mine = self::TAG . '-dlq';
        $other = self::TAG . '-other';
        $this->seedDeadLetter($mine);
        $this->seedDeadLetter($mine);
        $this->seedDeadLetter($other);

        $all = $this->call('webhookDlq', ['limit' => 1])['data'];
        self::assertSame(1, count($all['items'] ?? []));
        self::assertGreaterThanOrEqual(3, $all['total'] ?? 0, 'total 又数成这一页的行数了');

        $one = $this->call('webhookDlq', ['workflow' => $mine, 'limit' => 1])['data'];
        self::assertSame(1, count($one['items'] ?? []));
        self::assertSame(2, $one['total'] ?? null);
        self::assertSame(
            [$mine],
            array_column($one['by_workflow'] ?? [], 'workflow_name'),
            'by_workflow 没带 workflow 筛选：筛了 A 还列着 B'
        );
        self::assertSame(
            2,
            (int) ($one['by_workflow'][0]['count'] ?? 0),
            '排行的条数也得是筛选后的全集，不是这一页'
        );
        self::assertTrue($one['truncated'] ?? null, 'limit 截断没报告');
    }

    /* ==================== /api/workflow/failure-patterns ==================== */

    /**
     * `total_failed` 必须是时间窗内的失败执行总数，而不是被 `LIMIT 500` 截断后取回的行数。
     *
     * 种子刻意配成 501 条：抽样口径恒等于 500，全集口径等于 501，
     * 于是「超过上限」这一件事本身就是断言，不需要读生产的常量。
     */
    public function test_the_failure_summary_counts_the_window_not_the_sample(): void
    {
        $wf = self::TAG . '-patterns';
        $this->seedFailedExecutions($wf, self::PATTERN_SAMPLE_ROWS + 1);

        $data = $this->call('failurePatterns', ['workflow' => $wf])['data'];

        self::assertSame(
            self::PATTERN_SAMPLE_ROWS + 1,
            $data['total_failed'] ?? null,
            'total_failed 数的是 LIMIT 截断的抽样行：面板上的「总失败数」会永久卡在 500'
        );
        self::assertSame(
            self::PATTERN_SAMPLE_ROWS,
            $data['scanned_rows'] ?? null,
            '模式分布来自抽样，抽样规模必须自己说出来'
        );
        self::assertTrue($data['truncated'] ?? null, '只列了 500 条却不说「还有」，看板会把它当全集');
    }

    /** 没截断时 truncated 必须是 false，且 total 与 scanned 相等。 */
    public function test_the_failure_summary_reports_no_truncation_when_everything_fit(): void
    {
        $wf = self::TAG . '-patterns-fit';
        $this->seedFailedExecutions($wf, 3);

        $data = $this->call('failurePatterns', ['workflow' => $wf])['data'];

        self::assertSame(3, $data['total_failed'] ?? null);
        self::assertSame(3, $data['scanned_rows'] ?? null);
        self::assertFalse($data['truncated'] ?? null);
    }

    /**
     * 总数那条腿必须带上同一个 workflow 筛选。
     *
     * 两个工作流各造一批（一个正好被截断、一个装得下），筛选后的总数只能是自己那一批 ——
     * 与 `webhookDlq` 的 `by_workflow` 漏筛选是同一条反例。
     */
    public function test_the_failure_summary_follows_the_workflow_filter(): void
    {
        $big = self::TAG . '-patterns-a';
        $small = self::TAG . '-patterns-b';
        $this->seedFailedExecutions($big, self::PATTERN_SAMPLE_ROWS + 5);
        $this->seedFailedExecutions($small, 4);

        $data = $this->call('failurePatterns', ['workflow' => $small])['data'];

        self::assertSame(4, $data['total_failed'] ?? null, '筛选没跟着总数走：筛了 B 还数着 A');
        self::assertSame(4, $data['scanned_rows'] ?? null);
        self::assertFalse($data['truncated'] ?? null);
    }

    /* ==================== /api/workflow/webhook-delivery-stats ==================== */

    /**
     * 头部四个数（总数 / 各状态 / 投递率 / 平均延迟）必须算在整个时间窗上。
     *
     * 造 `上限 + 1` 条投递，且让**多出来的那一条落在抽样窗口外**（created_at 最早、状态唯一）：
     * 于是「数全集」得 pending=1、「数抽样」得 pending=0，总数 2001 对 2000 ——
     * 两个数各自独立地把错法挡下来，都不依赖库存量行的多少。
     */
    public function test_the_delivery_headline_describes_the_window_not_the_sample(): void
    {
        $wf = self::TAG . '-delivery';
        $this->seedDeliveries($wf, self::WEBHOOK_SAMPLE_ROWS, 1);

        $data = $this->call('webhookDeliveryStats', [])['data'];

        self::assertGreaterThan(
            self::WEBHOOK_SAMPLE_ROWS,
            (int) ($data['total_deliveries'] ?? 0),
            'total_deliveries 恒等于抽样上限：它数的是 LIMIT 截断的那批行，窗口再涨也不动'
        );
        self::assertSame(
            self::WEBHOOK_SAMPLE_ROWS,
            $data['scanned_rows'] ?? null,
            'by_url / 重试分布来自抽样，抽样规模必须自己说出来'
        );
        self::assertTrue($data['truncated'] ?? null, '只统计了最近 2000 行却不说，页面会把它当全窗口径');
        self::assertGreaterThanOrEqual(
            1,
            (int) ($data['by_status']['pending'] ?? 0),
            'by_status 也只数了抽样行：落在窗口外那条状态直接消失'
        );
        self::assertGreaterThan(
            0,
            (int) ($data['by_status']['delivered'] ?? 0),
            '种子没生效（delivered 为 0），上面的断言会集体空转'
        );
    }

    /* ==================== 载荷列展开型聚合的扫描预算 ==================== */

    /**
     * 这批入口全部把执行记录的**载荷列**（`step_results_json` / `input_json`）整列拉进 PHP
     * 再 `json_decode` 做聚合。
     *
     * 代价不是「数字偏小」而是「worker 怎么死」：实测本库 `step_results_json` 均值 1958 B、最大 37801 B，
     * 而 `json_decode(..., true)` 出来的 PHP 数组占的字节是 JSON 原文的 8~9 倍（同一行实测
     * 37802 B → 356040 B），HTTP worker 的 `memory_limit` 是 128M。
     * 一条没有任何行预算的 `SELECT` 把窗口里的执行记录全取回来，就是几万行量级即触发内存 fatal
     * —— 而这批入口的 `days` 上限各写一遍（365 / 90 / 30），传 `?days=365` 就能挑中最贵的那条路。
     *
     * 最后两条（`inputProfile` / `inputValidation`）是这一族按 `input_json` 又长出来的两个入口：
     * 参数类型分布与必填校验的判据只在 PHP 有一份（`initParamStats`/`getTypeLabel` 那一套），
     * 所以抽样是允许的，代价与上面完全同构。这一族最初收口时只登记了 `step_results_json`
     * 那一轮用到的名字，所以静态面漏掉了它们（见 `test_no_hand_written_payload_scan_forgets_its_row_limit`）。
     *
     * 所以这一族统一三件套：`window_rows`（同一条 WHERE 上的 SQL `COUNT(*)`）、
     * `scanned_rows`（真正进 PHP 聚合的行数）、`truncated`（前者大于后者）。
     * 一个循环收齐所有缺口再断言：逐条 assert 的话第一个红会遮住后面十一个漏网的入口。
     */
    private const SCAN_HANDLERS = [
        'stepStats',
        'failureAnalysis',
        'stepRetryAnalysis',
        'apiCallAggregation',
        'timeoutHotspots',
        'stepHealth',
        'pathMining',
        'dataLineage',
        'executionClustering',
        'costAllocation',
        'dependencyHealth',
        'performanceBottleneck',
        'inputProfile',
        'inputValidation',
    ];

    public function test_every_step_results_aggregate_declares_its_scan_budget(): void
    {
        $this->seedScanRows(self::STEP_SCAN_ROWS + 1);

        $gaps = [];
        foreach (self::SCAN_HANDLERS as $handler) {
            $res = $this->call($handler, []);
            if ($res['status'] !== 200) {
                $gaps[] = "{$handler}: HTTP {$res['status']}";
                continue;
            }
            $data = $res['data'];
            foreach (['window_rows', 'scanned_rows', 'truncated'] as $key) {
                if (!array_key_exists($key, $data)) {
                    $gaps[] = "{$handler}: 缺 {$key}";
                }
            }
            if ($gaps !== [] && end($gaps) !== null && str_starts_with((string) end($gaps), $handler)) {
                continue; // 这个入口已经记过缺键，别再拿它的数值报第二遍
            }
            $scanned = (int) ($data['scanned_rows'] ?? -1);
            $window = (int) ($data['window_rows'] ?? -1);
            if ($scanned > self::STEP_SCAN_ROWS) {
                $gaps[] = "{$handler}: 扫了 {$scanned} 行，超过预算 " . self::STEP_SCAN_ROWS;
            }
            if ($window < self::STEP_SCAN_ROWS + 1) {
                $gaps[] = "{$handler}: window_rows={$window}，没数到种子的 " . (self::STEP_SCAN_ROWS + 1) . ' 行';
            }
            if (($data['truncated'] ?? null) !== true) {
                $gaps[] = "{$handler}: 窗口比抽样多却没说 truncated（分布会被读成全窗口径）";
            }
            // 标题栏那份总数必须是**窗口全集**。这一格和上一条是两件事：
            // `window_rows` 只是「扫描器知道自己扫了多少」，而页面顶部那张卡片读的是
            // `total_executions` —— 写成 `count($rows)` 时三件套照样全绿，卡片却静默变成抽样数。
            // 嵌套的 `global_summary.total_executions` 不在射程内：那一处**应当**是抽样口径
            // （分子分母都取自同一批抽样，见 AGENTS §4）。
            if (array_key_exists('total_executions', $data) && (int) $data['total_executions'] !== $window) {
                $gaps[] = "{$handler}: total_executions={$data['total_executions']}，数的是抽样的 {$window} 行之外的某一批";
            }
        }

        self::assertSame([], $gaps, "以下入口没走同一份扫描预算：\n  " . implode("\n  ", $gaps));
    }

    /**
     * 窗口里没有数据时三件套必须**缺键都在**（回 0 / false，而不是不出现）。
     *
     * 缺键与「老版本后端」在客户端无法区分，而面板按固定长度画图 ——
     * 这条是 AGENTS §4「`unreadable_shards` 恒在，正常时是 `[]`」的同一口径。
     */
    public function test_the_scan_budget_keys_are_present_when_nothing_matched(): void
    {
        $gaps = [];
        foreach (self::SCAN_HANDLERS as $handler) {
            $data = $this->call($handler, ['workflow' => self::TAG . '-no-such-wf'])['data'];
            foreach (['window_rows', 'scanned_rows', 'truncated'] as $key) {
                if (!array_key_exists($key, $data)) {
                    $gaps[] = "{$handler}: 缺 {$key}";
                }
            }
        }

        self::assertSame([], $gaps, "空窗口下缺键：\n  " . implode("\n  ", $gaps));
        // `workflow` 不是每个入口都吃，所以这一条只验「键恒在」，数值口径由上一条管。
    }

    /**
     * 健康评分的「重试率」那一维在每个工作流的循环里扫 `step_results_json`，
     * 旧写法一条 `LIMIT` 都没有 —— 窗口里只要有一个工作流攒了几万条执行，
     * 第一个工作流就把 128M 的 worker 装满，而它只是 0-100 分里占 15 分的那一项。
     *
     * 现在的口径：每次扫描有预算（峰值内存 = 单个工作流的抽样规模，不是全窗口），
     * 并且整个入口把**跨工作流累加**的三件套说出来 —— 少了它，评分依据的是
     * 「最近 400 次」还是「窗口全部 4 万次」在页面上完全看不出来。
     */
    public function test_the_health_score_retry_dimension_declares_its_scan_budget(): void
    {
        $this->seedScanRows(self::STEP_SCAN_ROWS + 1);

        $data = $this->call('workflowHealthScore', [])['data'];

        foreach (['window_rows', 'scanned_rows', 'truncated'] as $key) {
            self::assertArrayHasKey($key, $data, "健康评分没说清重试率那一维扫了多少行（缺 {$key}）");
        }
        self::assertGreaterThanOrEqual(
            self::STEP_SCAN_ROWS + 1,
            (int) $data['window_rows'],
            'window_rows 没数到种子的执行记录，后面的比值断言是空的'
        );
        self::assertTrue($data['truncated'], '扫了抽样却按全窗口径出评分，必须点名');
        self::assertLessThan(
            (int) $data['window_rows'],
            (int) $data['scanned_rows'],
            'scanned_rows 不小于 window_rows 却报 truncated，三件套自相矛盾'
        );
    }

    /**
     * `correlationAnalysis` 把窗口内的失败执行（含 `error` 这一 TEXT 列）整列取回后两两比较。
     *
     * 三条独立的要求，一条用例全收：
     *  1. 三件套齐（这条入口也吃 `days`，上限 30，一枚 `?days=30` 就能挑到最贵的那档）；
     *  2. `total_failures` 是**窗口全集**，不是被截断的抽样行数；
     *  3. 被截掉的是**最老**的那些，不是最新的那些 —— 旧写法 `ORDER BY created_at ASC`
     *     配上任何上限都会留下最老的一批，于是面板上「最近的关联故障」其实是上周的，
     *     而这一族里 `performanceBottleneck` 已经因为同一个走法修过一次。
     *
     * 造法：新侧 2000 条（单个工作流，1 秒一条）+ 3 条另一工作流但落在最新几秒（`-corr-new-b`），
     * 老侧两组各 3 条、同批秒数（4000..4002 秒前，它们彼此构成一次跨工作流关联，却排在最老 6 条）。
     * 预算 2000 行 ⇒ 老侧那 6 条恰好被切掉、新侧那 3 条必然留下：
     * `summary.unique_workflow_names` 必须出现 `-corr-new-b`、必须不出现 `-corr-old-a/-corr-old-b`。
     * 反过来（按最老取）恰好把这两个断言互换 —— 所以这一条同时在验「留哪一头」。
     */
    public function test_the_correlation_window_is_budgeted_and_keeps_the_recent_end(): void
    {
        $newA = self::TAG . '-corr-new-a';
        $newB = self::TAG . '-corr-new-b';
        $oldA = self::TAG . '-corr-old-a';
        $oldB = self::TAG . '-corr-old-b';
        $this->seedFailedSpread($newA, self::STEP_SCAN_ROWS, 1);
        $this->seedFailedSpread($newB, 3, 2);
        $this->seedFailedSpread($oldA, 3, 4000);
        $this->seedFailedSpread($oldB, 3, 4000);

        $window = (int) (Db::select(
            "SELECT COUNT(*) AS cnt FROM kode_lowcode_workflow_executions
             WHERE status = 'failed' AND created_at > NOW() - INTERVAL '7 days'"
        )[0]['cnt'] ?? 0);
        self::assertGreaterThanOrEqual(
            self::STEP_SCAN_ROWS + 6,
            $window,
            '独立重数的窗口集比预算还小，说明种子没落进 7 天窗口，下面的断言会集体空转'
        );

        $data = $this->call('correlationAnalysis', [])['data'];

        foreach (['window_rows', 'scanned_rows', 'truncated'] as $key) {
            self::assertArrayHasKey($key, $data, "关联分析没说清扫了多少行（缺 {$key}）");
        }
        self::assertSame($window, (int) ($data['window_rows'] ?? -1), 'window_rows 与我按同一条 WHERE 重数的不一致');
        self::assertSame($window, (int) ($data['total_failures'] ?? -1), 'total_failures 数的是抽样行，不是窗口全集');
        self::assertLessThanOrEqual(self::STEP_SCAN_ROWS, (int) ($data['scanned_rows'] ?? PHP_INT_MAX), '进 PHP 的行数超预算');
        self::assertTrue($data['truncated'] ?? null, '窗口比预算大却没说 truncated');

        $names = (array) ($data['summary']['unique_workflow_names'] ?? []);
        self::assertContains($newB, $names, '最新那几秒的跨工作流关联没被检出：抽样留下的不是最近那批');
        self::assertNotContains($oldA, $names, '被预算切掉的老侧进了榜：抽样按最老取（ORDER BY created_at ASC）');
        self::assertNotContains($oldB, $names, '同上：另一条老侧工作流也不该出现');
    }

    /**
     * 幂等键检测必须算在**窗口全集**上，而不是「进 PHP 的那一批」上。
     *
     * 这一族其它入口允许抽样，因为判据（错误文本规范化、参数类型分布、步骤树递归）只在 PHP 有一份；
     * `idempotency_key` 是一个普通 varchar 列，「同一个键出现了几次」是纯粹的 `GROUP BY ... HAVING COUNT(*) > 1`
     * —— 搬得进 SQL 就必须搬（AGENTS §4「跨分片聚合不许按行抽样」）。差别在这里是致命的：
     * 重复检测的**缺陷本身就是那一条**。按行截断会把一个重复组分在边界上切成两半，
     * 于是「有两条执行共用一个幂等键」这个必须被发现的问题，会因为截断而**报成没有**。
     *
     * 造法：先插重复对与畸形/缺键行（拿到**最小**的 id），再插 2001 条唯一键的执行把它们挤出任何
     * 「最近 2000 行」的窗口。期望值是手算出来的种子形状。
     *
     * 重复组一共 5 组（1 组冲突 + 4 组状态一致）：**组数必须比被测的 `limit` 大**，
     * 否则「整个窗口的组数」与「榜上那几条」会巧合相等，`duplicates = count(榜)` 这份回退写法全绿。
     */
    public function test_duplicate_idempotency_keys_are_detected_beyond_any_row_budget(): void
    {
        $dupKey = self::uuidOf('duplicate');
        $quiet = [self::uuidOf('quiet-1'), self::uuidOf('quiet-2'), self::uuidOf('quiet-3'), self::uuidOf('quiet-4')];
        $wf = self::TAG . '-idem';
        $this->seedIdempotencyFixture($wf, $dupKey, self::STEP_SCAN_ROWS + 1, $quiet);

        $data = $this->call('idempotencyCheck', ['workflow' => $wf])['data'];
        $summary = (array) ($data['summary'] ?? []);

        // 期望值是**手算出来的种子形状**，不是把生产 SQL 再抄一遍：
        // 5 组重复对（10 条）+ 3 条缺键 + 3 条畸形键 + 2001 条唯一键 = 2017 条，全部在同一个 workflow 下，
        // 而窗口比任何「最近 2000 行」的预算都大 ⇒ 前面 16 条（id 最小）必然落在预算外。
        self::assertSame(2017, (int) ($data['total_executions'] ?? -1), '总执行数不是窗口全集');
        self::assertSame(3, (int) ($summary['missing_key'] ?? -1), '缺键数只数了进 PHP 的那批：缺键行 id 最小，正是被预算切掉的那头');
        self::assertSame(3, (int) ($summary['format_invalid'] ?? -1), '畸形键数同上');
        self::assertSame(2014, (int) ($summary['with_key'] ?? -1), 'with_key 是「带键的执行条数」（2017-3），不是去重后的键数');

        self::assertSame(
            5,
            (int) ($summary['duplicates'] ?? 0),
            '重复组数不是整个窗口的组数 —— 而那 10 条执行都在窗口里，只是被行预算切到了外面'
        );
        self::assertSame(1, (int) ($summary['conflicts'] ?? 0), '冲突组（同键不同状态）没检出：状态集合没算在全组上');
        self::assertSame(5, (int) ($summary['duplicate_keys'] ?? 0), 'duplicate_keys 与 duplicates 数的是同一件事');

        $dup = null;
        foreach ((array) ($data['duplicates'] ?? []) as $row) {
            if (($row['key'] ?? null) === $dupKey) {
                $dup = (array) $row;
            }
        }
        self::assertNotNull($dup, '重复对落在预算外，榜上就查无此键（假「无重复」）');
        self::assertSame(2, (int) ($dup['executions'] ?? 0), '组内条数不是全组');
        self::assertFalse((bool) ($dup['status_consistent'] ?? true), 'succeeded/failed 混在一组却被判为状态一致');
        self::assertSame([$wf], array_values((array) ($dup['workflows'] ?? [])), '重复组没带出工作流名');
        self::assertSame(2, count((array) ($dup['exec_ids'] ?? [])), '明细里的执行 id 少了一条');

        // 榜是列表页，`limit` 只该切列表；三份清单同时受预算与总数口径约束。
        $page = $this->call('idempotencyCheck', ['workflow' => $wf, 'limit' => 2])['data'];
        self::assertSame(2, count((array) ($page['duplicates'] ?? [])), 'limit 没作用到重复榜');
        self::assertSame(2, count((array) ($page['missing_key'] ?? [])), 'limit 没作用到缺键清单');
        self::assertSame(2, count((array) ($page['format_invalid'] ?? [])), 'limit 没作用到畸形键清单');
        self::assertSame(2017, (int) ($page['total_executions'] ?? -1), '列表 limit 把总数也改小了');
        self::assertSame(5, (int) ($page['summary']['duplicates'] ?? 0), '列表 limit 把重复组数也改小了');
        self::assertTrue($page['truncated'] ?? null, '窗口里 5 组只列了 2 组，却没说截断');
    }

    /**
     * 空窗口下幂等检测的形状与计数口径都要在位。
     *
     * 恒 0 而不是缺键：面板顶部六张卡片按固定形状画（同上一条 `test_the_scan_budget_keys_are_present_when_nothing_matched`）。
     */
    public function test_idempotency_counts_are_zero_rather_than_absent_on_an_empty_window(): void
    {
        $data = $this->call('idempotencyCheck', ['workflow' => self::TAG . '-no-such-wf'])['data'];
        $summary = (array) ($data['summary'] ?? []);

        foreach (['total_executions', 'duplicates', 'conflicts', 'missing_key', 'format_invalid', 'with_key'] as $key) {
            self::assertArrayHasKey($key, $summary, "空窗口下 summary 缺 {$key}");
            self::assertSame(0, (int) $summary[$key], "空窗口下 {$key} 不是 0");
        }
        self::assertSame(7, (int) ($data['days'] ?? -1), '夹过的窗口没回显生效值');
        self::assertSame([], (array) ($data['duplicates'] ?? null));
    }

    /**
     * 批量造「窗口内失败执行」：`status = failed`、1 秒一条、从 `$firstSecondAgo` 往前排。
     *
     * 不带 `input_json` / `step_results_json` 载荷 —— 关联分析只读 `error` 那一列，
     * 少写一列就是少一堆没必要的字节。
     */
    private function seedFailedSpread(string $workflow, int $count, int $firstSecondAgo): void
    {
        $this->executionSeeds[] = $workflow;
        Db::statement(
            "INSERT INTO kode_lowcode_workflow_executions
                (workflow_name, workflow_id, status, step_count, duration_ms, error, steps_retried, created_at)
             SELECT ?, 0, 'failed', 1, 30 + (g % 5), 'zz correlation probe ' || ?, 0,
                    NOW() - ((? + g - 1) || ' second')::interval
             FROM generate_series(1, ?) AS g",
            [$workflow, $workflow, $firstSecondAgo, $count]
        );
    }

    /**
     * 幂等键检测的种子：**先**插要被预算切掉的那些（拿到最小 id），**再**插唯一键的填充行。
     *
     * 顺序即断言的一半 —— 反过来插，重复对就会落在「最近 2000 行」里，旧写法照样能检出，用例白写。
     *
     * `$quietKeys` 是**状态一致**的重复组（每组两条同状态），存在的理由只有一条：
     * 只种一组重复时「整个窗口的组数」与「被 `limit` 切过的榜上条数」可以恰好相等，
     * 于是 `duplicates = count(列表)` 这份回退写法照样全绿。组数必须**多于**任何被测的 `limit`。
     *
     * @param list<string> $quietKeys
     */
    private function seedIdempotencyFixture(string $workflow, string $dupKey, int $fillers, array $quietKeys = []): void
    {
        $this->executionSeeds[] = $workflow;
        // 1. 重复对：同键两次，状态一个成功一个失败（冲突组）
        Db::statement(
            "INSERT INTO kode_lowcode_workflow_executions
                (workflow_name, workflow_id, status, step_count, duration_ms, created_at, idempotency_key)
             VALUES (?, 0, 'succeeded', 1, 20, NOW() - INTERVAL '3 hours', ?),
                    (?, 0, 'failed', 1, 21, NOW() - INTERVAL '2 hours', ?)",
            [$workflow, $dupKey, $workflow, $dupKey]
        );
        foreach ($quietKeys as $key) {
            Db::statement(
                "INSERT INTO kode_lowcode_workflow_executions
                    (workflow_name, workflow_id, status, step_count, duration_ms, created_at, idempotency_key)
                 VALUES (?, 0, 'succeeded', 1, 25, NOW() - INTERVAL '3 hours', ?),
                        (?, 0, 'succeeded', 1, 26, NOW() - INTERVAL '2 hours', ?)",
                [$workflow, $key, $workflow, $key]
            );
        }
        // 2. 缺键 3 条 + 畸形键 3 条（三份清单的条数都 > `limit`，否则「切列表」验不出来）
        Db::statement(
            "INSERT INTO kode_lowcode_workflow_executions
                (workflow_name, workflow_id, status, step_count, duration_ms, created_at, idempotency_key)
             SELECT ?, 0, 'succeeded', 1, 22, NOW() - INTERVAL '1 hour', ''
             FROM generate_series(1, 3) AS g",
            [$workflow]
        );
        Db::statement(
            "INSERT INTO kode_lowcode_workflow_executions
                (workflow_name, workflow_id, status, step_count, duration_ms, created_at, idempotency_key)
             SELECT ?, 0, 'succeeded', 1, 23, NOW() - INTERVAL '50 minutes', 'zz-not-a-uuid-' || g
             FROM generate_series(1, 3) AS g",
            [$workflow]
        );
        // 3. 唯一键的填充行：把上面 10 条挤出任何「最近 2000 行」的窗口
        Db::statement(
            "INSERT INTO kode_lowcode_workflow_executions
                (workflow_name, workflow_id, status, step_count, duration_ms, created_at, idempotency_key)
             SELECT ?, 0, 'succeeded', 1, 24, NOW() - (g || ' second')::interval,
                    substr(md5('zz-idem-' || g), 1, 8) || '-' || substr(md5('zz-idem-' || g), 9, 4)
                    || '-' || substr(md5('zz-idem-' || g), 13, 4) || '-' || substr(md5('zz-idem-' || g), 17, 4)
                    || '-' || substr(md5('zz-idem-' || g), 21, 12)
             FROM generate_series(1, ?) AS g",
            [$workflow, $fillers]
        );
    }

    /**
     * 把一个种子串折成 UUID 形状（8-4-4-4-12 小写十六进制）。
     *
     * 后端的格式判据认的就是这个形状，种子拿它当「合法键」才不会被算成 `format_invalid`。
     */
    private static function uuidOf(string $seed): string
    {
        $hex = md5($seed);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * 静态面：任何人再写一条「不带预算的载荷列扫描」，这里必须变红。
     *
     * 上面那两条按 handler 清单逐个调用，而清单是**手写的** —— 新加的第 13 个聚合入口
     * 不在清单里就永远不被检查（这一族缺陷的成因从来不是有人写错，是有人另起一处）。
     * 所以再加一条按源码扫的：本文件里每一条**手写**的
     * `SELECT ... <载荷列> ... FROM kode_lowcode_workflow_executions`
     * 必须自己带行数上限。走 `ScanBudget` 的那些不在此列 —— 它们的表名由派发口拼，
     * 源码里根本不出现 `FROM kode_lowcode_workflow_executions`，正是这条判据的落点。
     *
     * 判据先剥注释再扫：文档里那句「旧写法长什么样」不是实现（AGENTS §3「一个判定只许
     * 有一份实现」的另一半是「别让解释把它当成实现」）。
     *
     * 判据的**列名清单**不能只写「造出这一轮缺陷的那个列名」。这一条最初只认
     * `step_results_json`，于是同一张表上把 `input_json` 整列拉走的三个入口
     * （`inputProfile` / `inputValidation` / `idempotencyCheck`）在它眼皮底下全绿了整整一轮
     * —— 名字是上一轮的，代价是这一轮的（AGENTS §6「门禁证明的是算式不是名字」的同一族）。
     */
    public function test_no_hand_written_payload_scan_forgets_its_row_limit(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/plugins/frontend/WorkflowBridgeController.php');
        $scanned = self::statementWith(self::codeOnly($source), 'FROM kode_lowcode_workflow_executions');
        // 防空转：扫描面本身必须真取回东西，否则「0 条违规」和「什么都没扫」在输出上一样。
        self::assertNotEmpty($scanned, '一条 executions 表的查询都没扫到，判据是空的');

        $violations = self::unboundedPayloadScans($scanned);
        self::assertSame([], $violations, "无预算的载荷列扫描：\n  " . implode("\n  ", $violations));

        // 正对照：修复前的形状必须被同一个判据点名，否则上一条 assertSame 只是在复述代码。
        // 两列各一条 —— 只拿 `step_results_json` 做对照的话，「判据又只认了一个列名」照样全绿。
        $before = [
            'SELECT id, step_results_json FROM kode_lowcode_workflow_executions WHERE created_at >= ?',
            'SELECT id, workflow_name, input_json FROM kode_lowcode_workflow_executions WHERE created_at >= ? ORDER BY created_at DESC',
        ];
        self::assertSame($before, self::unboundedPayloadScans($before), '判据抓不住旧写法，它就抓不住新的');
        // 反向对照：带上限的、按主键/按父键单行或单链的、以及在 SQL 里 `json_each` 收敛成分组的都放行
        // —— 否则这条门禁只会逼人把 `LIMIT 99999999` 拼上去交差。
        self::assertSame([], self::unboundedPayloadScans([
            'SELECT id, step_results_json FROM kode_lowcode_workflow_executions WHERE created_at >= ? ORDER BY id DESC LIMIT 2000',
            'SELECT step_results_json FROM kode_lowcode_workflow_executions WHERE id = ?',
            'SELECT id, error FROM kode_lowcode_workflow_executions WHERE parent_execution_id = ? ORDER BY id ASC',
            'SELECT id, error FROM kode_lowcode_workflow_executions WHERE id IN (?, ?)',
            'SELECT COUNT(*) AS cnt FROM kode_lowcode_workflow_executions WHERE error LIKE ?',
            "SELECT SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS err_cnt FROM kode_lowcode_workflow_executions WHERE created_at > ?",
            'SELECT step_name, COUNT(*) FROM kode_lowcode_workflow_executions, LATERAL json_each(step_results_json) GROUP BY step_name',
        ]));
        // 反对照里那两条 `error` 必须真在扫描面上（`SELECT id, error ... WHERE parent_execution_id = ?`
        // 是本文件里真实存在的形状）：把 `error` 从列名清单里摘掉时，上面那组放行会集体变绿而什么都没测到。
        self::assertNotEmpty(
            self::payloadColumnsIn(['SELECT id, error FROM kode_lowcode_workflow_executions WHERE created_at > ?']),
            '判据已经不再认 `error` 这一列，那两条放行只是恰好没人扫它'
        );
    }

    /**
     * 用 token 流剥掉注释，只留代码骨架（注释里的示例 SQL 不是实现）。
     */
    private static function codeOnly(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /**
     * 按 `;` 切成语句，返回含某个标记的那些（SQL 字面量里没有分号，切得干净）。
     *
     * @return list<string>
     */
    private static function statementWith(string $code, string $needle): array
    {
        $hits = [];
        foreach (explode(';', $code) as $stmt) {
            if (str_contains($stmt, $needle)) {
                $hits[] = trim(preg_replace('/\s+/', ' ', $stmt) ?? '');
            }
        }

        return $hits;
    }

    /**
     * 「把整列拉进 PHP」的那几列。
     *
     * 前两个是 JSON 载荷（`json_decode` 再放大 8~9 倍），`error` 是 TEXT 且会进
     * `correlationAnalysis` 的滑动窗口 —— 三者都是「行数 × 单行宽度」里的行数那一项，
     * 而单行宽度由 `ScanBudget` 的预算管。列清单只写这一份，正对照与反对照都读它。
     */
    private const PAYLOAD_COLUMNS = ['step_results_json', 'input_json', 'error'];

    /**
     * 一条语句里出现了哪些载荷列（判据与它的对照用例共用同一份列清单）。
     *
     * @param list<string> $statements
     * @return list<string>
     */
    private static function payloadColumnsIn(array $statements): array
    {
        $found = [];
        foreach ($statements as $stmt) {
            foreach (self::PAYLOAD_COLUMNS as $col) {
                if (stripos($stmt, $col) !== false) {
                    $found[] = $col;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * 找出「读载荷列却没带行数上限」的那些语句。
     *
     * 四类放行，且理由不同：
     *  - `json_each` 在 SQL 里展开并按 `GROUP BY` 收敛（`stepPerformance` / `stepTrend` /
     *    `stepRegression` 那一族）—— 返回行数正比于**分组基数**，与历史条数无关，
     *    这正是 AGENTS §4 要求的方向，加 LIMIT 反而会把分布算歪；
     *  - 选择列表以聚合函数开头（`SELECT COUNT(*) ... WHERE error LIKE ?`、
     *    `SELECT SUM(CASE WHEN ... END) ...`）：一行都不取回，读载荷列的只是**筛选条件**；
     *  - `WHERE id = ?` / `WHERE parent_execution_id = ?` / `WHERE id IN (...)` 按键取单行或单链，
     *    本来就有界。
     *
     * 判据只看「有没有 `LIMIT`」而不比数值：上限取值是否合理由上面那组行为断言管
     * （`scanned_rows <= STEP_SCAN_ROWS` 与三件套），静态面挡的是「压根没写」。
     *
     * @param list<string> $statements
     * @return list<string>
     */
    private static function unboundedPayloadScans(array $statements): array
    {
        $violations = [];
        foreach ($statements as $stmt) {
            if (self::payloadColumnsIn([$stmt]) === []) continue;
            if (!preg_match('/\bSELECT\b/i', $stmt)) continue; // UPDATE/INSERT 里的同名列不是扫描
            if (stripos($stmt, 'json_each') !== false) continue;
            if (preg_match('/SELECT\s+(?:DISTINCT\s+)?(?:COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $stmt)) continue;
            if (preg_match('/\bLIMIT\b/i', $stmt)) continue;
            if (preg_match('/WHERE\s+(?:id|parent_execution_id)\s*=\s*\?/i', $stmt)) continue;
            if (preg_match('/WHERE\s+id\s+IN\s*\(/i', $stmt)) continue;
            $violations[] = mb_substr($stmt, 0, 160);
        }

        return $violations;
    }

    /**
     * 死代码榜只能列「执行历史里从未出现过」的步骤。
     *
     * `collectExecutedStepNames()` 的累加器曾和 `foreach` 的循环变量**同名**（`$result`），
     * 于是每轮迭代把「上一步的累加结果」整个覆盖掉，返回值只剩最后一个步骤自己 ——
     * 加上取执行行的那一遍是「每行覆盖上一个工作流条目」，跨行出现过的步骤也算没出现过。
     * 后果不是少一行：定义里的其他步骤**全部**被印成死代码，而面板上那一栏的文案是
     * 「从未到达」，照着删步骤就是删掉正在跑的业务。
     */
    public function test_dead_steps_only_list_steps_that_never_ran(): void
    {
        $wf = self::TAG . '-dead';
        $this->seedWorkflowDef($wf, ['step_one', 'step_two', 'step_three']);
        // 三个步骤分散在两条执行里：没有任何一个步骤是「从未到达」的。
        $this->seedExecutionWithSteps($wf, ['step_one', 'step_two']);
        $this->seedExecutionWithSteps($wf, ['step_three']);

        $data = $this->call('pathMining', ['workflow' => $wf])['data'];

        self::assertSame(2, (int) ($data['scanned_rows'] ?? -1), '种子行没被窗口接住，后面的断言是空的');
        self::assertSame(
            [],
            $data['dead_steps'],
            '三个步骤都在执行历史里出现过，死代码榜不该有行：'
            . json_encode($data['dead_steps'], JSON_UNESCAPED_UNICODE)
        );
    }

    /** 只有一行执行见过 step_one 时，另外两个步骤才是真死代码（正向对照，防上条空转）。 */
    public function test_dead_steps_still_report_the_genuinely_unreached_ones(): void
    {
        $wf = self::TAG . '-dead-partial';
        $this->seedWorkflowDef($wf, ['step_one', 'step_two', 'step_three']);
        $this->seedExecutionWithSteps($wf, ['step_one']);

        $data = $this->call('pathMining', ['workflow' => $wf])['data'];
        $dead = array_column((array) $data['dead_steps'], 'step_name');
        sort($dead);

        self::assertSame(['step_three', 'step_two'], $dead);
    }

    /* ==================== 清理与导出：剩下两个「按窗口决定删/写多少」的入口 ==================== */

    /**
     * 非法 days 样本：[入参原文（`__missing__` 表示整个键不传）, 期望错误里必须出现的词]。
     * 口径与 `RetentionGuardTest::badDays()` 同源 —— 判定只有一份，样本也该只有一份形状。
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function badPruneDays(): array
    {
        return [
            'missing' => ['__missing__', '必填'],
            'zero' => ['0', '7..3650'],
            'negative' => ['-1', '7..3650'],
            'too small' => ['6', '7..3650'],
            'too large' => ['4000', '7..3650'],
            'not a number' => ['abc', '整数'],
            'fractional' => ['7.5', '整数'],
        ];
    }

    /**
     * `pruneExecutions` 是第四个「按 days 删东西」的入口，前三个（审计/系统/登录日志）已经
     * 收进 `RetentionGuard`，它自己还留着一份 `min(365, max(1, (int) input('days', 30)))`：
     * 漏传、传 0、传负数统统夹成 **1 天**，而服务端 `dry_run` 默认 `false`（只有前端默认预览），
     * 于是一次手误 = 立刻删掉昨天之前的全部执行记录 + webhook 日志 + 旧版本历史。
     * 「too small / too large」两条是这份夹取独有的第二种坏法：下限 1、上限 365 与另外三处
     * （7..3650）都对不上，同一个入参在四个清理入口里含义各不相同。
     *
     * 种子与真实删除都别碰：这一条只验 400 分支（判定必须在任何计数与 DELETE 之前返回），
     * 并且**恒带 dry_run=1**，这样即使闸门失效也不会动开发库里的一行。
     */
    #[DataProvider('badPruneDays')]
    public function test_prune_refuses_instead_of_clamping_a_destructive_days(string $raw, string $needle): void
    {
        $query = ['dry_run' => '1'];
        if ($raw !== '__missing__') {
            $query['days'] = $raw;
        }

        $res = $this->call('pruneExecutions', $query);

        self::assertSame(400, (int) ($res['body']['code'] ?? 0), 'days=' . $raw . ' 被夹成了一个能删的数');
        self::assertStringContainsString($needle, (string) ($res['body']['msg'] ?? ''));
        // 拒绝得足够早：连「窗口里有多少行」都没数，就更没有删。
        self::assertArrayNotHasKey('executions_count', $res['data']);
        self::assertArrayNotHasKey('cutoff', $res['data']);
    }

    /**
     * 合法 days 照常放行（上条用例的反向对照：闸门若把一切拒了，它也绿）。
     * 预览模式下只会 COUNT，不写不删。
     */
    public function test_prune_still_runs_in_preview_for_an_in_range_days(): void
    {
        $res = $this->call('pruneExecutions', ['days' => '30', 'dry_run' => '1']);

        self::assertSame(200, $res['status']);
        self::assertSame(30, (int) ($res['data']['days'] ?? -1));
        self::assertTrue((bool) ($res['data']['dry_run'] ?? false));
        self::assertSame(0, (int) ($res['data']['executions_deleted'] ?? -1), '预览模式不该删行');
    }

    /**
     * 导出产物的 `total` 数的是**文件里装了几条**，而 `$items` 恒被 `LIMIT ?` 截断
     * （管理端固定发 500）。窗口里有 3 条、文件里只有 2 条时，产物上写着 `total: 2` ——
     * 拿着这份 JSON 做对账的人读到的是「系统里只有 2 条执行」，而且没有任何一处说漏了。
     * 同一条线在 CSV 上的口径（AGENTS §4）：头已发出的产物要把缺项写进正文，这里同理。
     */
    public function test_the_export_artifact_says_what_it_left_out(): void
    {
        $wf = self::TAG . '-export';
        $this->seedFailedExecutions($wf, 3);

        $data = $this->call('executionsExport', ['workflow' => $wf, 'days' => '7', 'limit' => '2'])['data'];

        self::assertSame(2, (int) ($data['total'] ?? -1), 'total 该是文件里的条数');
        self::assertCount(2, (array) ($data['executions'] ?? []));
        self::assertSame(3, (int) ($data['window_rows'] ?? -1), '产物没说清窗口里一共几条');
        self::assertTrue((bool) ($data['truncated'] ?? false), '只写了 3 条里的 2 条却说自己写完了');
    }

    /** 没截断时 `truncated` 恒为 false：缺键与「没截断」在客户端无法区分。 */
    public function test_the_export_artifact_reports_no_truncation_when_the_window_fits(): void
    {
        $wf = self::TAG . '-export-full';
        $this->seedFailedExecutions($wf, 2);

        $data = $this->call('executionsExport', ['workflow' => $wf, 'days' => '7', 'limit' => '10'])['data'];

        self::assertSame(2, (int) ($data['total'] ?? -1));
        self::assertSame(2, (int) ($data['window_rows'] ?? -1));
        self::assertFalse((bool) ($data['truncated'] ?? true));
    }

    /* ==================== 工具 ==================== */

    private function seedAlert(string $workflow, string $level): void
    {
        $this->alertIds[] = (int) Db::insert(
            "INSERT INTO kode_lowcode_workflow_alerts
                (workflow_name, metric, level, current_value, limit_value, ratio, message, webhook_status, webhook_http_code)
             VALUES (?, 'daily_exec', ?, 120, 100, 1.2, 'probe', 'skipped', 0)",
            [$workflow, $level]
        );
    }

    private function seedRegression(string $workflow, string $level): void
    {
        $this->regressionIds[] = (int) Db::insert(
            "INSERT INTO kode_lowcode_workflow_step_regressions
                (workflow_name, step_name, current_avg_ms, previous_avg_ms, change_pct,
                 current_p95_ms, previous_p95_ms, current_count, previous_count, level)
             VALUES (?, 'zz-step', 300, 100, 200, 500, 150, 10, 10, ?)",
            [$workflow, $level]
        );
    }

    private function seedDeadLetter(string $workflow): void
    {
        $this->logIds[] = (int) Db::insert(
            "INSERT INTO kode_lowcode_workflow_webhook_logs
                (workflow_id, workflow_name, execution_id, target_url, status, http_code, duration_ms,
                 error, request_body, retry_count, max_retries)
             VALUES (0, ?, 0, 'https://example.test/zz', 'dead_letter', 500, 12, 'probe', '{}', 3, 3)",
            [$workflow]
        );
    }

    /**
     * 批量造失败执行：一条 `INSERT ... SELECT generate_series`，几千行不必跑几千次往返。
     * created_at 随序号递减，于是「被 LIMIT 截掉的那一条」是**确定**的（最老的），
     * 而不是靠数据库恰好怎么排。
     */
    private function seedFailedExecutions(string $workflow, int $count): void
    {
        $this->executionSeeds[] = $workflow;
        Db::statement(
            "INSERT INTO kode_lowcode_workflow_executions
                (workflow_name, workflow_id, status, error, step_results_json, created_at)
             SELECT ?, 0, 'failed', 'zz probe: upstream timed out after ' || g || ' ms',
                    '{\"step_a\":{\"error\":\"upstream 503\"}}',
                    NOW() - (g || ' second')::interval
             FROM generate_series(1, ?) AS g",
            [$workflow, $count]
        );
    }

    /**
     * 批量造投递日志，形状是这条断言的一半：
     * `$outsideSample` 条状态唯一（pending）且时间**最早**，所以它们必定落在 `LIMIT` 抽样之外。
     * 「数全集」能数到它们，「数抽样」恒数不到 —— 不用比总数就能看出口径错了。
     */
    private function seedDeliveries(string $workflow, int $inSample, int $outsideSample): void
    {
        $this->webhookSeeds[] = $workflow;
        Db::statement(
            "INSERT INTO kode_lowcode_workflow_webhook_logs
                (workflow_id, workflow_name, execution_id, target_url, status, http_code,
                 duration_ms, error, request_body, retry_count, max_retries, created_at)
             SELECT 0, ?, 0, 'https://example.test/zz-delivery',
                    CASE WHEN g > ? THEN 'pending' ELSE 'delivered' END,
                    CASE WHEN g > ? THEN 0 ELSE 200 END,
                    10, '', '{}', 0, 3,
                    NOW() - (g || ' second')::interval
             FROM generate_series(1, ?) AS g",
            [$workflow, $inSample, $inSample, $inSample + $outsideSample]
        );
    }

    /**
     * 造一条「定义里有 N 个步骤」的工作流（死代码榜的对照面来自 steps_json，不是执行行）。
     * `status = 1` 是 pathMining 取定义的那条 WHERE 的门槛，漏了就整条断言空转。
     */
    private function seedWorkflowDef(string $workflow, array $stepNames): void
    {
        $steps = array_map(
            fn(string $n): array => ['name' => $n, 'type' => 'log'],
            $stepNames
        );
        $this->defIds[] = (int) Db::insert(
            "INSERT INTO kode_lowcode_workflows (name, description, steps_json, on_fail_json, step_count, status)
             VALUES (?, 'probe', ?, '[]', ?, 1)",
            [$workflow, json_encode($steps, JSON_UNESCAPED_UNICODE), count($stepNames)]
        );
    }

    /** 造一条执行记录，step_results_json 里只有给定的那几个步骤名（值为一次成功的结果）。 */
    private function seedExecutionWithSteps(string $workflow, array $stepNames): void
    {
        $results = [];
        foreach ($stepNames as $n) {
            $results[$n] = ['type' => 'log', 'success' => true, 'duration_ms' => 5];
        }
        if (!in_array($workflow, $this->executionSeeds, true)) {
            $this->executionSeeds[] = $workflow;
        }
        Db::insert(
            "INSERT INTO kode_lowcode_workflow_executions
                (workflow_name, workflow_id, status, step_count, duration_ms, created_at, step_results_json)
             VALUES (?, 0, 'succeeded', ?, 5, NOW(), ?)",
            [$workflow, count($stepNames), json_encode($results, JSON_UNESCAPED_UNICODE)]
        );
    }

    /**
     * 批量造「能被这批入口各自的条件接住」的执行行：
     * `step_count > 0`（stepStats 的门槛）、`step_results_json <> '{}'`（其余入口的门槛）、
     * 状态落在 `failed/timeout/cancelled`（failureAnalysis 的门槛）、带 `_attempt`（重试分析读它）。
     * 少任何一项，对应入口的 `window_rows` 就数不到种子，那条断言会空转。
     */
    private function seedScanRows(int $count): void
    {
        $wf = self::TAG . '-scan';
        $this->executionSeeds[] = $wf;
        Db::statement(
            "INSERT INTO kode_lowcode_workflow_executions
                (workflow_name, workflow_id, status, step_count, duration_ms, error, created_at, step_results_json)
             SELECT ?, 0,
                    CASE WHEN g % 3 = 0 THEN 'failed' WHEN g % 3 = 1 THEN 'timeout' ELSE 'cancelled' END,
                    2, 40 + (g % 7), 'zz scan probe ' || g,
                    NOW() - (g || ' second')::interval,
                    '{\"api_a\":{\"type\":\"api_call\",\"url\":\"https://example.test/zz-api\",\"duration_ms\":'
                    || g || ',\"success\":false,\"_attempt\":2,\"error\":\"HTTP 503 upstream unavailable\"},'
                    || '\"calc_b\":{\"type\":\"calc\",\"duration_ms\":3,\"success\":true,\"value\":1}}'
             FROM generate_series(1, ?) AS g",
            [$wf, $count]
        );
    }

    /**
     * 这些入口的载荷是 `$this->ok(['data' => [...]])`，剥完信封还有一层 `data`
     * （管理端 `res.data?.data ?? res.data` 读的就是它），这里同口径取一次。
     *
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>, data: array<string, mixed>}
     */
    private function call(string $handler, array $query): array
    {
        $request = (new ServerRequest('GET', '/api/workflow/probe?' . http_build_query($query)))
            ->withQueryParams($query)
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => self::TAG, 'roles' => ['admin']]);
        HttpRequest::setRequest($request);

        $response = (new WorkflowBridgeController())->{$handler}();
        self::assertInstanceOf(ResponseInterface::class, $response);

        $body = (array) json_decode((string) $response->getBody(), true);
        $payload = $body['data'] ?? [];

        return [
            'status' => $response->getStatusCode(),
            'body' => $body,
            'data' => (array) ($payload['data'] ?? $payload),
        ];
    }
}
