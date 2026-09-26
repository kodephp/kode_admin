<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * 插件代码编辑器一圈端点的「取参器」契约（#68 `json()` 那条线的姊妹案）。
 *
 * `Controller::param()` 与 `query()` 长得几乎一样，读的来源却毫无交集：
 * 前者只读 `_route_params`（= URL pattern 里 `{x}` 占位符匹配到的值），
 * 后者才读查询串。于是把 `param()` 写在一条**没有对应占位符**的路由上，
 * 取到的恒是 null —— 不报错、不 500，只是那个参数永久等于默认值。
 *
 * 实测三个端点全是这个形状（前端 `api/kode.ts` 一律以 `?path=` / `?keyword=` / `?page=` 发查询串）：
 *  - `GET /api/plugins/{name}/files/read`：`param('path')` 恒空 ⇒ 「路径非法」400 **无条件返回**，
 *    于是编辑器点开任何文件都是 400，抽屉永远打不开（这就是「/code-editor 打不开」的真身）；
 *  - `GET /api/plugins/{name}/code-search` 与 `GET /api/plugins/code-search`：`param('keyword')` 恒空
 *    ⇒ 恒 400「关键词必填」，搜索框敲什么都报「搜索失败」；
 *  - `GET /api/plugins/{name}/logs`：`param('page'/'size'/'level')` 恒空 ⇒ 翻页永久停在第一页，
 *    看着像「一共只有 20 条」，而 total 走的是 COUNT(\*) —— 数字对得上，列表却翻不动。
 *
 * 所以每条都正反两面测：给了查询串必须读到值（挡住「恒默认值」），
 * 缺参/越界必须仍是 400（挡住「顺手把守卫也放宽」）。
 */
final class PluginFileReadParamTest extends TestCase
{
    protected bool $independentApp = true;

    /** 本地必定存在的插件与它目录下的一个文件（path 相对 `plugins/`，所以带目录前缀）。 */
    private const PLUGIN = 'frontend';

    private const FILE = 'frontend/WorkflowBridgeController.php';

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

    /* ==================== files/read：编辑器读文件 ==================== */

    /** 前端以 `?path=` 发查询串，这条就必须真读得到源码。 */
    public function test_the_editor_reads_a_file_given_by_the_query_string(): void
    {
        $res = $this->call('fileRead', ['name' => self::PLUGIN], ['path' => self::FILE]);

        self::assertSame(200, $res['status'], '读文件恒 400 == 代码编辑器永远打不开：' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $data = (array) ($res['body']['data'] ?? []);
        self::assertTrue($data['ok'] ?? null, 'Explorer 自己判了失败：' . (string) ($data['message'] ?? ''));
        self::assertStringContainsString(
            'class WorkflowBridgeController',
            (string) ($data['content'] ?? ''),
            '读回来的不是这个文件的内容（取到空串说明 path 又被别的取参器吞了）'
        );
        self::assertGreaterThan(0, (int) ($data['size'] ?? 0));
    }

    /** 守卫不放宽：越界与缺参仍是 400，未知插件仍是 404。 */
    public function test_the_path_guard_still_rejects_traversal_and_missing(): void
    {
        $traversal = $this->call('fileRead', ['name' => self::PLUGIN], ['path' => '../../app/admin/routes.php']);
        self::assertSame(400, $traversal['status'], '越界路径必须 400，不能落到 Explorer 里再判');

        $empty = $this->call('fileRead', ['name' => self::PLUGIN], []);
        self::assertSame(400, $empty['status'], '缺 path 必须 400（恒 400 才是这次的病，缺参 400 是药）');

        $unknown = $this->call('fileRead', ['name' => 'zz-no-such-plugin'], ['path' => self::FILE]);
        self::assertSame(404, $unknown['status']);
    }

    /* ==================== code-search：两个搜索入口 ==================== */

    public function test_code_search_takes_its_keyword_from_the_query(): void
    {
        $hit = $this->call('codeSearch', ['name' => self::PLUGIN], ['keyword' => 'webhookDeliveryStats']);

        self::assertSame(200, $hit['status'], '搜索恒 400 == 搜索框永远报「搜索失败」');
        self::assertSame('webhookDeliveryStats', (string) ($hit['body']['data']['keyword'] ?? ''));
        self::assertGreaterThan(0, (int) ($hit['body']['data']['total'] ?? 0), '关键词读到了却搜不出行，说明扫描面变了');

        $missing = $this->call('codeSearch', ['name' => self::PLUGIN], []);
        self::assertSame(400, $missing['status']);
    }

    public function test_global_code_search_takes_its_keyword_from_the_query(): void
    {
        $hit = $this->call('globalCodeSearch', [], ['keyword' => 'webhookDeliveryStats']);

        self::assertSame(200, $hit['status']);
        self::assertGreaterThan(0, (int) ($hit['body']['data']['total'] ?? 0));

        $missing = $this->call('globalCodeSearch', [], []);
        self::assertSame(400, $missing['status'], '缺关键词要 400，而不是搜出全仓');
    }

    /* ==================== logs：翻页参数 ==================== */

    /** `page`/`size` 来自查询串；恒取默认值就是「翻不动页」那个形状。 */
    public function test_the_log_pager_reads_page_and_size_from_the_query(): void
    {
        $res = $this->call('logs', ['name' => self::PLUGIN], ['page' => '3', 'size' => '7']);

        self::assertSame(200, $res['status']);
        self::assertSame(3, (int) ($res['body']['page'] ?? 0), 'page 没读进来：列表会永远停在第一页');
        self::assertSame(7, (int) ($res['body']['size'] ?? 0), 'size 没读进来');

        $deep = $this->call('logs', ['name' => self::PLUGIN], ['page' => '9223372036854775807']);
        self::assertSame(400, $deep['status'], 'PageWindow 的 400 分支不在这条线上就白接了');
    }

    /* ==================== 工具 ==================== */

    /**
     * 直接调控制器方法（绕开中间件，同仓内其它控制器用例的口径），
     * 但**如实还原路由给的两种来源**：`_route_params` 装 pattern 占位符，查询串装 `?k=v`。
     * 只注入其中一个的话，`param()`/`query()` 的失配就测不出来。
     *
     * @param array<string, string> $routeParams
     * @param array<string, string> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function call(string $handler, array $routeParams, array $query): array
    {
        $request = (new ServerRequest('GET', '/api/plugins/probe?' . http_build_query($query)))
            ->withQueryParams($query)
            ->withAttribute('_route_params', $routeParams)
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => 'zz-param', 'roles' => ['admin']]);
        HttpRequest::setRequest($request);

        $response = (new \app\admin\controllers\PluginController())->{$handler}();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }
}
