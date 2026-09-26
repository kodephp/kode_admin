<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\AuditController;
use app\admin\controllers\ConfigController;
use app\admin\controllers\DashboardController;
use app\admin\controllers\NotifyController;
use app\support\AuditTrail;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 管理端出参按身份收敛（#89 的后半：路由门禁管不到的那一半）。
 *
 * 有些只读接口天生挂不了权限码 —— 侧边栏、登录页、首页卡片对每个账号都要出内容。
 * 对它们，「谁能调」不是问题，「一次能拿走多少」才是：
 * 全站用户数/角色数/插件数/版本号、全站请求趋势、以及 user_id=0 的广播行。
 * 本类锁的就是这条线：非超管只看到自己的那一份，共享公告只能被本人清理。
 *
 * 写完删自己的行（按探针标记精删），不建库不建表。
 */
final class AdminReadScopeTest extends TestCase
{
    protected bool $independentApp = true;

    /** @var list<array{0: string, 1: list<mixed>}> 本用例落过的行，tearDown 按主键精删 */
    private array $probeTags = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->probeTags as [$sql, $params]) {
            try {
                Db::delete($sql, $params);
            } catch (\Throwable) {
            }
        }
        $this->probeTags = [];
        HttpRequest::clear();

        parent::tearDown();
    }

    /** 调控制器并解信封；$payload 为 auth 属性（null=不带）。 */
    private function call(string $method, string $uri, object $controller, string $action, mixed $payload = null): array
    {
        $query = (string) parse_url($uri, PHP_URL_QUERY);
        $parsed = [];
        if ($query !== '') {
            parse_str($query, $parsed);
        }
        $request = (new ServerRequest($method, $uri))->withQueryParams($parsed);
        if ($payload !== null) {
            $request = $request->withAttribute('auth', $payload);
        }
        HttpRequest::setRequest($request);

        $response = $controller->{$action}();
        $decoded = (array) json_decode((string) $response->getBody(), true);

        return [
            'code' => (int) ($decoded['code'] ?? $response->getStatusCode()),
            'msg' => (string) ($decoded['msg'] ?? ''),
            'data' => $decoded['data'] ?? null,
        ];
    }

    public function test_site_subset_returns_only_the_declared_brand_keys(): void
    {
        // 探针键：同前缀、不在白名单里。前缀匹配会把它一起漏出去，逐键白名单不会。
        $probeKey = 'site.__scope_probe__';
        Db::insert(
            'INSERT INTO kode_sys_configs (ckey, cvalue, remark, ctype, ctitle, cgroup, sort, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$probeKey, 'private', 'probe', 'text', 'probe', 'site', 1]
        );
        $this->probeTags[] = ['DELETE FROM kode_sys_configs WHERE ckey = ?', [$probeKey]];

        $body = $this->call('GET', '/api/configs/site', new ConfigController(), 'site', (object) ['uid' => 2, 'roles' => []]);

        self::assertSame(200, $body['code']);
        $keys = array_keys((array) $body['data']);
        sort($keys);
        self::assertSame(['site.description', 'site.icp', 'site.logo', 'site.title'], $keys);
        self::assertArrayNotHasKey($probeKey, (array) $body['data'], '按前缀放行会把 site.* 下的任意新键一起送出去');
        // 全站配置表里的其它键（上传策略、市场地址…）一概不在
        self::assertArrayNotHasKey('market.api_url', (array) $body['data']);
    }

    public function test_summary_hides_global_inventory_from_non_admin(): void
    {
        $body = $this->call('GET', '/api/dashboard/summary', new DashboardController(), 'summary', (object) [
            'uid' => 2,
            'roles' => ['guanli'],
            'username' => 'nobody_for_scope_probe',
        ]);

        self::assertSame(200, $body['code']);
        self::assertSame('self', $body['data']['scope'] ?? null);
        foreach (['users', 'roles', 'notices', 'plugins', 'system'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, (array) $body['data'], "非超管不应看到全站 {$forbidden} 库存");
        }
    }

    public function test_summary_still_returns_full_board_for_admin(): void
    {
        $body = $this->call('GET', '/api/dashboard/summary', new DashboardController(), 'summary', (object) [
            'uid' => 2,
            'roles' => ['admin'],
            'username' => 'admin',
        ]);

        self::assertSame(200, $body['code']);
        self::assertSame('all', $body['data']['scope'] ?? null);
        foreach (['users', 'roles', 'notices', 'plugins', 'system', 'audits_today'] as $expected) {
            self::assertArrayHasKey($expected, (array) $body['data']);
        }
    }

    public function test_audit_stats_for_other_users_only_sees_their_own_rows(): void
    {
        $tag = '__scope_probe_' . uniqid() . '__';
        // 落片与 INSERT 都走生产侧那一份判据：测试自己再算一遍表名， drifted 了就是假绿
        $table = AuditTrail::tableFor('0', '127.0.0.1');
        AuditTrail::record('0', $tag, 'GET', '/api/probe', 200, 1, '127.0.0.1');
        $this->probeTags[] = ["DELETE FROM {$table} WHERE username = ?", [$tag]];

        $admin = $this->call('GET', '/api/audits/stats', new AuditController(), 'stats', (object) [
            'uid' => 1,
            'roles' => ['admin'],
            'username' => 'admin',
        ]);
        $adminTotal = array_sum(array_map(static fn($r) => (int) $r['count'], (array) $admin['data']));

        $other = $this->call('GET', '/api/audits/stats', new AuditController(), 'stats', (object) [
            'uid' => 2,
            'roles' => [],
            'username' => 'no_such_user_' . $tag,
        ]);
        $otherTotal = array_sum(array_map(static fn($r) => (int) $r['count'], (array) $other['data']));

        self::assertSame(200, $admin['code']);
        self::assertGreaterThan(0, $adminTotal, '超管口径应能看见刚插入的那条全站审计');
        self::assertSame(0, $otherTotal, '无审计记录的账号拿到全站趋势 = username 过滤没生效');
        // 形状不变：两处图表都在 stats.map，收的是行范围而不是出参结构。
        self::assertCount(7, (array) $other['data']);
    }

    public function test_notify_clear_leaves_broadcast_rows_alone(): void
    {
        $uid = 990001;
        $broadId = $this->insertNotice($uid, 'broadcast-keep', 0, 1);
        $mineId = $this->insertNotice($uid, 'mine-drop', $uid, 1);

        $body = $this->call('DELETE', '/api/notifications/clear', new NotifyController(), 'clear', (object) [
            'uid' => $uid,
            'roles' => [],
            'username' => 'scope_probe',
        ]);

        self::assertSame(200, $body['code']);
        self::assertTrue($this->noticeExists($broadId), '广播行被本人的「清空已读」删掉了（跨账号不可逆）');
        self::assertFalse($this->noticeExists($mineId), '本人已读行应照旧清掉');
        self::assertGreaterThanOrEqual(1, (int) ($body['data']['deleted'] ?? 0));
    }

    public function test_notify_endpoints_refuse_a_payload_without_uid(): void
    {
        $broadId = $this->insertNotice(990002, 'broadcast-noauth', 0, 1);
        $anonymous = (object) ['uid' => 0, 'roles' => [], 'username' => 'ghost'];

        foreach (['index', 'unreadCount', 'read', 'readAll', 'clear'] as $action) {
            $body = $this->call('GET', '/api/notifications', new NotifyController(), $action, $anonymous);
            self::assertSame(401, $body['code'], $action . ' 对无 uid 载荷必须 401');
        }

        // uid=0 恰好是广播行的归属：旧写法会把它当成「我的收件箱」，clear 更是替全站删公告。
        self::assertTrue($this->noticeExists($broadId), 'uid=0 落进 user_id IN (0,0) 后动了全站广播行');
    }

    /** @return int 探针行 id */
    private function insertNotice(int $ownerUid, string $title, int $userId, int $read): int
    {
        $id = (int) Db::insert(
            'INSERT INTO kode_notifications (user_id, type, title, content, level, url, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$userId, 'info', '__scope_probe__' . $title, '', 'info', '', $read]
        );
        $this->probeTags[] = ['DELETE FROM kode_notifications WHERE id = ?', [$id]];

        return $id;
    }

    private function noticeExists(int $id): bool
    {
        return (int) (Db::select('SELECT COUNT(*) AS c FROM kode_notifications WHERE id = ?', [$id])[0]['c'] ?? 0) > 0;
    }
}
