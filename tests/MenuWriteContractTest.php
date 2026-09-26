<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * 菜单写侧的三条契约（AGENTS.md §4「PUT 局部更新语义」+「授权类枚举写入前先查表」的同族）。
 *
 * 修的是三个互不相干、但都在同一段代码里的实测缺陷：
 *
 * 1. **插件占用的 path 在写侧完全看不见**。插件菜单不入库，是 `GET /api/menus` 读取时才和
 *    库内菜单合并的（`MenuController::tree()`），所以新建一条 `/workflow-bridge`
 *    （FrontendPlugin 已经声明了它）能存下来 —— 侧边栏出两个同名条目，而库里那条
 *    和插件那条互相改不动。本机 `kode_menus` 里 id=49（`plugin` 为 NULL 的化石行）
 *    就是早期「把插件菜单落表」留下的，路径正是 `/workflow-bridge`。
 * 2. **库内同 path 也没有判定**，两条一样的菜单同样建得出来（当前库里 0 组重复，靠的是没人撞过）。
 * 3. **`update()` 是无条件的 11 列覆盖**，而校验规则只写了 7 个键：
 *    列表页那种只发 `{title, path, status}` 的 PUT 会把 `auth_code` 抹成空串（菜单立刻
 *    变成「登录可见」，等于越权可见）、把 `name`/`component`/`meta` 抹成默认值、
 *    把 `parent_id` 拍平成顶级；而 `status` 缺键时回落成 1，**禁用中的菜单会被静默重新启用**。
 *
 * 「历史行路径没动」的那条例外不是宽松处理，而是让编辑能继续用：见
 * `test_a_legacy_row_sharing_a_plugin_path_can_still_be_renamed` 与它后面那条反向对照。
 */
final class MenuWriteContractTest extends TestCase
{
    protected bool $independentApp = true;

    private const PROBE = 'zz-menu-probe';

    /** @var list<int> 本用例建出来的菜单 id（tearDown 精确按 id 删） */
    private array $menuIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->menuIds as $id) {
            Db::delete('DELETE FROM kode_menu_buttons WHERE menu_id = ?', [(string) $id]);
            Db::delete('DELETE FROM kode_menus WHERE id = ?', [(string) $id]);
        }
        // 「被拒的新建不许落库」那条断言失败时，行是真落进去了 —— 前缀清扫兜住本文件自己写下的行，
        // 否则一次红就跑出两条常驻的重复菜单（侧边栏立刻多出条目，而且没人知道是哪次测试写的）。
        Db::delete("DELETE FROM kode_menus WHERE title LIKE 'zz-menu-probe%'");
        // 探针行曾经由 store/update 失效过缓存，收尾再失效一次，
        // 免得开发服务的 300s 缓存里留着一条已删的 zz 菜单。
        \app\support\HotCache::forget('menus:tree');

        HttpRequest::clear();

        parent::tearDown();
    }

    /* ==================== 1. 插件占用的 path ==================== */

    public function test_plugin_menus_are_enumerated_at_all(): void
    {
        // 反空转：后面几条全靠这张表判断撞车，它是空的就什么都挡不住。
        $paths = \app\support\PluginMenus::paths();
        self::assertNotEmpty($paths, '没有任何插件声明 menus()：写侧撞车判定是空的');
        self::assertContains(
            '/workflow-bridge',
            array_keys($paths),
            'FrontendPlugin 的菜单声明枚举不到（PluginMenus 与 tree() 分家了？）：' . implode(', ', array_keys($paths))
        );
    }

    public function test_a_path_claimed_by_a_plugin_cannot_be_created_as_a_menu(): void
    {
        $path = (string) array_key_first(\app\support\PluginMenus::paths());
        $before = $this->menuCount();

        $result = $this->call('POST', '/api/menus', 'store', [
            'title' => self::PROBE,
            'path' => $path,
        ]);

        self::assertSame(409, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('插件', (string) ($result['body']['msg'] ?? ''));
        self::assertSame($before, $this->menuCount(), '被拒的新建仍然落了库');
    }

    /** 库内同 path 也算撞车：侧边栏同样是两个同名条目。 */
    public function test_a_path_already_used_by_another_menu_cannot_be_created(): void
    {
        $id = $this->seed(['path' => '/' . self::PROBE . '-dup']);
        $before = $this->menuCount();

        $result = $this->call('POST', '/api/menus', 'store', [
            'title' => self::PROBE . '-second',
            'path' => '/' . self::PROBE . '-dup',
        ]);

        self::assertSame(409, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('已存在', (string) ($result['body']['msg'] ?? ''));
        self::assertSame($before, $this->menuCount(), '撞车的第二条仍然落了库');
        self::assertSame(
            self::PROBE,
            (string) (Db::select('SELECT title FROM kode_menus WHERE id = ?', [$id])[0]['title'] ?? ''),
            '撞车的那次新建顺手改了已有的行'
        );
    }

    /* ==================== 2. 改路径也要过同一道闸 ==================== */

    public function test_editing_a_menu_into_a_plugin_path_is_refused_and_leaves_the_row_alone(): void
    {
        $id = $this->seed(['path' => '/' . self::PROBE . '-movable']);
        $path = (string) array_key_first(\app\support\PluginMenus::paths());

        $result = $this->call('PUT', '/api/menus/' . \app\support\HashId::enc($id), 'update', [
            'title' => self::PROBE . '-moved',
            'path' => $path,
        ], $id);

        self::assertSame(409, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $row = Db::select('SELECT title, path FROM kode_menus WHERE id = ?', [$id])[0] ?? [];
        self::assertSame('/' . self::PROBE . '-movable', (string) ($row['path'] ?? ''), '被拒的 PUT 仍然挪了路径');
        self::assertSame(self::PROBE, (string) ($row['title'] ?? ''), '被拒的 PUT 仍然改了标题');
    }

    /**
     * 历史行（与插件声明撞车的那一类）路径没动时仍然可编辑 —— 否则库里那条化石行
     * 连改标题都改不动，而它只能被删除；例外只放行「路径不变」，改路径仍然要过闸。
     */
    public function test_a_legacy_row_sharing_a_plugin_path_can_still_be_renamed(): void
    {
        $path = (string) array_key_first(\app\support\PluginMenus::paths());
        // 绕开控制器种行：本用例测的是「已存在的撞车行怎么办」，不是「能不能种出它」。
        $id = $this->seed(['path' => $path, 'title' => self::PROBE . '-legacy']);

        $result = $this->call('PUT', '/api/menus/' . \app\support\HashId::enc($id), 'update', [
            'title' => self::PROBE . '-renamed',
            'path' => $path,
        ], $id);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(
            self::PROBE . '-renamed',
            (string) (Db::select('SELECT title FROM kode_menus WHERE id = ?', [$id])[0]['title'] ?? '')
        );

        // 反向对照：例外不能顺手变成「这一行的路径改什么都行」。
        $away = $this->call('PUT', '/api/menus/' . \app\support\HashId::enc($id), 'update', [
            'title' => self::PROBE . '-renamed',
            'path' => '/' . self::PROBE . '-away',
        ], $id);
        self::assertSame(200, $away['status'], json_encode($away['body'], JSON_UNESCAPED_UNICODE));

        $back = $this->call('PUT', '/api/menus/' . \app\support\HashId::enc($id), 'update', [
            'title' => self::PROBE . '-renamed',
            'path' => $path,
        ], $id);
        self::assertSame(
            409,
            $back['status'],
            '改回插件占用的路径没被拒：「路径没动才放行」被写成了「这一行永久放行」'
        );
    }

    /* ==================== 3. PUT 缺键保留原值 ==================== */

    /** 列表页那种只发 {title, path, status} 的开关 PUT，不许把兄弟列抹成默认值。 */
    public function test_a_partial_put_keeps_the_columns_it_did_not_send(): void
    {
        $id = $this->seed([
            'path' => '/' . self::PROBE . '-partial',
            'icon' => 'lucide:shield',
            'plugin' => 'frontend',
            'sort' => 7,
            'auth_code' => 'users:view',
            'name' => 'SystemProbe',
            'component' => '/sys/probe/list',
            'meta' => '{"hideInMenu":true}',
            'status' => 0,
        ]);
        // 挂一个上级，顺带测 parent_id：缺键时最坏的情况是把菜单从目录里拍平出来。
        $rootId = $this->seed(['path' => '/' . self::PROBE . '-root']);
        Db::update('UPDATE kode_menus SET parent_id = ? WHERE id = ?', [$rootId, $id]);

        $body = ['title' => '改名了', 'path' => '/' . self::PROBE . '-partial', 'status' => 0];
        $result = $this->call('PUT', '/api/menus/' . \app\support\HashId::enc($id), 'update', $body, $id);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $row = Db::select('SELECT * FROM kode_menus WHERE id = ?', [$id])[0] ?? [];
        foreach ([
            'icon' => 'lucide:shield',
            'plugin' => 'frontend',
            'sort' => '7',
            'auth_code' => 'users:view',
            'name' => 'SystemProbe',
            'component' => '/sys/probe/list',
            'meta' => '{"hideInMenu":true}',
            'parent_id' => (string) $rootId,
        ] as $col => $want) {
            self::assertSame($want, (string) ($row[$col] ?? ''), "缺键的 {$col} 没保留原值");
        }
        // auth_code 是这一条最贵的列：抹成空串 = 「登录可见」，一条受控菜单当场变成全员可见。
        self::assertSame(
            'users:view',
            (string) ($row['auth_code'] ?? ''),
            'auth_code 被抹平了：这条菜单对所有登录账号可见了'
        );
    }

    /** `status` 缺键绝不能回落成 1 —— 那是一次「谁都没点的启用」。 */
    public function test_a_put_without_status_does_not_re_enable_a_disabled_menu(): void
    {
        $id = $this->seed(['path' => '/' . self::PROBE . '-status', 'status' => 0]);

        $result = $this->call('PUT', '/api/menus/' . \app\support\HashId::enc($id), 'update', [
            'title' => self::PROBE . '-tweaked',
            'path' => '/' . self::PROBE . '-status',
        ], $id);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(
            0,
            (int) (Db::select('SELECT status FROM kode_menus WHERE id = ?', [$id])[0]['status'] ?? -1),
            '没发 status 的 PUT 把禁用的菜单重新启用了'
        );
    }

    /** 「保留原值」不等于「改不动」：显式发了 status 就必须落。 */
    public function test_an_explicit_status_still_writes_both_ways(): void
    {
        $id = $this->seed(['path' => '/' . self::PROBE . '-toggle', 'status' => 0]);

        $on = $this->call('PUT', '/api/menus/' . \app\support\HashId::enc($id), 'update', [
            'title' => self::PROBE, 'path' => '/' . self::PROBE . '-toggle', 'status' => 1,
        ], $id);
        self::assertSame(200, $on['status']);
        self::assertSame(1, (int) (Db::select('SELECT status FROM kode_menus WHERE id = ?', [$id])[0]['status'] ?? 0));

        $off = $this->call('PUT', '/api/menus/' . \app\support\HashId::enc($id), 'update', [
            'title' => self::PROBE, 'path' => '/' . self::PROBE . '-toggle', 'status' => 0,
        ], $id);
        self::assertSame(200, $off['status']);
        self::assertSame(
            0,
            (int) (Db::select('SELECT status FROM kode_menus WHERE id = ?', [$id])[0]['status'] ?? 1),
            '显式禁用的 PUT 没落地：「缺键保留原值」被写成了「所有键都保留原值」'
        );
    }

    /** 显式清空是合法语义：发了空串才算清空，缺键才是「没改」。 */
    public function test_an_explicit_empty_value_clears_the_column(): void
    {
        $id = $this->seed(['path' => '/' . self::PROBE . '-clear', 'component' => '/sys/probe/list']);

        $result = $this->call('PUT', '/api/menus/' . \app\support\HashId::enc($id), 'update', [
            'title' => self::PROBE, 'path' => '/' . self::PROBE . '-clear', 'component' => '',
        ], $id);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(
            '',
            (string) (Db::select('SELECT component FROM kode_menus WHERE id = ?', [$id])[0]['component'] ?? null),
            '显式传空串没清空这一列'
        );
    }

    /* ==================== 工具 ==================== */

    /** @param array<string, mixed> $overrides */
    private function seed(array $overrides): int
    {
        $row = array_merge([
            'title' => self::PROBE,
            'path' => '/' . self::PROBE . '-' . count($this->menuIds),
            'icon' => '',
            'plugin' => 'system',
            'sort' => 0,
            'parent_id' => 0,
            'auth_code' => '',
            'status' => 1,
            'name' => '',
            'component' => '',
            'meta' => '{}',
        ], $overrides);

        $id = (int) Db::insert(
            'INSERT INTO kode_menus (title, path, icon, plugin, sort, parent_id, auth_code, status, name, component, meta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $row['title'], $row['path'], $row['icon'], $row['plugin'], $row['sort'], $row['parent_id'],
                $row['auth_code'], $row['status'], $row['name'], $row['component'], $row['meta'],
            ]
        );
        $this->menuIds[] = $id;

        return $id;
    }

    /** @param array<string, mixed> $body @return array{status: int, body: array<string, mixed>} */
    private function call(string $method, string $path, string $handler, array $body, ?int $routeId = null): array
    {
        $request = (new ServerRequest($method, $path))
            ->withParsedBody($body)
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => self::PROBE, 'roles' => ['admin']]);
        if ($routeId !== null) {
            $request = $request->withAttribute('_route_params', ['id' => \app\support\HashId::enc($routeId)]);
        }
        HttpRequest::setRequest($request);

        $response = (new \app\admin\controllers\MenuController())->{$handler}();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }

    private function menuCount(): int
    {
        return (int) (Db::select('SELECT COUNT(*) AS c FROM kode_menus')[0]['c'] ?? 0);
    }
}
