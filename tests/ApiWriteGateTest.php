<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Testing\TestCase;
use Kode\Http\App;
use Kode\Http\Routing\Route;

/**
 * C 端（`app:api`）写侧门禁 —— 补 `CoreWriteGateTest` 看不到的那一半。
 *
 * 那条门禁只枚举标签为 `app:admin` 的路由（`tests/CoreWriteGateTest.php` 的 `coreRoutes()`），
 * 所以 `/c-api/*` 的写接口对它是**不存在**的：`POST /c-api/auth/register` 在修复前直接复用
 * 后台的 `UserController::store()`，匿名请求因此收得到 `dept_id`（往别人的部门里塞人），
 * 而 `kode_users` 的 `status` 一栏又默认 1 —— 没有一条测试会因此变红。
 *
 * 这里不能走运行时枚举：注册本身是 `if (env('C_API_REGISTER'))` 包着的，
 * 默认关闭时「扫到了 0 条写路由」和「全部合规」输出一模一样。所以判据读**源码**，
 * 环境开关关不掉一个静态扫描；再补一条运行时反向断言（默认配置下这条路由确实没挂上）。
 *
 * 每条登记还要求 handler 源码里真出现守卫符号 —— 白名单注释里写一句「有节流」不算过，
 * 注释会留着、代码里的调用可能早就被删（口径同 `PluginAnonymousRouteGateTest`）。
 */
final class ApiWriteGateTest extends TestCase
{
    /** 路由源码：门禁读它，而不是读注册后的路由表。 */
    private const ROUTES_FILE = __DIR__ . '/../app/api/routes.php';

    /**
     * `METHOD pattern` → 这条匿名写接口凭什么开着。
     *
     * 新增 `/c-api/*` 的 POST/PUT/PATCH/DELETE 必须先登记在这里，否则直接红；
     * 删了路由或补上鉴权就要同步删条目（死条目等于预留一次重新裸奔）。
     *
     * @var array<string, array{handler: string, guards: list<string>, fields: list<string>}>
     */
    private const WRITE_ROUTES = [
        'POST /c-api/auth/register' => [
            'handler' => 'RegisterController@register',
            'guards' => ['tooManyAttempts(', 'hitAttempt('],
            // 收参面钉死：多收一个字段 = 匿名请求多一个可写的列，改这里要写理由。
            'fields' => ['email', 'name', 'password'],
        ],
    ];

    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('C_API_REGISTER');
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        // 开关状态不许漏给后面的用例：本类的反向断言（默认不挂）依赖它是干净的。
        putenv('C_API_REGISTER');
        parent::tearDown();
    }

    public function test_write_registrations_are_found_in_the_source(): void
    {
        // 防空转：正则扫不到任何写注册时，下面的白名单检查会全绿但什么都没检查。
        $found = $this->writeRegistrations();
        self::assertGreaterThanOrEqual(1, count($found), 'app/api/routes.php 里扫不到任何非 GET 注册，正则口径疑似变了');
    }

    public function test_every_write_registration_is_listed_and_only_those(): void
    {
        $found = $this->writeRegistrations();
        $unlisted = array_values(array_diff($found, array_keys(self::WRITE_ROUTES)));
        $stale = array_values(array_diff(array_keys(self::WRITE_ROUTES), $found));

        self::assertSame([], $unlisted, "新增的 C 端写接口没有登记理由：\n  " . implode("\n  ", $unlisted));
        self::assertSame([], $stale, "登记过的 C 端写接口已不存在，请删掉条目：\n  " . implode("\n  ", $stale));
    }

    public function test_handlers_stay_on_api_controllers_and_name_their_guard(): void
    {
        $src = $this->codeOnly((string) file_get_contents(self::ROUTES_FILE));
        $problems = [];

        foreach (self::WRITE_ROUTES as $key => $spec) {
            [$class, $method] = explode('@', $spec['handler']);

            // 路由表里必须真的指向这个 handler —— 换成后台控制器就是本次修的东西。
            // 源码走 use + 短类名，所以两头都认：导入行 + resolve(...) 调用。
            if (!str_contains($src, "use app\\api\\controllers\\{$class};")) {
                $problems[] = "{$key}：routes.php 没有导入 app\\api\\controllers\\{$class}";
                continue;
            }
            if (!str_contains($src, "resolve({$class}::class)->{$method}()")) {
                $problems[] = "{$key}：路由里没有 resolve({$class}::class)->{$method}() 调用";
            }
            if (str_contains($src, 'app\\admin\\controllers')) {
                $problems[] = "{$key}：app/api 路由又开始复用后台控制器（本次修复的对象）";
            }

            $body = $this->methodSource('app\\api\\controllers\\' . $class, $method);
            if ($body === null) {
                $problems[] = "{$key}：handler {$class}::{$method} 不可反射";
                continue;
            }
            foreach ($spec['guards'] as $guard) {
                if (!str_contains($body, $guard)) {
                    $problems[] = "{$key}：handler 里没有守卫符号 {$guard}（预算被删了？）";
                }
            }
            if (str_contains($body, 'curl_init(')) {
                $problems[] = "{$key}：匿名端点里出现裸 curl_init(";
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    public function test_the_anonymous_field_surface_does_not_widen(): void
    {
        // 校验规则里的键名 = 匿名请求能写的列。收一个 `dept_id` 就是「往别人的部门塞人」，
        // 收一个 `roles` 就是自我授权，`status` 让注册接口能建禁用行。
        // 这条断言只认 `$this->validate(...)` 里的 `'键' => '规则'`，加字段必须显式改这里。
        $problems = [];
        foreach (self::WRITE_ROUTES as $key => $spec) {
            [$class, $method] = explode('@', $spec['handler']);
            $body = $this->methodSource('app\\api\\controllers\\' . $class, $method);
            if ($body === null) {
                $problems[] = "{$key}：handler 不可反射";
                continue;
            }
            preg_match_all("/'([a-z_]+)'\s*=>\s*'[a-z]/", $body, $m);
            $rules = array_values(array_unique($m[1] ?? []));
            sort($rules);
            $want = $spec['fields'];
            sort($want);
            if ($rules !== $want) {
                $problems[] = "{$key}：校验字段面变成 [" . implode(',', $rules) . "]，登记的是 [" . implode(',', $want) . "]";
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    public function test_the_register_route_is_absent_with_the_default_config(): void
    {
        // 源码门禁证明「写了什么」，这条证明「默认没挂上」：开关关掉的条件下
        // 这条匿名写接口不应该存在于路由表里。
        $patterns = [];
        foreach ($this->apiRoutes() as $route) {
            foreach (array_diff((array) $route->getMethods(), ['HEAD']) as $m) {
                $patterns[] = $m . ' ' . $route->getPattern();
            }
        }

        self::assertContains(
            'GET /c-api/ping',
            $patterns,
            'app:api 路由枚举口径变了（本类第二条断言依赖它），实际：' . implode(', ', $patterns)
        );
        self::assertNotContains(
            'POST /c-api/auth/register',
            $patterns,
            'C_API_REGISTER 默认 false，但注册路由挂上了 —— 开关不再兜底'
        );
    }

    public function test_the_switch_really_registers_the_route(): void
    {
        // 上一条只证明「默认没挂」；不开开关时它其实恒成立 —— 那条门禁可能挡的是空气。
        // 这条是正对照：把开关打开，路由必须真的挂上（且仍是同一条 pattern）。
        putenv('C_API_REGISTER=true');
        $this->bootApp(dirname(__DIR__));

        $patterns = [];
        foreach ($this->apiRoutes() as $route) {
            foreach (array_diff((array) $route->getMethods(), ['HEAD']) as $m) {
                $patterns[] = $m . ' ' . $route->getPattern();
            }
        }

        self::assertContains('POST /c-api/auth/register', $patterns, '开关已开，注册路由却没挂上：' . implode(', ', $patterns));
    }

    /* ==================== 工具 ==================== */

    /**
     * 源码里的非 GET 注册（`METHOD /path`）。
     *
     * @return list<string>
     */
    private function writeRegistrations(): array
    {
        $src = $this->codeOnly((string) file_get_contents(self::ROUTES_FILE));
        preg_match_all('/\$app->(post|put|patch|delete|options)\(\s*[\'"]([^\'"]+)[\'"]/', $src, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $row) {
            $out[] = strtoupper($row[1]) . ' ' . $row[2];
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /** 去掉注释：守卫判定只认真实代码，注释里留一句「有节流」不算过。 */
    private function codeOnly(string $src): string
    {
        $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

        return (string) preg_replace('#^\s*//.*$#m', '', $src);
    }

    private function methodSource(string $class, string $method): ?string
    {
        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }
        $ref = new \ReflectionMethod($class, $method);
        $file = $ref->getFileName();
        if ($file === false) {
            return null;
        }
        $lines = file($file);
        if (!is_array($lines)) {
            return null;
        }
        $start = (int) $ref->getStartLine() - 1;

        return $this->codeOnly(implode('', array_slice($lines, $start, max(0, (int) $ref->getEndLine() - $start))));
    }

    /** @return list<Route> */
    private function apiRoutes(): array
    {
        /** @var App $app */
        $app = resolve(App::class);
        /** @var RouteRegistry $registry */
        $registry = resolve(RouteRegistry::class);

        $out = [];
        foreach ($app->getRouter()->getRoutes() as $route) {
            if ((string) ($registry->sourceOf($route) ?? '') === 'app:api') {
                $out[] = $route;
            }
        }

        return $out;
    }
}
