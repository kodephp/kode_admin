<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\ScheduleController;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 动态定时任务注册回归：handler 是「已有任务方法的引用」，不是代码字符串。
 *
 * 这条路径在修 `json()` 误用之前根本跑不到（`$this->json('handler')` 返回响应对象，
 * `(string) $obj` 直接 Error → 500），所以它的 eval() 一直藏在 500 后面。
 * 取参修好后 POST /api/schedules/create 才真正可达，此时任何能登录管理端的账号
 * 都能把 PHP 代码塞进 cron 里按期执行——原来的「危险函数黑名单」挡不住：
 * `file_put_contents('/tmp/x', 'y')` 不在名单上，`$f='sys'.'tem'; $f('id')` 也能绕。
 *
 * 现在 handler 只被当作查表用的类/方法引用，执行面收敛到「仓库里已有的任务代码」。
 */
final class ScheduleHandlerAllowlistTest extends TestCase
{
    protected bool $independentApp = true;

    /** 本类注册用的任务名（tearDown 统一回收，避免污染调度器注册表） */
    private const TASK = 'allowlist-regression';

    /** 旧代码会真正落盘的证据文件 */
    private const PROOF = '/tmp/kode_schedule_eval_pwned';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        @unlink(self::PROOF);
    }

    protected function tearDown(): void
    {
        resolve(ScheduleDispatcher::class)->unregister(self::TASK);
        @unlink(self::PROOF);
        HttpRequest::clear();

        parent::tearDown();
    }

    /**
     * 以 JSON 请求体调用 create()，返回 [HTTP 状态, 解码后的信封]。
     *
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function create(array $payload): array
    {
        $payload += ['name' => self::TASK, 'expression' => '0 4 * * *'];

        HttpRequest::setRequest(new ServerRequest(
            'POST',
            '/api/schedules/create',
            [],
            ['content-type' => 'application/json'],
            (string) json_encode($payload)
        ));

        $response = (new ScheduleController())->create();
        $body = json_decode((string) $response->getBody(), true);

        $this->assertIsArray($body, '响应体应是 JSON 信封');

        return [$response->getStatusCode(), $body];
    }

    private function registered(): ?object
    {
        return resolve(ScheduleDispatcher::class)->find(self::TASK);
    }

    public function test_handler_code_string_is_never_executed(): void
    {
        [$status, $body] = $this->create([
            'handler' => "file_put_contents('" . self::PROOF . "', 'pwned');",
        ]);

        self::assertSame(400, $status, '代码字符串不再被接受：' . ($body['msg'] ?? ''));
        self::assertNull($this->registered(), '被拒的请求不得在调度器里留下任务');
        self::assertFalse(file_exists(self::PROOF), 'handler 不得被执行（旧代码会 eval 出这条写文件）');
    }

    /**
     * 旧黑名单同样会放过的写法，现在一并被拒——说明门禁从「匹配危险字样」换成了「查表白名单」。
     */
    public function test_blacklist_evasion_is_also_rejected(): void
    {
        [$status, $body] = $this->create([
            'handler' => "\$f = 'sys'.'tem'; \$f('id > /tmp/x');",
        ]);

        self::assertSame(400, $status, '拼接函数名绕过黑名单的写法必须被拒：' . ($body['msg'] ?? ''));
        self::assertNull($this->registered());
    }

    public function test_task_class_with_handle_is_registered(): void
    {
        [$status, $body] = $this->create(['handler' => 'app\\tasks\\AuditCleanTask']);

        self::assertSame(200, $status, '合法任务类应注册成功：' . ($body['msg'] ?? ''));
        self::assertSame(
            'app\\tasks\\AuditCleanTask::handle',
            $body['data']['handler'] ?? null,
            '回显归一化后的真实类名 + 方法'
        );

        $task = $this->registered();
        self::assertNotNull($task);
        self::assertSame('dynamic', $task->source);
        self::assertInstanceOf(\Closure::class, $task->handler);
    }

    /**
     * 方法级引用（不继承 Task 的类）同样可用，且闭包真的能派发到目标方法。
     */
    public function test_method_reference_dispatches_to_the_target(): void
    {
        [$status, $body] = $this->create(['handler' => 'MonitorTask::ping']);
        self::assertSame(200, $status, '方法级引用应被接受：' . ($body['msg'] ?? ''));
        self::assertSame('app\\tasks\\MonitorTask::ping', $body['data']['handler'] ?? null);

        $task = $this->registered();
        self::assertNotNull($task);

        // 直接触发闭包：容器解析不到类、或调了不存在的方法都会抛
        ($task->handler)();
    }

    public function test_references_outside_the_allowlist_are_rejected(): void
    {
        foreach ([
            'CleanupTask::handle(',
            'NopeTask',
            'app\\tasks\\NopeTask::handle',
            'app\\tasks\\..\\support\\Shard',
            '../support/Shard',
            'Shard',
            'ScheduleDispatcher',
            'MonitorTask::ping() && file_put_contents',
            'MonitorTask::__construct',
            'AuditCleanTask::notAMethod',
            '',
            '   ',
        ] as $ref) {
            [$status, $body] = $this->create(['handler' => $ref]);

            self::assertSame(400, $status, "handler='" . $ref . "' 应被拒：" . ($body['msg'] ?? ''));
            self::assertNull($this->registered(), "handler='" . $ref . "' 不得注册任务");
        }
    }
}
