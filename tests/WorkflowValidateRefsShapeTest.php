<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Plugins\frontend\WorkflowBridgeController;
use Psr\Http\Message\ResponseInterface;

/**
 * `POST /api/workflow/definitions/validate-refs` 的 `errors` / `warnings` 两键必须是**列表**。
 *
 * 修之前是 `array_filter($issues, fn($i) => $i['type'] === 'error')` —— `array_filter` 保留原键，
 * 而 `$issues` 里 error 与 warning 是**混着追加**的（按步骤顺序：某步前向引用先记 warning、
 * 下一步引用不存在的步骤记 error），于是筛出来的数组键是 `[1]` 而不是 `[0]`。
 * `json_encode` 对空洞键出的不是 `[` 而是 `{`：`{"1":{...}}`。
 *
 * 这一列在管理端唯一的消费点就是数长度
 * （`views/kode/workflow-bridge/index.vue` 的 `validateStepRefs()`：
 * `${errors.length} 个错误，${warnings.length} 个警告`）—— 对象没有 `length`，
 * 于是提示条渲染成「引用验证失败：undefined 个错误，undefined 个警告」，
 * 而用户看到的正是那句「验证失败」却拿不到任何计数（同 §7「形状恒定」那条：
 * 客户端按列表读，出参就不许在两种 JSON 类型之间来回跳）。
 *
 * 现在两键都过 `array_values()`。断言打在**响应正文的 JSON** 上（`"errors":[`），
 * 因为「PHP 里是个数组」不是契约，「线上是数组还是对象」才是 —— 前者用 `?? []` 就能糊过去。
 */
final class WorkflowValidateRefsShapeTest extends TestCase
{
    protected bool $independentApp = true;

    private const TAG = 'zz-refs';

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
     * 一条 warning 排在前、一条 error 排在后 —— 正是让 `array_filter` 留下键空洞的形状。
     *
     * `alpha` 前向引用了后面才定义的 `beta`（warning），`beta` 引用了根本不存在的步骤（error）。
     */
    public function test_error_and_warning_lists_stay_lists_when_issues_are_mixed(): void
    {
        $data = $this->validate([
            ['name' => 'alpha', 'type' => 'log', 'config' => ['message' => 'got {{beta.result.v}}']],
            ['name' => 'beta', 'type' => 'log', 'config' => ['message' => 'got {{nowhere.result.v}}']],
        ]);

        self::assertFalse($data['valid'] ?? null, '有一处引用不存在的步骤，valid 必须是 false');
        self::assertSame(2, $data['total_issues'] ?? null, '问题总数与两列之和对不上');

        self::assertCount(1, $data['errors']);
        self::assertCount(1, $data['warnings']);
        self::assertSame('error', $data['errors'][0]['type'] ?? null, 'errors 列里混进了别的类型');
        self::assertSame('warning', $data['warnings'][0]['type'] ?? null, 'warnings 列里混进了别的类型');
    }

    /**
     * 正向对照：全是 warning 时（键恰好连续），旧写法也不会变形 ——
     * 这条必须恒绿，否则上一条就是在测夹具而不是测判据。
     */
    public function test_a_warning_only_run_still_reports_zero_errors(): void
    {
        $data = $this->validate([
            ['name' => 'alpha', 'type' => 'log', 'config' => ['message' => 'got {{beta.result.v}}']],
            ['name' => 'beta', 'type' => 'log', 'config' => ['message' => 'params.x is fine']],
        ]);

        self::assertTrue($data['valid'] ?? null, '只有 warning 时判定应当是通过');
        self::assertSame([], $data['errors'] ?? null, '没有 error 时 errors 必须是空列表');
        self::assertCount(1, $data['warnings'] ?? []);
    }

    /**
     * 静态口径：插件的出参列不许是「保留键的 array_filter」的结果。
     *
     * 只对 `plugins/` 生效：那里的载荷都是给管理端按列表读的。`app/support/PluginContract.php`
     * 的 `'groups' => array_filter(...)`、`PluginMetrics.php` 的 `'method_distribution'` 是**按字符串键
     * 分组**的关联数组（对象本来就是它们的形状），所以这条线不越界到 app/。
     */
    public function test_no_plugin_response_column_is_a_key_preserving_filter(): void
    {
        $files = glob(dirname(__DIR__) . '/plugins/*.php') ?: [];
        $files = array_merge($files, glob(dirname(__DIR__) . '/plugins/*/*.php') ?: []);
        self::assertGreaterThanOrEqual(15, count($files), '扫描面太小：这条门禁没看到插件代码');
        self::assertContains(
            dirname(__DIR__) . '/plugins/frontend/WorkflowBridgeController.php',
            $files,
            '被修的控制器不在扫描面上：glob 的层级假设不对'
        );

        $bare = [];
        $allowed = 0;
        foreach ($files as $file) {
            $code = self::stripComments((string) file_get_contents($file));
            if (str_contains($code, 'array_values(array_filter(')) {
                $allowed++;
            }
            foreach (self::bareFilterColumns($code) as $key) {
                $bare[] = basename($file) . " → '{$key}'";
            }
        }

        self::assertGreaterThan(0, $allowed, '合法形状一次都没命中：判据可能写错了');
        self::assertSame([], $bare, '出参列直接用了 array_filter：键空洞会让该列在 JSON 里变成对象');

        // 反对照：修复前的那一行必须被同一个判据点名。
        $before = <<<'PHP'
        return $this->ok(['data' => [
            'errors' => array_filter($issues, fn($i) => $i['type'] === 'error'),
        ]]);
        PHP;
        self::assertSame(['errors'], self::bareFilterColumns($before), '旧写法必须点名，否则这条门禁是空的');
    }

    /**
     * 出参里形如 `'key' => array_filter(...)`（没有被 `array_values(` 包住）的列名。
     *
     * @return list<string>
     */
    private static function bareFilterColumns(string $code): array
    {
        preg_match_all("/'([a-z_]+)'\s*=>\s*(array_values\(\s*)?array_filter\(/", $code, $m, PREG_SET_ORDER);

        $keys = [];
        foreach ($m as $one) {
            if (($one[2] ?? '') === '') {
                $keys[] = $one[1];
            }
        }

        return $keys;
    }

    /** 剥掉注释：文档里那句「修之前是 array_filter(...)」不是实现命中。 */
    private static function stripComments(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    private function validate(array $steps): array
    {
        $res = $this->call($steps);

        self::assertSame(200, $res['status'], '引用校验没回 200：下面的断言是按载荷读的');
        // 该 handler 走 `ok(['data' => [...]])`，信封的 data 里还有一层 data
        // （前端 `res.data ?? res` 的两级兜底就是在接这个形状）。
        $payload = $res['body']['data']['data'] ?? [];
        self::assertArrayHasKey('errors', $payload, '出参形状变了：没有 errors 键');

        // 断言的是**线上**那一列的 JSON 类型：管理端只数 `.length`，而 array_filter 的键空洞
        // 会让 json_encode 把它写成 `{...}` —— 对象没有 length，提示条就成了「undefined 个错误」。
        self::assertMatchesRegularExpression(
            '/"errors":\[/',
            $res['raw'],
            'errors 在 JSON 里不是数组：问题顺序会让它变成带键空洞的对象'
        );
        self::assertMatchesRegularExpression(
            '/"warnings":\[/',
            $res['raw'],
            'warnings 在 JSON 里不是数组：同上一条'
        );

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @return array{status: int, body: array<string, mixed>, raw: string}
     */
    private function call(array $steps): array
    {
        $request = (new ServerRequest('POST', '/api/workflow/definitions/validate-refs'))
            ->withParsedBody([
                'steps' => $steps,
                'workflow_name' => self::TAG . '-wf',
            ])
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => self::TAG, 'roles' => ['admin']]);
        HttpRequest::setRequest($request);

        $response = (new WorkflowBridgeController())->validateStepRefs();
        self::assertInstanceOf(ResponseInterface::class, $response);

        $raw = (string) $response->getBody();

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode($raw, true),
            'raw' => $raw,
        ];
    }
}
