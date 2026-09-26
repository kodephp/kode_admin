<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\middleware\RequirePerm;
use app\admin\middleware\RequireRole;
use Kode\Framework\Http\Middleware\AuthMiddleware;
use Kode\Framework\Http\RouteRegistry;
use Kode\Http\App;
use Kode\Http\Routing\Route;
use Kode\Framework\Testing\TestCase;

/**
 * 核心后台「写侧」门禁兑现（与 `CoreReadGateTest` 配对：那条管 GET，本条管其余方法）。
 *
 * 读侧收口做完，漏的那一半其实是写：一条 `POST` 只挂 `AuthMiddleware` 就等于
 * 「任何一枚有效 token 都能改」，而它不会让任何现有测试变红 —— 读侧白名单不看它，
 * 控制器单测又是自己注入 `auth` 属性绕开中间件的。本类把这条线固化：
 * 非 GET 的核心路由必须挂 `RequireRole` 或 `RequirePerm`，例外只能逐条写在下面并给理由。
 *
 * 例外的成立条件只有一个：**它操作的资源天然属于调用者本人**（自助改密、自己的头像、
 * 自己的收件箱）。凡是往全站共享状态里写的（改别人、改配置、删库存）都不算例外。
 */
final class CoreWriteGateTest extends TestCase
{
    /**
     * 允许「只验登录」的非 GET 核心路由 pattern → 理由。
     *
     * 新增写接口不在这里、也没挂角色/权限门禁就会红。加条目时先答一句：
     * 「这条改动落在谁的行上？」答不出「本人」就不该加。
     */
    private const SELF_SERVICE = [
        '/api/auth/login' => '登录本身（匿名）；防爆破在控制器内按 IP+账号 10 分钟 10 次计 429',
        '/api/auth/refresh' => '只换调用者自己的 token，并作废旧的那一枚',
        '/api/auth/password' => '自助改密：验原密码，只改本人，且撤销本人在发令牌',
        '/api/auth/profile' => '只改本人的 name/email/avatar',
        '/api/auth/login-logs/clean' => '控制器按 token uid 过滤，只删本人的登录日志',
        '/api/notifications/{id:[A-Za-z0-9]+}/read' => '只能把本人的收件行翻成已读（读别人那条 404）',
        '/api/notifications/read-all' => '同上，批量也只覆盖 user_id = 本人',
        '/api/notifications/clear' => '只删本人 user_id 且已读的行；广播行 user_id=0 全站共享，不在删除条件里',
        '/api/upload' => '个人中心换头像要走这条；产物落本人可选的媒体库，大小/类型/MIME 三重白名单在控制器内',
    ];

    /**
     * 白名单里**故意不验登录**的条目（其余例外必须仍挂 AuthMiddleware，见下面的测试）。
     * 登录接口挂了鉴权就永远进不来；它的闸门是控制器内的防爆破，不是 token。
     */
    private const ANONYMOUS = ['/api/auth/login'];

    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    /**
     * 核心后台路由（来源标签 app:admin）。
     *
     * @return list<Route>
     */
    private function coreRoutes(): array
    {
        /** @var App $app */
        $app = resolve(App::class);
        /** @var RouteRegistry $registry */
        $registry = resolve(RouteRegistry::class);

        $out = [];
        foreach ($app->getRouter()->getRoutes() as $route) {
            if (($registry->sourceOf($route) ?? '') === 'app:admin') {
                $out[] = $route;
            }
        }

        return $out;
    }

    /** 纯读路由：方法集只有 GET/HEAD（router 给 GET 自动补了 HEAD）。 */
    private function isReadOnly(Route $route): bool
    {
        return array_diff((array) $route->getMethods(), ['GET', 'HEAD']) === [];
    }

    private function hasRoleOrPermGate(Route $route): bool
    {
        foreach ($route->getMiddlewares() as $mw) {
            if ($mw instanceof RequireRole || $mw instanceof RequirePerm) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Route>
     */
    private function writeRoutes(): array
    {
        $out = [];
        foreach ($this->coreRoutes() as $route) {
            if (!$this->isReadOnly($route)) {
                $out[] = $route;
            }
        }

        return $out;
    }

    public function test_write_route_count_is_sane(): void
    {
        // 本类全部断言都建立在「枚举到了写路由」之上：数量掉到个位数说明
        // 来源标签口径或 getMethods() 语义变了，那时下面的循环会空转成全绿假象。
        $writes = $this->writeRoutes();
        self::assertGreaterThanOrEqual(90, count($writes), 'app:admin 的写路由应能枚举到，实际过少说明口径变了：' . count($writes));
    }

    public function test_every_write_route_is_gated_or_self_service(): void
    {
        $missing = [];
        foreach ($this->writeRoutes() as $route) {
            $pattern = (string) $route->getPattern();
            if ($this->hasRoleOrPermGate($route)) {
                continue;
            }
            if (isset(self::SELF_SERVICE[$pattern])) {
                continue;
            }
            $names = [];
            foreach ($route->getMiddlewares() as $mw) {
                $names[] = (new \ReflectionClass($mw))->getShortName();
            }
            $missing[] = implode('|', (array) $route->getMethods()) . ' ' . $pattern
                . ' [' . (implode(',', $names) ?: '无中间件') . ']';
        }

        self::assertSame([], $missing, "以下写接口对任何有效 token 开放：\n" . implode("\n", $missing));
    }

    public function test_self_service_entries_are_still_login_gated(): void
    {
        // 白名单条目连 AuthMiddleware 都没有 = 匿名可写，比漏挂角色更糟。
        $found = [];
        foreach ($this->writeRoutes() as $route) {
            $pattern = (string) $route->getPattern();
            if (!isset(self::SELF_SERVICE[$pattern])) {
                continue;
            }
            $found[] = $pattern;
            if (in_array($pattern, self::ANONYMOUS, true)) {
                continue;
            }
            $authed = false;
            foreach ($route->getMiddlewares() as $mw) {
                $authed = $authed || $mw instanceof AuthMiddleware;
            }
            self::assertTrue($authed, "{$pattern} 在白名单里但连 AuthMiddleware 都没挂");
        }

        // 反向：白名单里不该留已经不存在的条目（否则它永久占着一个「已审过」的名额）。
        $stale = array_diff(array_keys(self::SELF_SERVICE), $found);
        self::assertSame([], array_values($stale), 'SELF_SERVICE 存在已失效条目，请删除：' . implode(', ', $stale));
    }

    public function test_media_writes_are_admin_only(): void
    {
        // 媒体库的口径是「共享可读、只有超管可改」：KodeMediaPicker 内嵌在个人中心、
        // 富文本、站点设置里，所以读侧收不进任何菜单码（见 CoreReadGateTest 的白名单）；
        // 那么写侧就必须钉死，否则「人人可读」会顺带变成「人人可删」。
        $loose = [];
        foreach ($this->writeRoutes() as $route) {
            $pattern = (string) $route->getPattern();
            if (str_starts_with($pattern, '/api/media') || $pattern === '/api/storage/test') {
                if (!$this->hasRoleOrPermGate($route)) {
                    $loose[] = $pattern;
                }
            }
        }

        self::assertSame([], $loose, '媒体库写接口必须挂角色/权限门禁：' . implode(', ', $loose));
    }
}
