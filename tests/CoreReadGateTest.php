<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\middleware\RequirePerm;
use app\admin\middleware\RequireRole;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Middleware\AuthMiddleware;
use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Testing\TestCase;
use Kode\Http\App;
use Kode\Http\Routing\Route;

/**
 * 核心只读接口的门禁基线。
 *
 * 治的是这个系统性形状：`app/admin/routes.php` 里只有两种门禁 —— `$auth`（只验登录）
 * 和 `$admin`（超管），RequirePerm 一次都没用过。于是写侧、导出侧全是 $admin，
 * 而读侧（用户名单、系统库存、审计聚合、配置整表、存储通道…）一枚有效 token 就能拉走：
 * 权限模型在菜单层「看得见才点得到」，接口层却完全不设防，勾选角色形同虚设。
 *
 * 口径：只读接口的权限码 = 渲染它那张菜单页的 `kode_menus.auth_code`（菜单可见 ⟺ 接口可调）。
 * 例外只有三类，逐条写在 ALLOWED 里并附理由：
 *  1) 出参天然按本人收敛（mine / 我的通知 / auth-me / 站点品牌 / 带 scope 的汇总）；
 *  2) 全站布局依赖、且已按 auth_code 在服务端过滤过内容的菜单树；
 *  3) 被**内嵌组件**消费的共享资源库（媒体库：选择器在个人中心/富文本/站点设置里都出现，
 *     没有一张独占的菜单页可以代表它）—— 这类只能靠**写侧**兜底，见 `CoreWriteGateTest`
 *     的 `test_media_writes_are_admin_only`：人人可读必须配「只有超管可改」才成立。
 *
 * 同时锁两条配套约束：门禁码必须在 `kode_menus.auth_code` 里存在（否则角色页根本勾不到，
 * 非超管永久 403 —— 与 `PluginContract::auditPermGates()` 同向，只是这里管核心路由）；
 * 字面量路由必须注册在同段的参数路由之前（`/api/configs/site` 会被 `/api/configs/{key}` 吞掉）。
 */
final class CoreReadGateTest extends TestCase
{
    /**
     * 允许「只验登录」的核心 GET 路由 → 理由。
     *
     * 新增只读接口不在这里、也没挂 RequirePerm 就会红：要么挂菜单码，
     * 要么在这里写清它凭什么对全体登录用户开放。
     */
    private const ALLOWED = [
        '/api/auth/me' => '本人身份，出参只有自己',
        '/api/auth/login-logs' => '控制器按 token uid 自过滤（kode_login_logs.user_id）',
        '/api/menus' => '侧边栏数据源；树内容已在服务端按调用者 perms/auth_code 过滤',
        '/api/notifications' => '本人收件箱（user_id IN (0, 本人)）',
        '/api/notifications/unread-count' => '同上，只是计数',
        '/api/audits/mine' => '按 token 用户名自过滤',
        '/api/audits/stats' => '非超管只看自己触发的行（AuditController::stats 的 username 条件）',
        '/api/dashboard/summary' => '非超管只拿到 scope=self 的今日请求数，全站库存不出库',
        '/api/configs/site' => '登录页要显示站点标题；只出 SysConfig 的 site.* 白名单键',
        '/api/secure/ping' => '签名演示端点，另挂 ApiSignMiddleware',
        '/api/media' => '媒体选择器（KodeMediaPicker）内嵌在个人中心换头像、富文本插图、站点设置里，'
            . '不是 /media 那一页独有 → 收不进任何菜单码；写侧全部钉死超管（见 CoreWriteGateTest）',
        '/api/media/months' => '同上：与 /api/media 同一 index() 方法，只是按月份进目录',
        '/api/media/dirs' => '同上：选择器左侧的目录树',
        '/api/media/resolve' => 'utils/media.ts 的 mediaUrl() 被 layouts/basic.vue（顶栏头像，每页都渲染）调用',
        '/api/files/{path:.+}' => 'resolve 出来的 URL 就落在这个下载通道上；挂菜单码等于全站头像裂开',
    ];

    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    /**
     * 核心后台路由（来源标签 app:admin），按注册顺序。
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

    /** @return list<string> 路由上出现的权限码（不含超管旁路） */
    private function gateCodes(Route $route): array
    {
        $codes = [];
        foreach ($route->getMiddlewares() as $mw) {
            if ($mw instanceof RequirePerm) {
                foreach ($mw->perms() as $code) {
                    $codes[] = trim((string) $code);
                }
            }
        }

        return array_values(array_filter($codes, static fn(string $c): bool => $c !== ''));
    }

    public function test_route_source_label_is_recognised(): void
    {
        // 来源标签变了（或被框架改名）时，下面的门禁检查会退化成「一条都没扫到」的全绿假象。
        $core = $this->coreRoutes();
        self::assertGreaterThan(50, count($core), 'app:admin 路由应能枚举到，实际过少说明标签口径变了');

        $gated = 0;
        foreach ($core as $route) {
            if (in_array('GET', $route->getMethods(), true) && $this->gateCodes($route) !== []) {
                $gated++;
            }
        }
        // 只读收口的地板：低于这个数就说明 $can(...) 被成批改回了 $auth（本类其它断言在
        // 白名单被同步扩大时也会一起变绿，只有这条能发现「门禁整体被拆掉」）。
        self::assertGreaterThanOrEqual(30, $gated, "核心 GET 路由挂权限码门禁的只有 {$gated} 条");
    }

    public function test_every_core_get_route_is_gated_or_explicitly_allowed(): void
    {
        $missing = [];
        $noAuth = [];
        foreach ($this->coreRoutes() as $route) {
            if (!in_array('GET', $route->getMethods(), true)) {
                continue;
            }
            $pattern = $route->getPattern();
            $mws = $route->getMiddlewares();
            $hasAuth = false;
            $hasCeiling = false;
            foreach ($mws as $mw) {
                if ($mw instanceof AuthMiddleware) {
                    $hasAuth = true;
                }
                if ($mw instanceof RequireRole || $mw instanceof RequirePerm) {
                    $hasCeiling = true;
                }
            }
            if (!$hasAuth) {
                $noAuth[] = $pattern;
                continue;
            }
            if ($hasCeiling || isset(self::ALLOWED[$pattern])) {
                continue;
            }
            $missing[] = $pattern;
        }

        self::assertSame([], $noAuth, '核心 GET 路由未挂 AuthMiddleware：' . implode(', ', $noAuth));
        self::assertSame([], $missing, '核心 GET 路由既无角色/权限码门禁，也不在 ALLOWED 白名单：' . implode(', ', $missing));
    }

    public function test_allowlist_entries_are_still_login_gated(): void
    {
        // 白名单只豁免「权限码」这一层，不豁免登录：漏了 AuthMiddleware 的条目等于对公网敞开。
        $seen = [];
        $problems = [];
        foreach ($this->coreRoutes() as $route) {
            $pattern = $route->getPattern();
            if (!isset(self::ALLOWED[$pattern])) {
                continue;
            }
            $seen[] = $pattern;
            $hasAuth = false;
            foreach ($route->getMiddlewares() as $mw) {
                if ($mw instanceof AuthMiddleware) {
                    $hasAuth = true;
                }
            }
            if (!$hasAuth) {
                $problems[] = $pattern;
            }
        }

        self::assertSame([], $problems, 'ALLOWED 路由必须仍挂 AuthMiddleware：' . implode(', ', $problems));
        $stale = array_values(array_diff(array_keys(self::ALLOWED), $seen));
        self::assertSame([], $stale, 'ALLOWED 里有条目已不存在（收口完成后请删除，别留免死金牌）：' . implode(', ', $stale));
    }

    public function test_gate_codes_exist_as_menu_auth_codes(): void
    {
        $declared = [];
        foreach (Db::select("SELECT DISTINCT auth_code FROM kode_menus WHERE auth_code <> ''") as $row) {
            $declared[] = (string) $row['auth_code'];
        }

        $problems = [];
        foreach ($this->coreRoutes() as $route) {
            foreach ($this->gateCodes($route) as $code) {
                if (!in_array($code, $declared, true)) {
                    $problems[] = $route->getPattern() . ' → ' . $code;
                }
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($problems)),
            '门禁码不在 kode_menus.auth_code 里：角色页勾不到它，非超管用户永久 403。'
        );
    }

    public function test_site_config_route_precedes_the_parametrized_one(): void
    {
        // /api/configs/{key} 的 {key} 没有正则约束，且 'site' 过得了控制器里那条 key 正则，
        // 注册顺序颠倒会让站点标题接口变成「读一个名为 site 的配置项」（404）。
        $order = [];
        $i = 0;
        foreach ($this->coreRoutes() as $route) {
            if (!in_array('GET', $route->getMethods(), true)) {
                continue;
            }
            $order[$route->getPattern()] ??= $i;
            $i++;
        }
        self::assertArrayHasKey('/api/configs/site', $order);
        self::assertArrayHasKey('/api/configs/{key}', $order);
        self::assertLessThan(
            $order['/api/configs/{key}'],
            $order['/api/configs/site'],
            '字面量路由 /api/configs/site 必须先于 /api/configs/{key} 注册'
        );
    }
}
