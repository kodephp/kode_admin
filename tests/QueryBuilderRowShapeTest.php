<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\FeatureFlagController;
use app\admin\controllers\ScheduleController;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * `Db::table()` 的行形状，以及按「对象」读它的两个读入口（AGENTS.md §6「别把框架 API 用错
 * 渲染成没有数据」）。
 *
 * `Kode\Database\Query\QueryBuilder` 的签名写得很清楚：`get(): array`、`first(): ?array` ——
 * 查询构建器交回来的是**关联数组**，不是实体。而 `app/`+`plugins/` 里用 `Db::table(` 的文件只有三个，
 * 其中两个把行当对象读了，于是：
 *
 *  - `FeatureFlagController::formatFlag(object $row)`：库里一旦有行，`GET /api/feature-flags`
 *    直接 `TypeError` → **500**（本机这张表恰好是空的，所以这个 500 一直没露头）；
 *  - `logChange(..., ?object $before)`：`update`/`destroy`/`toggle` 以及命中已存在分支的 `store`
 *    全都在**写完成之后**抛 `TypeError` → 库里改了、客户端看到 500（半套状态，实测见各用例断言）；
 *  - `(bool) $flag->enabled` 读数组属性拿到的是 null：`check()` 对任何开关恒回 `reason:"disabled"`，
 *    `stats()` 把启用中的开关数成 disabled、把 10% 灰度数成 zero_rollout，
 *    `toggle()` 的 `!$existing->enabled` 恒为 true → **只有关没有开**（点第二下仍然写 true）。
 *  - `ScheduleController::runs()` 的 `(string) $row->task_name` 同理：整列表渲染成空字段，
 *    一行数据都不报，页面上就是「有 N 条记录，每条什么都没有」。
 *
 * 这一族最坏的地方在于**没有任何一处会报错**：PHP 8 只给一条 warning，值静默变 null。
 * 所以本文件不写正则门禁，而是每个入口一条行为断言：塞一行真数据进去，要求读出来的是那行数据。
 */
final class QueryBuilderRowShapeTest extends TestCase
{
    private const FLAG = 'zz-rowshape-flag';

    private const CRON_TASK = 'zz-rowshape-task';

    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        HttpRequest::clear();
        if (Db::tableExists('kode_feature_flags')) {
            Db::table('kode_feature_flags')->where('key', self::FLAG)->delete();
        }
        if (Db::tableExists('kode_cron_runs')) {
            Db::table('kode_cron_runs')->where('task_name', self::CRON_TASK)->delete();
        }

        parent::tearDown();
    }

    /** 先把契约钉住：构建器交回的是数组。哪天它改成对象，本文件的其它断言会一起说话。 */
    public function test_the_builder_hands_back_associative_arrays(): void
    {
        $this->seedFlag(10, true);

        $row = Db::table('kode_feature_flags')->where('key', self::FLAG)->first();
        self::assertIsArray($row, 'first() 不再是数组：本文件的形状断言要跟着改');
        self::assertSame(self::FLAG, $row['key']);

        $rows = Db::table('kode_feature_flags')->where('key', self::FLAG)->get();
        self::assertIsArray($rows[0] ?? null, 'get() 的元素不再是数组');
        self::assertSame(10, (int) ($rows[0]['rollout'] ?? null));
    }

    /** 未修复实测：`TypeError: formatFlag(): Argument #1 ($row) must be of type object, array given`。 */
    public function test_the_flag_list_reads_the_row_that_is_actually_stored(): void
    {
        $this->seedFlag(10, true);

        $result = $this->send(FeatureFlagController::class, 'index');

        self::assertSame(200, $result['status'], '列表页在有数据时挂了：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $mine = $this->findFlag($result['body']['data'] ?? []);
        self::assertNotNull($mine, '列表里找不到刚建的那一行');
        self::assertSame(10, $mine['rollout']);
        self::assertSame(true, $mine['enabled']);
        self::assertSame('灰度形状回归', $mine['description']);
    }

    /** 未修复实测：`{"total":1,"enabled":0,"disabled":1,"zero_rollout":1,"recent_changed":[{"key":"",...}]}`。 */
    public function test_the_stats_panel_counts_the_row_that_is_actually_stored(): void
    {
        $this->seedFlag(10, true);

        $result = $this->send(FeatureFlagController::class, 'stats');
        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $data = (array) ($result['body']['data'] ?? []);

        self::assertGreaterThanOrEqual(1, (int) ($data['total'] ?? 0));
        self::assertGreaterThanOrEqual(1, (int) ($data['enabled'] ?? 0), '启用中的开关被数成 disabled');
        self::assertGreaterThanOrEqual(1, (int) ($data['partial_rollout'] ?? 0), '10% 灰度被数成 zero_rollout');
        self::assertSame(
            self::FLAG,
            (string) ($data['recent_changed'][0]['key'] ?? ''),
            '最近变更里 key 是空串：读数组行用了对象写法'
        );
        // 同一行里另一个字段：`$row->updated_at ?? ''` 在数组上静默给空串，
        // 于是「最近变更」列表的时间列永远空白 —— 逐字段断言，别只测第一个键。
        self::assertNotSame(
            '',
            (string) ($data['recent_changed'][0]['updated_at'] ?? ''),
            '最近变更里 updated_at 是空串：这一处还在按对象读数组行'
        );
    }

    /** 未修复实测：对任何开关恒回 `{"enabled":false,"reason":"disabled"}`，灰度判定根本没跑到。 */
    public function test_the_check_endpoint_uses_the_stored_flag(): void
    {
        $this->seedFlag(100, true);

        $result = $this->send(FeatureFlagController::class, 'check', ['key' => self::FLAG]);
        $data = (array) ($result['body']['data'] ?? []);

        self::assertSame(true, $data['enabled'] ?? null, '全量放开的开关被判成不启用：' . json_encode($data, JSON_UNESCAPED_UNICODE));
        self::assertSame('full_rollout', $data['reason'] ?? null);
    }

    /**
     * 未修复实测：`enabled` 恒被写成 true（`!null === true`），点第二下关不掉，
     * 然后 `logChange()` 抛 TypeError → 500。
     */
    public function test_toggling_actually_turns_a_flag_off(): void
    {
        $this->seedFlag(10, true);

        $result = $this->send(FeatureFlagController::class, 'toggle', [], self::FLAG);

        self::assertSame(200, $result['status'], '切换接口 500（写完成后才抛）：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(false, $this->storedEnabled(), 'toggle 没把它关掉：读旧值用了对象写法，!null 恒为 true');
    }

    /** 未修复实测：行删掉了，响应却是 500（`logChange()` 的 `?object $before`）。 */
    public function test_destroy_reports_success_after_it_deleted_the_row(): void
    {
        $this->seedFlag(10, true);

        $result = $this->send(FeatureFlagController::class, 'destroy', [], self::FLAG);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->flagRows(), '删除回成功了但行还在');
    }

    /** 未修复实测：`PUT` 写进去了但回 500（同一处 `logChange()`）。 */
    public function test_update_reports_success_after_it_wrote(): void
    {
        $this->seedFlag(10, true);

        $result = $this->send(FeatureFlagController::class, 'update', ['description' => '改过了'], self::FLAG);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('改过了', $this->storedDescription());
    }

    /**
     * `ScheduleController::runs()` 的同一族形状：未修复实测每一行的每个字段都是空串/0。
     *
     * 清单在 `data.rows` 里（这一屏的六张读面统一带 `available`/`reason`，见
     * tests/ScheduleReadFaceTest.php）：本用例只钉「行里的字段是不是那行数据」，
     * 但顺手要求 `rows` 恒是数组 —— 形状随成败变过一回，前端就会把 undefined 画成表格。
     */
    public function test_the_cron_run_list_carries_the_values_it_stored(): void
    {
        if (!Db::tableExists('kode_cron_runs')) {
            self::markTestSkipped('kode_cron_runs 不存在：本用例没有对象');
        }
        Db::table('kode_cron_runs')->insert([
            'task_name' => self::CRON_TASK,
            'status' => 'ok',
            'message' => '形状回归',
            'duration_ms' => 42,
        ]);

        $result = $this->send(ScheduleController::class, 'runs', ['task' => self::CRON_TASK]);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $data = (array) ($result['body']['data'] ?? []);
        self::assertIsArray($data['rows'] ?? null, 'rows 键缺失或不是数组：列表形状不许随成败变');
        self::assertTrue((bool) ($data['available'] ?? false), '刚读得到却报不可用：' . json_encode($data, JSON_UNESCAPED_UNICODE));

        $row = (array) (($data['rows'] ?? [])[0] ?? []);
        self::assertSame(self::CRON_TASK, $row['task_name'] ?? '', '执行记录列表整行空白：读数组行用了对象写法');
        self::assertSame('ok', $row['status'] ?? '');
        self::assertSame('形状回归', $row['message'] ?? '');
        self::assertSame(42, $row['duration_ms'] ?? null);
    }

    /* ==================== 工具 ==================== */

    private function seedFlag(int $rollout, bool $enabled): void
    {
        Db::table('kode_feature_flags')->where('key', self::FLAG)->delete();
        Db::table('kode_feature_flags')->insert([
            'key' => self::FLAG,
            'description' => '灰度形状回归',
            'enabled' => $enabled,
            'rollout' => $rollout,
            'environment' => '',
        ]);
    }

    /** @return array<string, mixed>|null */
    private function findFlag(mixed $rows): ?array
    {
        foreach ((array) $rows as $row) {
            if ((string) ((array) $row)['key'] === self::FLAG) {
                return (array) $row;
            }
        }

        return null;
    }

    private function storedEnabled(): bool
    {
        return (bool) (Db::table('kode_feature_flags')->where('key', self::FLAG)->first()['enabled'] ?? false);
    }

    private function storedDescription(): string
    {
        return (string) (Db::table('kode_feature_flags')->where('key', self::FLAG)->first()['description'] ?? '');
    }

    private function flagRows(): int
    {
        return (int) (Db::select('SELECT COUNT(*) AS c FROM kode_feature_flags WHERE key = ?', [self::FLAG])[0]['c'] ?? 0);
    }

    /** @param array<string, mixed> $body @return array{status:int, body:array<string,mixed>} */
    private function send(string $class, string $handler, array $body = [], string $routeKey = ''): array
    {
        $request = (new ServerRequest($handler === 'check' || $handler === 'index' || $handler === 'stats' || $handler === 'runs' ? 'GET' : 'POST', '/api/probe'))
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => 'zz-rowshape', 'roles' => ['admin']]);

        if ($routeKey !== '') {
            $request = $request->withAttribute('_route_params', ['key' => $routeKey]);
        }
        $request = in_array($handler, ['check', 'runs'], true)
            ? $request->withQueryParams($body)
            : $request->withParsedBody($body);

        HttpRequest::setRequest($request);

        $response = (new $class())->{$handler}();
        self::assertInstanceOf(ResponseInterface::class, $response);
        HttpRequest::clear();

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }
}
