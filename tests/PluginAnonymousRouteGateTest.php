<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Testing\TestCase;
use Kode\Http\App;
use Kode\Http\Routing\Route;

/**
 * 插件匿名路由白名单门禁。
 *
 * 插件路由默认由 `FrontendPlugin` 之类的 `Manager::addRoute()` 自己声明中间件，框架不补
 * 任何鉴权。漏挂一枚中间件，端点就是**全网可达**的：本轮之前 `POST /api/workflow/webhook/{name}`
 * 挂着这个形状（不鉴权 + 签名可选），等于给外部留了一个匿名执行入口。
 *
 * 光看源码判不准：`->middleware(...)` 常写在下一行，正则扫源码会报出一堆假「裸奔」。
 * 所以这里走运行时口径（route 对象上真正生效的中间件集合），并要求：
 *  1. 每条匿名插件路由都在白名单里，且白名单里不许留死条目；
 *  2. 非 GET 的匿名路由，其 handler 源码里必须出现该路由登记的守卫符号（判定只写一份，
 *     handler 里真调用它才算数）。
 *
 * 白名单外的 GET 匿名路由同样是攻击面（信息泄漏），一律先补进这里写清凭什么公开。
 */
final class PluginAnonymousRouteGateTest extends TestCase
{
    protected bool $independentApp = true;

    /**
     * `METHOD pattern` → 守卫说明。
     *
     * 新增匿名插件路由必须先在这里登记理由；删掉路由/给它补上鉴权，就要同步删掉这一条
     * （死条目会让下一条新增悄悄通过）。
     */
    private const ALLOWED = [
        // uni3 前端壳的已发布外部契约：壳的 request.uts 只判 code===0，不带 JWT（见 AGENTS §7）
        'GET /api/plugins/frontend/manifest' => 'uni3 壳启动时拉配置，先于登录，属已发布外部契约',
        'GET /api/plugins/frontend/schema/{pageId}' => '同上：C 端按 pageId 取渲染协议',
        'GET /api/plugins/frontend/presets' => '同上：预设列表是公开内容',
        'POST /api/plugins/frontend/diagnostics' => '护栏回传上报，契约要求恒 HTTP 200（§7）；准入=按 IP 的 AttemptGuard 预算，落点=AuditTrail 唯一派发口且只记元数据',

        // 静态资产投递：内容本身公开，守卫是「落点必须仍在发布目录内」而非身份
        'GET /uni3-preview/{path}' => 'uni3 H5 构建产物；守卫=str_starts_with($realPath, $realBase) 包含判定',
        'GET /' => '管理端 index.html（SPA 入口）',
        'GET /js/{file}' => '管理端构建产物分片，同上（serveStaticAdmin 的包含判定）',
        'GET /css/{file}' => '同上',
        'GET /jse/{file}' => '同上',
        'GET /_app-config-{file}' => '同上',
        'GET /{path:.+}' => 'SPA fallback：未命中的前端路由回 index.html，交给前端路由表',

        // 兜底 404：只出信封，不碰 DB 不出网（前缀清单必须盖全，否则打错的 API 拿到 HTML）
        'GET /api/{path:.+}' => '未匹配 API 的 JSON 404',
        'POST /api/{path:.+}' => '同上',
        'PUT /api/{path:.+}' => '同上',
        'PATCH /api/{path:.+}' => '同上',
        'DELETE /api/{path:.+}' => '同上',
        'GET /c-api/{path:.+}' => 'C 端未匹配 API 的 JSON 404',
        'POST /c-api/{path:.+}' => '同上',
        'PUT /c-api/{path:.+}' => '同上',
        'PATCH /c-api/{path:.+}' => '同上',
        'DELETE /c-api/{path:.+}' => '同上',

        // 工作流 webhook 触发：外部系统调用，身份由签名/IP 承担（见 WorkflowWebhookGateTest）
        'POST /api/workflow/webhook/{name}' => '入站触发器，守卫=webhookAdmission（密钥与 IP 白名单至少一道）',
    ];

    /**
     * 非 GET 匿名路由 → handler 源码里必须出现的守卫符号。
     *
     * 这些端点没有身份层、公开可写，所以「它凭什么安全」必须能在 handler 里指着某一行说清楚；
     * 只写在白名单注释里不算（注释会留着，代码里的守卫可能早就被删了）。
     *
     * 值可以是字符串或字符串数组：一个入口要同时回答「凭什么准入」与「落到哪」两件事时，
     * 只钉住其中一个，另一个就可以被换回危险的那条路而门禁全绿
     * （diagnostics 登记过 `"Db::insert('kode_audit_logs'"` —— 那是**写坏的那一行**本身：
     * 表名被当 SQL 传、列 `message` 根本不存在，于是永远 0 行落库、永远回 code:0 ok，
     * 而门禁正因为这行字面量在才一直放行。准入 + 落点两个符号一起钉才拦得住）。
     */
    private const WRITE_GATES = [
        'POST /api/plugins/frontend/diagnostics' => ['tooManyAttempts(self::DIAG_PREFIX', 'AuditTrail::record('],
        'POST /api/workflow/webhook/{name}' => 'webhookAdmission(',
        'POST /api/{path:.+}' => 'Response(404',
        'PUT /api/{path:.+}' => 'Response(404',
        'PATCH /api/{path:.+}' => 'Response(404',
        'DELETE /api/{path:.+}' => 'Response(404',
        'POST /c-api/{path:.+}' => 'Response(404',
        'PUT /c-api/{path:.+}' => 'Response(404',
        'PATCH /c-api/{path:.+}' => 'Response(404',
        'DELETE /c-api/{path:.+}' => 'Response(404',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    public function test_anonymous_plugin_routes_are_discoverable(): void
    {
        // 枚举口径一变（框架改名、插件路由不再打 plugin: 标签），下面的白名单检查会退化成
        // 「一条都没扫到」的全绿假象，所以先钉一个下限。
        $anon = $this->anonymousPluginRoutes();
        self::assertNotEmpty($this->pluginRoutes(), 'plugin:* 路由应能枚举到，为空说明来源标签口径变了');
        self::assertGreaterThanOrEqual(5, count($anon), '匿名插件路由少于 5 条，枚举口径疑似被改');
    }

    public function test_no_plugin_route_is_anonymous_outside_the_allowlist(): void
    {
        $found = [];
        foreach ($this->anonymousPluginRoutes() as $route) {
            // GET 自动带一份 HEAD（框架加的），它不是另一个端点：单独登记会把白名单写糊
            foreach (array_diff($route->getMethods(), ['HEAD']) as $m) {
                $found[] = [$m . ' ' . $route->getPattern()];
            }
        }

        $keys = array_map(static fn (array $row): string => $row[0], $found);
        $unlisted = array_values(array_diff($keys, array_keys(self::ALLOWED)));
        $stale = array_values(array_diff(array_keys(self::ALLOWED), $keys));

        self::assertSame(
            [],
            $unlisted,
            "出现白名单外的匿名插件路由：\n  " . implode("\n  ", $unlisted)
                . "\n补鉴权，或登记进 ALLOWED 并写清它凭什么对匿名调用开放"
        );
        self::assertSame(
            [],
            $stale,
            "白名单里有死条目（路由已删除或已补鉴权）：\n  " . implode("\n  ", $stale)
                . "\n留着就等于允许它以后重新裸奔"
        );
    }

    public function test_every_anonymous_write_route_names_its_guard_in_the_handler(): void
    {
        $problems = [];
        foreach ($this->anonymousPluginRoutes() as $route) {
            $methods = array_values(array_diff($route->getMethods(), ['GET', 'HEAD']));
            if ($methods === []) {
                continue;
            }
            $key = $methods[0] . ' ' . $route->getPattern();
            $symbol = self::WRITE_GATES[$key] ?? null;
            if ($symbol === null) {
                $problems[] = "{$key}：非 GET 匿名路由没有登记守卫符号";
                continue;
            }
            $src = $this->handlerSource($route);
            if ($src === null) {
                $problems[] = "{$key}：handler 不可反射，无法确认它真的做了 " . implode(' + ', (array) $symbol);
                continue;
            }
            foreach ((array) $symbol as $one) {
                if (!str_contains($src, $one)) {
                    $problems[] = "{$key}：handler 里没有出现守卫符号 {$one}";
                }
            }
            // 匿名可写端点绝不自带出网：SSRF 只能经 RemoteFetch 闸门，而闸门本身不在这里
            if (str_contains($src, 'curl_init(')) {
                $problems[] = "{$key}：匿名端点里出现裸 curl_init(";
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /* ==================== 工具 ==================== */

    /** @return list<Route> */
    private function pluginRoutes(): array
    {
        /** @var App $app */
        $app = resolve(App::class);
        /** @var RouteRegistry $registry */
        $registry = resolve(RouteRegistry::class);

        $out = [];
        foreach ($app->getRouter()->getRoutes() as $route) {
            if (str_starts_with((string) ($registry->sourceOf($route) ?? ''), 'plugin:')) {
                $out[] = $route;
            }
        }

        return $out;
    }

    /** @return list<Route> 一条中间件都没挂的插件路由 */
    private function anonymousPluginRoutes(): array
    {
        return array_values(array_filter(
            $this->pluginRoutes(),
            static fn (Route $r): bool => $r->getMiddlewares() === []
        ));
    }

    /** @return array{0: class-string, 1: string}|null */
    private function handlerOf(Route $route): ?array
    {
        $handler = $route->getHandler();
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[1])) {
            $class = is_object($handler[0]) ? get_class($handler[0]) : (string) $handler[0];

            return class_exists($class) ? [$class, $handler[1]] : null;
        }
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);

            return class_exists($class) && method_exists($class, $method) ? [$class, $method] : null;
        }

        return null;
    }

    private function handlerSource(Route $route): ?string
    {
        $pair = $this->handlerOf($route);
        if ($pair === null) {
            return null;
        }
        $ref = new \ReflectionMethod($pair[0], $pair[1]);
        $file = $ref->getFileName();
        if ($file === false) {
            return null;
        }
        $lines = file($file);
        if (!is_array($lines)) {
            return null;
        }

        $start = (int) $ref->getStartLine() - 1;

        return implode('', array_slice($lines, $start, max(0, (int) $ref->getEndLine() - $start)));
    }
}
