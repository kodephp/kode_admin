<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Plugins\frontend\WorkflowBridgeController;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * 工作流诊断面板的「时间窗口径」契约（AGENTS.md §4「凡是聚合就必须说清统计窗口」的读侧分支）。
 *
 * 这一族入口都收 `days`，并把越界值**静默夹到本面板的上下界**（读侧的夹取是保护，不是越权：
 * 它限的是这次扫描的代价，口径同 §4 分页里「size 夹到上限」）。缺陷不在夹取，而在**没人说出
 * 夹成了几天**：调用方发 `?days=999`，面板画的是最近 30 天的数，而响应里既没有 `days`
 * 也没有别的窗口声明 —— 于是「近 999 天」和「近 30 天」在页面上长得一模一样，
 * 而 30 天那一档恰恰是因为它最贵（`ScanBudget` 那条 128M 红线）才被夹住的。
 *
 * 口径：读了 `days` 的入口必须在响应里回显**生效值**（键名 `days`，与本文件既有的三十几处一致），
 * 且**每个返回分支都带**（空窗口/数据不足那些早退分支同样，缺键与「老后端」在客户端无法区分）。
 *
 * 两层判据各挡一种错法：
 *  - 静态门禁扫**全部**方法（新增第 12 个入口时自动生效），并拿已知 11 个名字当正对照——
 *    否则「扫到 0 个需要回显的方法」和「全部合规」输出一模一样；
 *  - 行为断言真调两个 handler，断言回显的是**夹过之后**的值而不是提交值
 *    （只 grep `'days' =>` 的话，回显原始 `Request::input('days')` 也能过，而那正是假的口径）。
 */
final class WorkflowWindowDeclarationTest extends TestCase
{
    protected bool $independentApp = true;

    private const TAG = 'zz-wfwin';

    /** 本轮修的那 11 个入口：静态扫描必须真的看见它们（正对照）。 */
    private const SILENT_HANDLERS = [
        'alertRuleCheck',
        'pathMining',
        'inputProfile',
        'capacityForecast',
        'dataLineage',
        'executionClustering',
        'healthTrend',
        'inputValidation',
        'costAllocation',
        'replayComparison',
        'idempotencyCheck',
    ];

    /**
     * 载荷是**变量**的入口（`return $this->ok(['data' => $export])`）：声明写在拼装那几行里，
     * 不在 return 表达式里。判据必须认这种形状，否则会逼人往 return 上再粘一行重复的声明；
     * 但它同时也是「按分支判」最容易漏的一类，所以单独点名并配一条反对照。
     */
    private const VARIABLE_PAYLOAD_HANDLERS = [
        'executionsExport',
        'pruneExecutions',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        HttpRequest::clear();

        parent::tearDown();
    }

    /* ==================== 静态口径：读了 days 就得回显 days ==================== */

    public function test_every_handler_that_windows_by_days_declares_its_window(): void
    {
        [$readers, $silent] = $this->scanWindowHandlers();

        self::assertNotEmpty(
            $readers,
            '一个读 days 的方法都没扫到：判据空转（多半是取参写法变了，同步改这条守卫）'
        );

        // 正对照：这一族必须全在扫描面上，漏一个就等于门禁没覆盖它。
        foreach (array_merge(self::SILENT_HANDLERS, self::VARIABLE_PAYLOAD_HANDLERS) as $name) {
            self::assertContains($name, $readers, "静态扫描没把 {$name} 认成 days 窗口入口");
        }

        self::assertSame(
            [],
            $silent,
            '这些入口夹了 days 却没在每个返回分支里回显生效窗口：' . implode(', ', $silent)
        );
    }

    /**
     * 反对照：把某个入口**其中一个分支**的回显真的剥掉，门禁必须点名它。
     *
     * 挑「早退分支」而不是全部剥掉，是为了证明判据是按分支算的：
     * 按方法体算的判据在这种情况下会放过它（正常分支还在回显），
     * 而「空窗口不说口径」正是用户最常看到的那半张面板。
     */
    public function test_the_gate_notices_a_handler_that_stops_declaring_its_window(): void
    {
        $source = $this->controllerSource();
        $bodies = $this->sliceMethods($source);
        $name = self::SILENT_HANDLERS[1];

        self::assertArrayHasKey($name, $bodies, "handler {$name} 不在扫描面上：这条反对照要先跟着搬");

        $before = substr_count($bodies[$name], "'days' => \$days,");
        self::assertGreaterThanOrEqual(
            2,
            $before,
            "{$name} 不到两个分支带回显，这条反对照测不出「按分支判」这件事（当前 {$before} 处）"
        );

        $stripped = $this->stripDaysEcho($bodies[$name]);
        self::assertSame($before - 1, substr_count($stripped, "'days' => \$days,"), '篡改没剥掉恰好一处');

        // 用同一条判据重扫整份源码，它必须把这一处点名出来。
        [, $silent] = $this->scanWindowHandlers($this->sliceMethods($this->spliceMethod($source, $name, $stripped)));
        self::assertContains($name, $silent, '某个分支静默夹取窗口却没被门禁发现');
    }

    /**
     * 反对照（变量载荷那一族）：把拼装载荷的那几行里的声明抹掉，门禁必须点名。
     *
     * 这条用例是给「载荷是变量就去看方法体上半段」这类宽松判据把门的：
     * 宽松本身看不见——不加这条，把判据改成「整份方法体里出现过 days 就算回显」照样全绿，
     * 而那时「早退分支不说口径」就又回来了。
     */
    public function test_the_gate_notices_a_variable_payload_handler_that_stops_declaring_its_window(): void
    {
        $source = $this->controllerSource();

        foreach (self::VARIABLE_PAYLOAD_HANDLERS as $name) {
            $bodies = $this->sliceMethods($source);
            self::assertArrayHasKey($name, $bodies, "handler {$name} 不在扫描面上：这条反对照要先跟着搬");

            // 前提：原样必须判绿，否则「剥掉之后变红」测的是别的东西。
            [, $before] = $this->scanWindowHandlers($bodies);
            self::assertNotContains($name, $before, "{$name} 本来就没回显窗口，这条反对照是空转的");

            $stripped = $this->stripAllDaysEcho($bodies[$name]);
            self::assertNotSame($bodies[$name], $stripped, "篡改没生效：{$name} 里找不到窗口声明");

            [, $silent] = $this->scanWindowHandlers($this->sliceMethods($this->spliceMethod($source, $name, $stripped)));
            self::assertContains($name, $silent, "{$name} 的载荷不再带窗口，门禁却没点名");
        }
    }

    /* ==================== 行为口径：回显的是生效值 ==================== */

    /**
     * @return array{string, array, int}[]
     */
    public static function windowCases(): array
    {
        // [handler, 提交的查询, 期望的生效窗口]
        // 上限两侧都取：夹小（挡代价）与夹大（补默认值）都必须照实说。
        return [
            '越上限被夹到 30' => ['pathMining', ['days' => 999, 'workflow' => self::TAG . '-none'], 30],
            '越下限被夹到 1' => ['pathMining', ['days' => 0, 'workflow' => self::TAG . '-none'], 1],
            '漏传回落默认 7' => ['pathMining', ['workflow' => self::TAG . '-none'], 7],
            '本面板下限是 7' => ['capacityForecast', ['days' => 1], 7],
            '本面板上限是 90' => ['capacityForecast', ['days' => 100000], 90],
            // 变量载荷那一族也要真的把窗口带出去：静态门禁只证明源码里写了这一行。
            '导出产物带顶层窗口' => ['executionsExport', ['days' => 999, 'workflow' => self::TAG . '-none'], 90],
        ];
    }

    #[DataProvider('windowCases')]
    public function test_the_response_echoes_the_effective_window_not_the_submitted_one(
        string $handler,
        array $query,
        int $expectedDays
    ): void {
        $res = $this->call($handler, $query);

        self::assertSame(200, $res['status'], "{$handler} 没走到正常响应");
        self::assertArrayHasKey('days', $res['data'], "{$handler} 的响应没说出它统计的是哪几天");
        self::assertSame(
            $expectedDays,
            $res['data']['days'] ?? null,
            "{$handler} 回显的窗口与实际扫描的窗口不是一回事（查询 " . json_encode($query) . '）'
        );
    }

    /**
     * 早退分支也要带声明：空窗口/数据不足时面板画的是「暂无数据」，
     * 那句「暂无数据」的口径仍然是某个窗口，缺键就退化成「老后端」。
     */
    public function test_the_early_return_branches_also_declare_the_window(): void
    {
        // pathMining 在没有匹配执行记录时走 `scanned_rows === 0` 的早退分支。
        $res = $this->call('pathMining', ['workflow' => self::TAG . '-definitely-none', 'days' => 3]);

        self::assertSame(200, $res['status']);
        self::assertSame(
            0,
            $res['data']['window_rows'] ?? null,
            '这条用例的前提（空窗口）不成立了：库里造出了行，早退分支没被走到'
        );
        self::assertSame(3, $res['data']['days'] ?? null, '早退分支没回显窗口');
    }

    /* ==================== helpers ==================== */

    /**
     * 扫控制器源码：谁读了 days，谁的**每个返回分支**把生效窗口说了出来。
     *
     * 判据落在分支上而不是方法体上，是因为真实缺陷有两种形状：整个方法都没回显，
     * 以及「正常分支回显了、早退分支没回显」——后者在方法级扫描里是看不见的，
     * 而空窗口恰恰是被夹取保护的那一类请求最常走的路径。
     *
     * 回显必须**引用那个生效变量**（`… days… => \$days` 或 `compact(…, 'days')`）：
     * 只匹配键名的话，`'days_available' => count($daily)`（有几天的数据）会被误当成
     * 「说了统计的是哪几天」（窗口是几天）。
     *
     * @param array<string, string>|null $bodies 已切好的方法体（反对照用它传篡改后的源码）
     * @return array{list<string>, list<string>} [读了 days 的方法名, 其中至少一个分支没回显的]
     */
    private function scanWindowHandlers(?array $bodies = null): array
    {
        $bodies ??= $this->sliceMethods($this->controllerSource());

        $readers = [];
        $silent = [];
        foreach ($bodies as $name => $body) {
            if (!preg_match("/(?:Request::)?(?:input|query|post)\(\s*'days'/", $body)) {
                continue;
            }
            $readers[] = $name;

            foreach ($this->successBranches($body) as $branch) {
                if (!$this->branchDeclaresWindow($branch)) {
                    $silent[] = $name;
                    break;
                }
            }
        }

        return [$readers, $silent];
    }

    /**
     * 这一条成功返回有没有把生效窗口说出来。
     *
     * 载荷是字面量时，声明必须就在那条 return 的括号里；载荷是**变量**时
     * （`return $this->ok(['data' => $export])`，导出/清理这类先拼装再返回的形状）
     * 声明写在拼装那几行里，只看 return 表达式会把它判成没回显，逼人往 return 上再粘一行重复声明。
     *
     * 所以变量载荷改成看**这一段直线代码**（上一条成功返回到这一条之间）：
     * 不能放宽到整个方法体，否则「早退分支不说口径」又看不见了 —— 那正是本文件另一条
     * 反对照在测的东西，`test_the_gate_notices_a_variable_payload_handler…` 则是给这条宽松
     * 规则本身把门的反对照（剥掉拼装行的声明必须变红）。
     */
    private function branchDeclaresWindow(array $branch): bool
    {
        $echo = "/['\"][a-z_]*days[a-z_]*['\"]\s*=>\s*\\\$days|compact\([^)]*['\"]days['\"]/";
        if (preg_match($echo, $branch['branch'])) {
            return true;
        }

        return str_contains($branch['branch'], "['data' => \$")
            && (bool) preg_match($echo, $branch['segment']);
    }

    /**
     * 拆出方法体里每一条**带载荷**的成功返回，以及它前面的那段直线代码。
     *
     * `['data' => null]` 那种是参数错误/空守卫，它根本没走到窗口计算，不该要求它回显。
     *
     * @return list<array{branch: string, segment: string}>
     */
    private function successBranches(string $body): array
    {
        $branches = [];
        $offset = 0;
        $segmentFrom = 0;
        while (($at = strpos($body, 'return $this->ok(', $offset)) !== false) {
            $open = strpos($body, '(', $at);
            $depth = 0;
            $end = $open;
            $len = strlen($body);
            for (; $end < $len; $end++) {
                if ($body[$end] === '(') {
                    $depth++;
                } elseif ($body[$end] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }

            $branch = substr($body, $at, $end - $at + 1);
            if (!str_contains($branch, "'data' => null")) {
                $branches[] = [
                    'branch' => $branch,
                    'segment' => substr($body, $segmentFrom, $at - $segmentFrom),
                ];
            }
            $segmentFrom = $end + 1;
            $offset = $end + 1;
        }

        return $branches;
    }

    private function controllerSource(): string
    {
        $path = dirname(__DIR__) . '/plugins/frontend/WorkflowBridgeController.php';
        $real = realpath($path);
        self::assertNotFalse($real, "控制器不在预期位置：{$path}");

        $source = (string) file_get_contents($real);
        self::assertGreaterThan(100000, strlen($source), '源码没读全，扫描面是空的');

        // 注释里出现的 'days' => 会让判据把「文档里写了该回显」当成「实现里回显了」，先剥掉。
        return $this->stripComments($source);
    }

    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                // 用等量换行占位，保持行号与切块位置不变。
                $out .= str_repeat("\n", max(0, substr_count($token[1], "\n")));
                continue;
            }
            $out .= is_array($token) ? $token[1] : (string) $token;
        }

        return $out;
    }

    /**
     * 按「缩进 4 的 function 声明行」切方法体。
     *
     * 不用大括号计数：这个文件里到处是 `{$var}` 字符串插值，闭合的 `}` 会被当成方法结束，
     * 于是切出来的是碎片，判据只扫到文件开头那一小段就「全绿」了。
     *
     * @return array<string, string>
     */
    private function sliceMethods(string $source): array
    {
        $lines = explode("\n", $source);

        $starts = [];
        foreach ($lines as $i => $line) {
            if (preg_match(
                '/^    (?:public|private|protected|static)[^;]*\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
                $line,
                $m
            )) {
                $starts[] = [$i, $m[1]];
            }
        }

        $bodies = [];
        foreach ($starts as $k => [$from, $name]) {
            $to = $k + 1 < count($starts) ? $starts[$k + 1][0] - 1 : count($lines) - 1;
            // 同名方法（不同可见性的私有 helper）极少见，真撞名时后一份覆盖前一份；
            // 这里的判据是「整个类里有没有回显」，合并成一份只会更松，不会造假绿。
            $bodies[$name] = ($bodies[$name] ?? '') . implode("\n", array_slice($lines, $from, $to - $from + 1));
        }

        return $bodies;
    }

    private function stripDaysEcho(string $body): string
    {
        return preg_replace("/'days'\s*=>\s*\\\$days,?\n/", '', $body, 1);
    }

    /**
     * 剥掉一个方法里**所有**形式的窗口回显（键值行 + `compact(…, 'days')` 里的成员）。
     *
     * 只剥键值行的话，`compact('workflow', 'status', 'limit', 'days')` 还在拼装那几行里说着
     * 同一件事，判据判绿而反对照以为自己在测「没回显」——那是测了个不存在的形状。
     */
    private function stripAllDaysEcho(string $body): string
    {
        $out = preg_replace("/'[a-z_]*days[a-z_]*'\s*=>\s*\\\$days,?\n/", '', $body);
        $out = preg_replace("/,\s*'days'/", '', $out);

        return (string) $out;
    }

    private function spliceMethod(string $source, string $name, string $body): string
    {
        $bodies = $this->sliceMethods($source);
        self::assertArrayHasKey($name, $bodies);

        $tampered = str_replace($bodies[$name], $body, $source);
        self::assertNotSame($source, $tampered, '拼接没生效（方法体定位失败）');

        return $tampered;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status:int, body:array, data:array}
     */
    private function call(string $handler, array $query): array
    {
        $request = (new ServerRequest('GET', '/api/workflow/window?' . http_build_query($query)))
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
