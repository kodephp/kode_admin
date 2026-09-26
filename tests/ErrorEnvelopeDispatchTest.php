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
 * 失败响应必须把**原因**送到客户端（AGENTS.md §3「返回一律走统一信封」的另一半）。
 *
 * 缺陷形状是「走错了派发口」：`$this->ok(['data' => null], 400, ['msg' => 'workflow 参数必填'])`
 * 看着像在改 msg，其实 `ApiEnvelope::ok()` 的体是
 * `['code' => $status, 'msg' => 'ok', 'dur' => …] + $extra` —— PHP 的数组 `+` 对重复键**保留左侧**，
 * 于是 extra 里那份 `msg` 被丢掉。发出去的字节是：
 *
 *   HTTP 400 {"code":400,"msg":"ok","dur":"0.0012s","data":{"data":null}}
 *
 * 三处一起坏：① 原因没了（管理端 vben 的响应拦截器读的正是 `msg`，toast 上于是印一个「ok」，
 * 而这条响应的全部作用就是说「为什么没成」）；② `code` 与 HTTP 状态一致但 msg 说成功，
 * 任何「先看 msg 再看 code」的客户端会把它当成功；③ 载荷多剥一层 `data.data`，
 * 与同一个控制器里那 109 条 `$this->error(...)`（经 `TimingMiddleware` 归一化成 `{code,msg,dur}`）分叉。
 *
 * 所以口径只有一条：**4xx/5xx 一律走 `fail()`/`error()`**，`ok()` 的第二参不是 200 就是走错口 ——
 * 这条按源码扫（静态门禁），因为「哪条 return 是失败分支」写在代码里，不在运行时数据里；
 * 另配一条行为断言，钉住被拒的请求确实带回了原因（静态门禁会跟着重构改名，行为断言不会）。
 */
final class ErrorEnvelopeDispatchTest extends TestCase
{
    protected bool $independentApp = true;

    private const TAG = 'zz-envlp';

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

    /**
     * 被拒的请求必须带回原因，而不是 `msg: "ok"`。
     *
     * 只挑**只读**的入口：写侧那几条（`alertRuleSave`/`alertRuleDelete`）由下面的静态门禁覆盖，
     * 拿假 id 去调它们等于赌「守卫排在写之前」，用例不该赌生产代码的顺序。
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: int, 3: string}>
     */
    public static function rejectedRequests(): array
    {
        return [
            '配置历史缺 workflow' => ['configHistory', [], 400, 'workflow 参数必填'],
            '配置历史查无此流程' => ['configHistory', ['workflow' => self::TAG . '-nope'], 404, '不存在'],
            '回放对比缺 workflow' => ['replayComparison', [], 400, 'workflow 参数必填'],
            '影响分析缺 workflow' => ['changeImpact', [], 400, 'workflow 参数必填'],
            '时间线 id 非法' => ['executionTimeline', ['id' => 'zz-not-a-real-id'], 400, '无效的执行 ID'],
        ];
    }

    #[DataProvider('rejectedRequests')]
    public function test_a_rejected_request_names_the_reason(string $handler, array $query, int $status, string $needle): void
    {
        [$code, $body] = $this->dispatch($handler, $query);

        self::assertSame($status, $code, "{$handler} 的状态码不是预期的 {$status}");
        // `error()` 出 `{message}`（由中间件归一化成 `msg`），`fail()` 直接出 `msg`：
        // 两个派发口都算对，只有「带着 4xx 却从 ok() 出去」这一种算错。
        $reason = (string) ($body['msg'] ?? $body['message'] ?? '');
        self::assertNotSame('ok', $reason, "{$handler} 回的是 msg:\"ok\" —— 原因在信封里被丢掉了");
        self::assertStringContainsString($needle, $reason, "{$handler} 没说清为什么拒（期望含「{$needle}」）");
    }

    /**
     * 静态门禁：全仓任何 `->ok(` 的第二参是 4xx/5xx 字面量，就是走错了派发口。
     *
     * 判据按**实参**算而不是正则扫一行：`ok(` 的实参常跨行（本仓最多的一条写到 6 行），
     * 单行正则会漏；而「第 2 个顶层实参是不是 >=400 的整数字面量」只有配对括号切分能答。
     * 注释先 `token_get_all()` 剥掉 —— 本文件开头那句「旧写法长什么样」不是实现。
     */
    public function test_no_failure_is_sent_through_the_success_envelope(): void
    {
        $violations = [];
        $scanned = 0;
        foreach ($this->sources() as $path => $code) {
            $scanned++;
            foreach (self::misplacedFailures($path, $code) as $line) {
                $violations[] = $line;
            }
        }

        // 防空转：扫描面必须真取回文件，否则「0 条违规」和「什么都没扫」在输出上一样。
        self::assertGreaterThanOrEqual(60, $scanned, "只扫到 {$scanned} 个文件，扫描面本身塌了");
        self::assertSame([], $violations, '以下失败分支是从 ok() 发出去的，客户端只会看到 msg:"ok"');
    }

    /**
     * 正/负对照：判据必须认得出违规，也必须放过 200。
     *
     * 没有这两条，「全绿」可能只是因为括号切分器把每一行都跳过了。
     */
    public function test_the_gate_names_the_misuse_and_forgives_a_success_status(): void
    {
        $snippet = <<<'PHP'
            <?php
            class Probe
            {
                public function bad()
                {
                    return $this->ok(['data' => null], 404, ['msg' => '不存在']);
                }

                public function alsoBad()
                {
                    return $this->ok([
                        'data' => null,
                    ], 500, ['msg' => '炸了']);
                }

                public function good()
                {
                    return $this->ok(['data' => 1], 200);
                }

                public function alsoGood()
                {
                    return $this->ok(['data' => 1]);
                }
            }
            PHP;

        $hits = self::misplacedFailures('probe.php', $snippet);

        self::assertSame(
            // 行号一并钉住：跨行那条的 `->ok(` 在第 11 行，偏移算错一格就说明「按括号配对取实参」
            // 那套行号数学不能用（200 与缺省那两条必须不出现在这里）。
            ['probe.php:6 (404)', 'probe.php:11 (500)'],
            $hits,
            '违规没被点名、跨行的那条被漏掉，或者 200/缺省被误伤'
        );
    }

    /* ==================== 判据 ==================== */

    /**
     * 取回待扫的控制器源码：`app/` 与 `plugins/` 下的 PHP，键是路径、值是内容。
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        $out = [];
        foreach (['app', 'plugins'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $out[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    /**
     * 一份源码里所有「`->ok(` 的第 2 个顶层实参是 >=400 的整数字面量」。
     *
     * @return list<string> `文件:行 (状态码)`
     */
    private static function misplacedFailures(string $path, string $source): array
    {
        $clean = self::stripComments($source);
        $hits = [];
        foreach (self::callArguments($clean, '->ok(') as [$args, $offset]) {
            if (count($args) < 2) {
                continue;
            }
            $second = trim($args[1]);
            if (preg_match('/^(4|5)\d{2}$/', $second) !== 1) {
                continue;
            }
            $line = 1 + substr_count($clean, "\n", 0, $offset);
            $hits[] = basename($path) . ':' . $line . ' (' . $second . ')';
        }

        return $hits;
    }

    /**
     * 剥掉注释（只把注释抹成等长空白，代码原样留着；换行数保留所以行号不漂）。
     */
    private static function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $out .= $token;
                continue;
            }
            // $token[0] 是类型、[1] 是文本、[2] 是行号 —— 拿 [2] 比类型会**把所有代码都抹掉**，
            // 于是扫描面恒为 0 条违规（对照用例 test_the_gate_names_the_misuse… 就是抓这个的）。
            $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                ? self::blank($token[1])
                : $token[1];
        }

        return $out;
    }

    /**
     * 等长涂白：非换行字符全替成空格，长度与换行数都不变。
     */
    private static function blank(string $text): string
    {
        return (string) preg_replace('/[^\n]/u', ' ', $text);
    }

    /**
     * 按**配对括号**取出每个 `$needle` 调用的顶层实参（字符串里的逗号与括号不参与配对）。
     *
     * @return list<array{0: list<string>, 1: int}> 实参片段 + 调用起点偏移
     */
    private static function callArguments(string $code, string $needle): array
    {
        $calls = [];
        $offset = 0;
        while (($start = strpos($code, $needle, $offset)) !== false) {
            $open = $start + strlen($needle) - 1;
            $depth = 0;
            $args = [];
            $current = '';
            $inString = null;
            $i = $open;
            $len = strlen($code);
            for (; $i < $len; $i++) {
                $ch = $code[$i];
                if ($inString !== null) {
                    $current .= $ch;
                    if ($ch === '\\') {
                        $current .= $code[++$i] ?? '';
                        continue;
                    }
                    if ($ch === $inString) {
                        $inString = null;
                    }
                    continue;
                }
                if ($ch === "'" || $ch === '"') {
                    $inString = $ch;
                    $current .= $ch;
                    continue;
                }
                if ($ch === '(' || $ch === '[') {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                }
                if ($ch === ')' || $ch === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                if ($ch === ',' && $depth === 1) {
                    $args[] = $current;
                    $current = '';
                    continue;
                }
                $current .= $ch;
            }
            if ($depth === 0) {
                $args[] = $current;
                $calls[] = [self::trimTail($args), $start];
            }
            $offset = $i > $offset ? $i + 1 : $offset + 1;
        }

        return $calls;
    }

    /**
     * 丢掉最后一片实参的收尾括号（`[...])` 这种尾巴会让 `trim()` 后的字面量不等于 `200`）。
     *
     * @param list<string> $args
     * @return list<string>
     */
    private static function trimTail(array $args): array
    {
        $last = array_key_last($args);
        if ($last !== null) {
            $args[$last] = rtrim($args[$last], " \t\n\r\0\x0B)]}");
        }

        return $args;
    }

    /* ==================== 夹具 ==================== */

    /**
     * 直接派发一个 handler，返回（HTTP 状态, 解码后的响应体）。
     *
     * 这里刻意**不过** `TimingMiddleware`：归一化会把 `{message}` 换成 `{code,msg}`，
     * 那样两条派发口在断言里就分不开了。判据写在两个键上，走哪条口都算对。
     *
     * @param array<string, mixed> $query
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function dispatch(string $handler, array $query): array
    {
        $request = (new ServerRequest('GET', '/api/workflow/probe?' . http_build_query($query)))
            ->withQueryParams($query)
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => self::TAG, 'roles' => ['admin']]);
        HttpRequest::setRequest($request);

        $response = (new WorkflowBridgeController())->{$handler}();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return [$response->getStatusCode(), (array) json_decode((string) $response->getBody(), true)];
    }
}
