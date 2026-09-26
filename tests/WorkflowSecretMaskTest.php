<?php

declare(strict_types=1);

namespace app\tests;

use app\support\HashId;
use app\support\PluginConfig;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Plugins\frontend\WorkflowBridgeController;

/**
 * 工作流定义的凭证字段：读侧掩码、写侧认掩码（AGENTS.md §4「掩码划出写侧边界」）。
 *
 * 挡的是这一条：`GET /api/workflow/definitions/{id}` 此前是 `SELECT *`，把
 * notify_secret / webhook_secret / alert_secret 三段 HMAC 密钥原样发给任何持
 * `workflow:view` 的账号 —— 而管理端表单（views/kode/workflow-bridge/index.vue）
 * 读到什么就回填什么、保存时整对象 PUT 回来。所以只加掩码不加「认掩码」，
 * 用户改一次描述就把三条凭证静默抹掉，且下一轮读仍是掩码，页面上完全看不出凭证已空。
 * 两侧都得有断言，缺一侧这条线就是假的。
 *
 * 自己造的行自己删（按主键精删），不建库不建表。
 */
final class WorkflowSecretMaskTest extends TestCase
{
    protected bool $independentApp = true;

    private const SECRETS = [
        'notify_secret' => 'real-notify-credential',
        'webhook_secret' => 'real-webhook-credential',
        'alert_secret' => 'real-alert-credential',
    ];

    /** @var list<int> 本类落进 kode_lowcode_workflows 的行 id */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->rows as $id) {
            try {
                Db::delete('DELETE FROM kode_lowcode_workflow_versions WHERE workflow_id = ?', [$id]);
                Db::delete('DELETE FROM kode_lowcode_workflows WHERE id = ?', [$id]);
            } catch (\Throwable) {
            }
        }
        $this->rows = [];
        HttpRequest::clear();

        parent::tearDown();
    }

    /** 造一条配好三段凭证的工作流，返回自增 id。 */
    private function seed(array $secrets = self::SECRETS): int
    {
        $name = 'wf_secret_probe_' . substr(md5(uniqid('', true)), 0, 8);
        $id = (int) Db::insert(
            "INSERT INTO kode_lowcode_workflows (name, description, steps_json, on_fail_json, step_count, status, notify_secret, webhook_secret, alert_secret)
             VALUES (?, 'probe', '[]', '[]', 0, 1, ?, ?, ?)",
            [$name, $secrets['notify_secret'], $secrets['webhook_secret'], $secrets['alert_secret']]
        );
        self::assertGreaterThan(0, $id, '造行失败，后面的断言全是空的');
        $this->rows[] = $id;

        return $id;
    }

    /** 直接调控制器；$body 为 PUT/POST 载荷，null 表示不带 body。 */
    private function call(string $action, int $id, ?array $body = null): array
    {
        $request = (new ServerRequest($body === null ? 'GET' : 'PUT', '/api/workflow/definitions/' . $id))
            ->withAttribute('_route_params', ['id' => HashId::enc($id)]);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        HttpRequest::setRequest($request);

        return self::unwrap((new WorkflowBridgeController())->{$action}());
    }

    /**
     * 解响应：单测直调控制器，绕过了 TimingMiddleware 的信封归一化，
     * 所以成功走 `{code,msg,data}`、失败走框架裸形态 `{message}` + HTTP 状态。
     * 这里一次抹平，用例只读 status/msg/data 三样。
     *
     * 详情接口的载荷本身就是 `{data:{data:行}}`（控制器写的是 ok(['data' => $row])），
     * 那是 uni3 壳的既有外部契约（AGENTS.md §7 例外），不在这里动。
     */
    private static function unwrap(\Psr\Http\Message\ResponseInterface $response): array
    {
        $body = (array) json_decode((string) $response->getBody(), true);

        return [
            'status' => $response->getStatusCode(),
            'msg' => (string) ($body['msg'] ?? $body['message'] ?? ''),
            'data' => $body['data'] ?? null,
        ];
    }

    private function stored(int $id): array
    {
        return Db::select(
            'SELECT name, description, notify_secret, webhook_secret, alert_secret FROM kode_lowcode_workflows WHERE id = ?',
            [$id]
        )[0];
    }

    public function test_detail_masks_every_credential_and_never_echoes_the_real_values(): void
    {
        $id = $this->seed();
        $r = $this->call('definitionShow', $id);
        $row = $r['data']['data'] ?? null;
        self::assertIsArray($row, '详情接口没出行：' . $r['msg']);

        // 整份响应里不得出现任何一段真凭证——只查三个键名会漏掉「换个键名又吐出来」的情况。
        $raw = (string) json_encode($r, JSON_UNESCAPED_UNICODE);
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $raw, '详情接口把凭证原样发出去了');
        }

        foreach (array_keys(self::SECRETS) as $field) {
            self::assertSame(
                PluginConfig::MASK,
                $row[$field] ?? null,
                "{$field} 未出掩码：前端回填的会是空串，保存时把真凭证抹掉"
            );
        }
        // 掩码后仍要出别的键：只测「不含」会允许接口退化成空壳。
        self::assertSame('probe', $row['description'] ?? null);
    }

    public function test_unset_credential_reads_as_empty_string_not_mask(): void
    {
        $id = $this->seed(['notify_secret' => '', 'webhook_secret' => '', 'alert_secret' => '']);
        $row = $this->call('definitionShow', $id)['data']['data'] ?? null;
        self::assertIsArray($row);

        // 「没配」必须渲染成空串而不是掩码：否则前端显示成「已配置」，
        // 用户以为有凭证、实际入站签名恒失败（口径同本文件预算接口的 *** 三元式）。
        foreach (array_keys(self::SECRETS) as $field) {
            self::assertSame('', $row[$field] ?? null);
        }
    }

    public function test_update_with_the_mask_literal_keeps_the_stored_credential(): void
    {
        $id = $this->seed();
        // 管理端就是「回填 + 整对象 PUT」：用户只改描述，凭证字段原样带回掩码。
        $r = $this->call('definitionUpdate', $id, [
            'description' => '只改了描述',
            'notify_secret' => PluginConfig::MASK,
            'webhook_secret' => PluginConfig::MASK,
            'alert_secret' => PluginConfig::MASK,
        ]);
        self::assertSame(200, $r['status'], '更新应当成功：' . $r['msg']);

        $row = $this->stored($id);
        self::assertSame('只改了描述', $row['description']);
        foreach (self::SECRETS as $field => $secret) {
            self::assertSame($secret, $row[$field], "{$field} 被掩码字面量覆盖了（写侧没认掩码）");
        }
    }

    public function test_update_with_a_real_value_still_replaces_it(): void
    {
        $id = $this->seed();
        $this->call('definitionUpdate', $id, ['webhook_secret' => 'rotated-credential']);

        self::assertSame('rotated-credential', $this->stored($id)['webhook_secret']);
    }

    public function test_update_with_explicit_empty_string_still_clears_it(): void
    {
        $id = $this->seed();
        $this->call('definitionUpdate', $id, ['alert_secret' => '']);

        // 留空 = 明确清空，这条不能被「认掩码」顺手改成保留，否则凭证删不掉。
        self::assertSame('', $this->stored($id)['alert_secret']);
    }

    public function test_create_refuses_the_mask_literal_because_there_is_nothing_to_preserve(): void
    {
        $request = (new ServerRequest('POST', '/api/workflow/definitions'))->withParsedBody([
            'name' => 'wf_mask_probe',
            'steps' => [],
            'notify_secret' => PluginConfig::MASK,
        ]);
        HttpRequest::setRequest($request);
        $r = self::unwrap((new WorkflowBridgeController())->definitionStore());

        self::assertSame(400, $r['status'], '掩码被当成真凭证存进了新建行');
        // 必须是「认掩码」这条拒的，不是 steps/name 校验顺带拒的（那样这条用例什么也没挡）。
        self::assertStringContainsString('notify_secret', $r['msg']);
        $leaked = Db::select("SELECT id FROM kode_lowcode_workflows WHERE name = ?", ['wf_mask_probe']);
        self::assertCount(0, $leaked, '新建被拒却落了行');
    }

    /**
     * 表里多一列 `*_secret` 而 SECRET_FIELDS 没跟上时，读侧会继续裸奔 —— 而本类前六个用例
     * 只盯着写死的那三个键名，全都照样绿。这条从 information_schema 现推集合，不抄代码里的清单。
     */
    public function test_secret_field_list_covers_every_credential_column(): void
    {
        $columns = [];
        foreach (Db::select(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'kode_lowcode_workflows' AND column_name LIKE '%\\_secret' ORDER BY column_name"
        ) as $row) {
            $columns[] = (string) $row['column_name'];
        }

        self::assertNotSame([], $columns, '探针失效：库里一张凭证列都没扫到，下面的比对是空的');

        $declared = (new \ReflectionClass(WorkflowBridgeController::class))->getConstant('SECRET_FIELDS');
        self::assertIsArray($declared, '控制器里的 SECRET_FIELDS 常量没了');
        foreach ($columns as $column) {
            self::assertContains($column, $declared, "凭证列 {$column} 不在掩码清单里：详情接口会把它原样发出去");
        }
    }
}
