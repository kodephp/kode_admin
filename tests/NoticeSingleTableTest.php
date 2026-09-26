<?php

declare(strict_types=1);

namespace app\tests;

use app\support\HashId;
use app\support\Shard;
use app\tests\Support\ShardSandbox;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Plugins\notices\NoticeController;
use Kode\Plugins\NoticePlugin;

/**
 * 单表模式（`sharding.enabled = false`）下，公告那三条按 id 寻址的路径必须真的读得到那一行。
 *
 * 为什么单独一条测试文件：分片开关同时决定**写侧落点**与**读侧表名**，而两侧是各写一遍的。
 * `locate()` 的未分片分支一度返回裸 `'notices'`（插件 install()/tables() 建的是 `kode_notices`），
 * 于是在默认配置下（`.env.example` 的 `SHARD_ENABLED=false`，且库里没有 `shard` 热配置行）：
 * 列表读得出来（那一支自己写的是 `kode_notices`），详情/改/删全部打到不存在的表 → 500。
 * 「一半界面能用」正是这类名字漂移长期不被发现的原因 —— 只有把开关真的拨到另一侧才看得见，
 * 而本机 `.env` 是开着的，所以此前所有用例都只跑了分片分支。
 *
 * 开关走生产那条路（`SysConfig::set('shard')` + `Shard::flushCache()`，即 `PUT /api/shard`
 * 写的同一个键、同一个失效链），不反射改私有静态：要测的就是真的那条判定链。
 * 收尾把该键恢复原状（本机原本没有这一行 → 删掉），并有一条对照断言验明确实恢复了 ——
 * 把开发库留在「分片已关」上比一条红着的测试更糟。
 */
final class NoticeSingleTableTest extends TestCase
{
    use ShardSandbox;

    protected bool $independentApp = true;

    /** 本插件的基表（与 NoticePlugin::tables() 同名单方验证，见下面的 assertContains） */
    private const TABLE = 'kode_notices';

    /** 本文件开始前的分片开关：恢复对照以它为准 */
    private bool $enabledBefore = false;

    /** @var array{0: int, 1: string}|null 本次插的探针行 [id, created_by 随机标记] */
    private ?array $probe = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));

        $this->enabledBefore = Shard::enabled();
        $this->captureShardBaseline();
    }

    protected function tearDown(): void
    {
        $this->dropProbe();
        $this->restoreShardConfig();

        HttpRequest::clear();

        parent::tearDown();
    }

    public function test_single_table_mode_resolves_to_the_table_the_plugin_actually_created(): void
    {
        // 前提对照：插件声明的表 = 库里存在的表；而那个错名字 `notices` 从来没被建过
        self::assertContains(
            self::TABLE,
            (array) (new NoticePlugin())->tables(),
            'NoticePlugin::tables() 已不声明 ' . self::TABLE . '：本用例的前提变了，请一起改判据'
        );
        self::assertNotNull(
            Db::select("SELECT to_regclass('public." . self::TABLE . "') AS r")[0]['r'] ?? null,
            '主库里没有基表 ' . self::TABLE . '：寻址问题无从谈起'
        );
        self::assertNull(
            Db::select("SELECT to_regclass('public.notices') AS r")[0]['r'] ?? null,
            '库里出现了名为 notices 的表：那个错名字已被当成约定，本用例要改判据'
        );

        $this->forceSingleTable();
        [$eid, $title] = $this->seedRow();

        // 列表：坏版本里这一支也是对的 —— 它就是「一半能用」的那一半，留作对照
        $list = $this->call('GET', 'index');
        self::assertSame(200, $list['status'], '单表模式连列表都读不出：' . $list['msg']);
        self::assertStringContainsString(
            $title,
            (string) json_encode($list['data'], JSON_UNESCAPED_UNICODE),
            '列表读不到刚插的行：基表落点与读侧口径已经变了'
        );

        // 详情：坏版本在这里抛「关系 "notices" 不存在」
        $show = $this->call('GET', 'show', $eid);
        self::assertSame(
            200,
            $show['status'],
            "单表模式详情读不到刚写进去的那条（{$show['msg']}）："
            . 'locate() 给的表名与 store() 的落点不是同一张 —— 列表能看、点进去就 500'
        );
        self::assertSame($title, (string) ($show['data']['title'] ?? ''), '详情回的不是那一行');

        $put = $this->call('PUT', 'update', $eid, ['title' => $title, 'body' => 'edited']);
        self::assertSame(200, $put['status'], '单表模式改不动自己的行：' . $put['msg']);

        $del = $this->call('DELETE', 'destroy', $eid);
        self::assertSame(200, $del['status'], '单表模式删不掉自己的行：' . $del['msg']);
        self::assertSame([], $this->probeRow(), '接口回了删除成功，基表里那行却还在');
        $this->probe = null;
    }

    /**
     * 收尾对照：本文件不该把开发库的分片开关留在「关」上。
     *
     * PHPUnit 按声明顺序跑，这条排在最后 = 前面所有 tearDown 都走完之后再看一眼环境。
     * 断言的是「与本文件开始前一致」，不是「必须是开」—— 换台机器结论不该变。
     */
    public function test_the_shard_switch_is_left_as_it_was(): void
    {
        self::assertSame(
            $this->enabledBefore,
            Shard::enabled(),
            '分片开关与本文件开始前的状态不一致：热配置没恢复，开发库会按错拓扑路由'
        );
        $this->assertSandboxLeftClean();
    }

    // ─── 私有 ───────────────────────────────────────────────

    /** 关掉分片（走生产那条写入路径：SysConfig + 失效热缓存），并断言开关真的落地。 */
    private function forceSingleTable(): void
    {
        $this->forceTopology(false, 1, 1);
    }

    /** @return array{0: string, 1: string} [对外 eid, 标题] */
    private function seedRow(): array
    {
        $owner = 'zz_notice_st_' . bin2hex(random_bytes(6));
        $title = '单表模式寻址探针 ' . $owner;
        $id = (int) Db::connection(Db::getDefaultConnection())->insert(
            'INSERT INTO ' . self::TABLE
            . " (title, body, type, status, created_by, created_at, updated_at) VALUES (?, ?, 'announcement', 1, ?, NOW(), NOW())",
            [$title, 'probe', $owner]
        );
        $this->probe = [$id, $owner];

        return [HashId::enc($id), $title];
    }

    /**
     * 直接驱动控制器（本机 admin 密码已改，拿不到真令牌；鉴权由路由侧 RequirePerm 负责，
     * 这里给的是控制器自己要读的那个 auth 属性）。
     *
     * @param array<string, mixed> $body
     * @return array{status: int, msg: string, data: array<string, mixed>}
     */
    private function call(string $method, string $handler, ?string $eid = null, array $body = []): array
    {
        $request = new ServerRequest(
            $method,
            '/api/notices' . ($eid === null ? '' : '/' . $eid),
            [],
            ['content-type' => 'application/json'],
            $body === [] ? '' : (string) json_encode($body)
        );
        if ($eid !== null) {
            $request = $request->withAttribute('_route_params', ['id' => $eid]);
        }
        HttpRequest::setRequest(
            $request->withAttribute('auth', (object) ['uid' => 1, 'roles' => ['admin']])
        );

        $response = (new NoticeController())->{$handler}();
        $decoded = (array) json_decode((string) $response->getBody(), true);

        return [
            'status' => (int) ($decoded['code'] ?? $response->getStatusCode()),
            'msg' => (string) ($decoded['msg'] ?? ''),
            'data' => (array) ($decoded['data'] ?? []),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function probeRow(): array
    {
        if ($this->probe === null) {
            return [];
        }
        [$id, $owner] = $this->probe;

        return Db::connection(Db::getDefaultConnection())->select(
            'SELECT id FROM ' . self::TABLE . ' WHERE id = ? AND created_by = ?',
            [$id, $owner]
        );
    }

    private function dropProbe(): void
    {
        if ($this->probe === null) {
            return;
        }
        [$id, $owner] = $this->probe;
        $this->probe = null;
        try {
            // 只删自己那一行：归属键是随机串，命中不了别人的数据
            Db::connection(Db::getDefaultConnection())->delete(
                'DELETE FROM ' . self::TABLE . ' WHERE id = ? AND created_by = ?',
                [$id, $owner]
            );
        } catch (\Throwable) {
            // 收尾删除失败不该盖住用例真正的失败原因
        }
    }
}
