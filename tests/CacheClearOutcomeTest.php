<?php

declare(strict_types=1);

namespace app\tests;

use app\support\HotCache;
use Kode\Framework\Testing\TestCase;
use Kode\Framework\Http\Resp;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 「清理热缓存」这一动作的**结果口径**（`POST /api/system/cache-clear` 与「一键修复」共用）。
 *
 * `HotCacheNamespaceTest` 治的是「哪些前缀在清理面里」，本文件治的是「清理成没成」。
 * 修之前的两份实现都把「没做成」渲染成「做完了」：
 *  - `clear()` 在 `conn() === null`（redis 扩展没装/连不上）时 `return 0`，
 *    在拿到一个已死的持久连接时 `scan()` 返回 `false` → `break` → 同样 `return 0`。
 *    于是「服务根本没连着」和「连着，但确实没有键要删」是同一个返回值。
 *  - 控制器把这张 `{前缀: 删除数}` 当成 `data` 整份发出去，前端读的是 `res.menus`
 *    与 `res.configs`（api.ts 的类型也写着 `{configs, menus}`）—— 那两个键从来不存在，
 *    提示条渲染成「热缓存已清理（菜单 ，配置 ）」，而第三个命名空间 `plugin:` 的数
 *    在页面上永远看不见：新增前缀不需要动前端，因为前端读的不是这份清单。
 *
 * 一条已死的 pconnect 是这个应用最真实的场景：常驻 worker 握着长连接，
 * redis 重启之后连接对象还在，`scan()` 只会回 false。
 */
final class CacheClearOutcomeTest extends TestCase
{
    protected bool $independentApp = true;

    /** @var list<string> 本用例写下的探针键，tearDown 里逐个删 */
    private array $probeKeys = [];

    /** @var \Redis|null 注入坏连接前的原始静态值 */
    private $originalConn = null;

    private bool $connSwapped = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->probeKeys as $key) {
            HotCache::forget($key);
        }
        $this->probeKeys = [];

        if ($this->connSwapped) {
            self::redisProperty()->setValue(null, $this->originalConn);
            $this->connSwapped = false;
            $this->originalConn = null;
        }

        HttpRequest::clear();

        parent::tearDown();
    }

    // ─── 出参形状 ────────────────────────────────────────────

    /**
     * 响应必须逐命名空间列出，并明说这一轮清理是否完整。
     *
     * 键取 `HotCache::NAMESPACES` 里那份原样（带尾冒号）：前端再也不会写死一份名字表，
     * 「第四第五个命名空间冒出来却看不见」这条线从根上断掉。
     */
    public function test_cache_clear_response_lists_every_registered_namespace(): void
    {
        $body = $this->callCacheClear();
        $data = (array) ($body['data'] ?? []);

        self::assertArrayHasKey('namespaces', $data,
            '响应里没有 namespaces 这份清单：前端只能靠写死的键名去读（实测就是这样丢的）');
        self::assertSame(
            HotCache::NAMESPACES,
            array_keys((array) $data['namespaces']),
            '清理结果的行必须与 HotCache::NAMESPACES 逐一对应，多一行少一行都是口径分岔'
        );
        self::assertArrayHasKey('complete', $data, '响应必须明说这一轮是否全部清理成功');
        self::assertIsBool($data['complete']);
        self::assertArrayHasKey('total', $data, '响应必须给出删除键的总数');
    }

    /**
     * 「清到了」才是 `complete: true`，并且逐行给出真实删除数、键确实消失。
     *
     * 每个命名空间各埋一枚探针键 —— 只测一个的话，「只清了 menus，其余报 0」照样绿。
     */
    public function test_live_clear_reports_real_counts_and_removes_the_keys(): void
    {
        if (HotCache::connection() === null) {
            // 跳过必须写明原因：否则「没跑」和「跑过且绿」在输出上长得一样。
            $this->markTestSkipped('redis 不可用，无法验证清理结果与键确实消失');
        }

        $token = bin2hex(random_bytes(6));
        $seeded = [];
        foreach (HotCache::NAMESPACES as $ns) {
            $key = $ns . 'zz-' . $token;
            $this->probeKeys[] = $key;
            HotCache::set($key, 'x', 60);
            self::assertSame('x', HotCache::get($key), "写入即读不到：{$key}");
            $seeded[$ns] = $key;
        }

        $data = (array) ($this->callCacheClear()['data'] ?? []);

        self::assertTrue($data['complete'] ?? null,
            'redis 可用且刚写入过键，这一轮必须报完整：' . json_encode($data['namespaces'] ?? null));

        $rows = (array) ($data['namespaces'] ?? []);
        $total = 0;
        foreach ($seeded as $ns => $key) {
            self::assertIsInt($rows[$ns] ?? null, "第 {$ns} 行必须是删除数（整数），不是 null：清理失败不能混在成功里");
            self::assertGreaterThanOrEqual(1, $rows[$ns], "第 {$ns} 行报 0，但这一轮刚往它下面写过一枚键");
            self::assertNull(HotCache::get($key), "响应说清了 {$ns}，键却还在：{$key}");
            $total += (int) $rows[$ns];
        }

        self::assertSame($total, $data['total'], 'total 必须等于各行之和，不能另算一份');
    }

    // ─── 降级面 ──────────────────────────────────────────────

    /**
     * 握着一条已死的长连接时，每行都得是 null、`complete` 必须是 false。
     *
     * 这条是本轮的核心：`return 0` 把「什么都没清」写成了「清完了，只是没东西可清」，
     * 而运维看到的提示是「热缓存已清理（共 0 个键）」—— 与真相完全相反的两种状态
     * 共用一个数字（实测：未连接的 \Redis 调 scan() 返回 false，不是抛）。
     */
    public function test_dead_store_reports_incomplete_instead_of_zero(): void
    {
        $this->injectDeadConnection();

        $rows = HotCache::clearAll();

        foreach (HotCache::NAMESPACES as $ns) {
            // 键与值分两条断言：`$rows[$ns] ?? 'x'` 分不开「没有这一行」和「这行是 null」，
            // 而这里要钉的恰恰是后者。
            self::assertArrayHasKey($ns, $rows, "结果里少了 {$ns} 这一行");
            self::assertNull($rows[$ns],
                "第 {$ns} 行在连不上服务时报了「删了 0 个」：0 会被读成「这台系统没有缓存」，而不是「没清成」");
        }

        $data = (array) ($this->callCacheClear()['data'] ?? []);
        self::assertFalse($data['complete'] ?? true,
            '一条都清不动时 complete 不能是 true：接口不能对着失败的清理回成功');
    }

    /**
     * 「一键修复」不许把没做成的清理写成已执行的修复项。
     *
     * 它与 cacheClear 共用同一份结果（这是对的），但此前是自己拼字符串：
     * `menus:0 / config:0 / plugin:0` 进的是 `executed`，于是失败被列进「已修复」。
     */
    public function test_repair_does_not_list_a_failed_clear_as_done(): void
    {
        $this->injectDeadConnection();

        $response = $this->callController('POST', '/api/system/health/repair', 'repair');
        $data = (array) ($response['data'] ?? []);

        $executed = implode(' | ', array_map('strval', (array) ($data['executed'] ?? [])));
        self::assertStringNotContainsString('清理热缓存', $executed,
            '热缓存一个键都没清掉，却出现在「已执行的修复项」里：' . $executed);

        $skipped = implode(' | ', array_map('strval', (array) ($data['skipped'] ?? [])));
        self::assertStringContainsString('热缓存', $skipped,
            '没做成也要点名是哪个修复项没做成，否则运维以为全站已经修好了：' . $skipped);
    }

    // ─── 派发口 ──────────────────────────────────────────────

    private function callCacheClear(): array
    {
        return $this->callController('POST', '/api/system/cache-clear', 'cacheClear');
    }

    /**
     * @return array<string, mixed>
     */
    private function callController(string $method, string $path, string $action): array
    {
        $request = (new ServerRequest($method, $path))
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => 'admin', 'roles' => ['admin']]);
        HttpRequest::setRequest($request);

        $controller = new \app\admin\controllers\SystemController();
        /** @var Resp $response */
        $response = $controller->{$action}();
        $decoded = (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * 把 HotCache 的静态连接换成一条从未连上的客户端。
     *
     * 只动这一个进程内的静态属性，tearDown 里原样还回去 —— 不碰 redis 服务本身，
     * 更不去改 `.env` 把别人的调试会话一起锁死。
     */
    private function injectDeadConnection(): void
    {
        $property = self::redisProperty();
        $this->originalConn = $property->getValue(null);
        $property->setValue(null, new \Redis());
        $this->connSwapped = true;
    }

    private static function redisProperty(): \ReflectionProperty
    {
        $property = new \ReflectionProperty(HotCache::class, 'redis');
        $property->setAccessible(true);

        return $property;
    }
}
