<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Testing\TestCase;
use Kode\Http\App;
use Kode\Http\Routing\Route;
use Kode\Http\Routing\Router;
use Kode\Http\Routing\RouteResult;

/**
 * 「注册 ≠ 可达」：逐条用真实 dispatch 验证路由表，每条路由都必须被**自己 pattern 生成的 URL** 命中。
 *
 * 为什么需要这条门禁：本应用的动态匹配是**按注册顺序先到先得**
 * （`vendor/kode/http` 的 `Router::doMatch()`：先查静态表，再 `foreach ($this->dynamic[$method])`
 * 取第一个 `match()` 成功的），所以一条贪婪参数路由（`/{path:.+}`、`/api/{path:.+}`）
 * 只要注册在别人前面，就会把后面所有参数化路由悄悄吞成兜底响应 —— 不报错、不告警，
 * `kode route:list` 里那条路由照常列出。同 pattern + 同方法的重复注册更直接：
 * 静态表里后写的覆盖先写的，先写的那条当场变死路由（`/api/workflow/templates` 就长期是这样）。
 *
 * 而注册顺序恰好是**最容易被改动又最没人看**的一处：`config/plugins.php` 的数组顺序就是插件
 * 路由的注册顺序，`PluginScaffolder::registerPlugin()` 又是**往数组末尾追加**的，
 * 于是「兜底路由必须最后注册」这条不变量只能靠人工维持。`FrontendPlugin` 今天正好排在末位
 * （`plugins/FrontendPlugin.php` 里那两句注释就是它踩过的坑），但任何排在其后的插件，
 * 它的 `/api/xxx/{id}` 从装上下来的那一刻就是死的。
 *
 * 现有门禁全都看不见这个：`ApiDocRouteGateTest` 只比对「文档 pattern 集合 ⊆ 路由表 pattern 集合」，
 * 一条注册了却永远匹配不到的路由在它眼里完全合格；控制器单测自己 `HttpRequest::setRequest()` 直接调
 * 控制器方法，连路由都不走。
 */
final class RouteReachabilityTest extends TestCase
{
    protected bool $independentApp = true;

    /**
     * 实际 dispatch 过的路由条数下限（按路由计，不按方法计）。
     *
     * 解析规则一改就跑空的话，本门禁会静默变成永真断言 —— 留余量但必须挡住「一条都没测」。
     */
    private const MIN_ROUTES_CHECKED = 300;

    /**
     * 参数候选值：对声明的约束正则逐个试，取第一个能**整段**匹配的。
     *
     * `[^/]+` 会挑中 `x1`，`.+` 会挑中带斜杠的 `a/b`（贪婪路由的本体形状），
     * `[A-Za-z0-9]+` 只会挑中 `x1` —— 别让 `[^/]+` 的默认形状去测 `.+`，那测不到跨斜杠。
     *
     * @var list<string>
     */
    private const SAMPLES = ['x1', 'a/b', 'a/b/c', 'zzreachabilityprobe', '2024-01-02', '7'];

    /** 与 `Route::compile()` 同一套占位符语法（含可选参数与内联约束）。 */
    private const PLACEHOLDER = '/\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*(\?)?\s*(?::\s*((?:[^{}]|\{\d+(?:,\d*)?\})+?)\s*)?\}/';

    /**
     * 允许的「同 pattern 重复注册」：pattern => 理由。
     *
     * 只放行一种形状 —— **无来源标签的那条（框架自己注册的）被本应用 `app` 标签那条覆盖**。
     * 插件吞插件、子应用吞别家的路由都不在此列，一律算缺陷。
     */
    private const INTENTIONAL_SHADOWS = [
        '/health' => 'app/routes.php 刻意覆盖框架内置的同名探针：要在一份报告里同时给出内核版本与骨架版本，'
            . '并让 status 随探针结果翻成 degraded/503（框架那条恒回 ok）。'
            . '判定/脱敏/「不进信封」三条由 tests/HealthDegradedReportTest.php'
            . ' + tests/ErrorResponseEnvelopeTest.php 兑现；两侧任一边变了就删掉本条目。',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    public function test_every_registered_route_is_reachable_by_its_own_url(): void
    {
        /** @var App $app */
        $app = resolve(App::class);
        $report = $this->audit($app->getRouter());

        self::assertGreaterThanOrEqual(
            self::MIN_ROUTES_CHECKED,
            $report['checked'],
            '只 dispatch 了 ' . $report['checked'] . ' 条路由（下限 ' . self::MIN_ROUTES_CHECKED . '）：'
            . '占位符解析规则或路由表来源变了，本门禁正在空转'
        );

        self::assertSame([], $report['failures'], $this->failureText($report['failures']));
    }

    /* ==================== 门禁自身的对照（证明它真能看见吞路由） ==================== */

    /**
     * 负对照：兜底路由注册在前时，本门禁必须报出被它吞掉的那条 —— 否则「全绿」只是没测到。
     *
     * 形状刻意照抄 `FrontendPlugin`：`/api/{path:.+}` 之后注册的 `/api/users/{id}` 会被
     * `#^/api/(?P<path>.+)$#` 整段吃掉（`.+` 跨斜杠），处理器换成 apiNotFound。
     */
    public function test_the_checker_does_report_a_greedy_route_registered_first(): void
    {
        $router = new Router();
        $router->add('GET', '/api/{path:.+}', 'zzFallback');
        $router->add('GET', '/api/users/{id}', 'zzShowUser');

        $report = $this->audit($router);

        self::assertSame(2, $report['checked'], '对照路由数不符，说明用例形状或计数口径变了');
        self::assertCount(1, $report['failures'], '没测出「贪婪在前吞掉后注册路由」：' . json_encode($report['failures']));
        self::assertStringContainsString('/api/users/{id}', $report['failures'][0]);
        self::assertStringContainsString('/api/{path:.+}', $report['failures'][0]);
    }

    /**
     * 正对照：同两条路由，特定在前、兜底在后 —— 必须一条都不报。
     *
     * 少了这一半，上面那个断言可以靠「任何情况都返回失败」蒙过去。
     */
    public function test_the_checker_stays_quiet_when_the_specific_route_comes_first(): void
    {
        $router = new Router();
        $router->add('GET', '/api/users/{id}', 'zzShowUser');
        $router->add('GET', '/api/{path:.+}', 'zzFallback');

        $report = $this->audit($router);

        self::assertSame(2, $report['checked']);
        self::assertSame([], $report['failures'], json_encode($report['failures'], JSON_UNESCAPED_SLASHES));
    }

    /**
     * 「先到先得」只发生在**两条动态路由之间**：无参字面量路由走静态查表，注册顺序不参与。
     *
     * `app/admin/routes.php` 里 `/api/configs/site` 那段注释写过「必须注册在
     * `/api/configs/{key}` 之前，否则被它吞掉」—— 那是把上面两条对照的结论错搬到了
     * 静态/动态之间。`Router::doMatch()` 是「先查 `$this->static[$method][$path]`，
     * 再遍历 `$this->dynamic[$method]`」，所以字面量在后也照样赢。
     * 口径写错的代价不是空转而是**反方向**：改路由的人会被误导去做没有必要的挪动，
     * 并且以为静态路由之间存在顺序保护（并不存在，同 pattern 的覆盖见下一条）。
     * 两条断言一起钉住形状：字面量赢、参数路由仍然接得住自己的 URL。
     */
    public function test_a_literal_route_beats_a_dynamic_one_regardless_of_registration_order(): void
    {
        $router = new Router();
        $router->add('GET', '/api/configs/{key}', 'zzGetConfig');
        $router->add('GET', '/api/configs/site', 'zzSite');

        $literal = $router->match('GET', '/api/configs/site');
        self::assertTrue($literal->isFound());
        self::assertSame('zzSite', $literal->route?->getHandler(), '字面量路由被先注册的参数路由吞了：静态表并非先查');

        $dynamic = $router->match('GET', '/api/configs/zzany');
        self::assertTrue($dynamic->isFound());
        self::assertSame('zzGetConfig', $dynamic->route?->getHandler(), '参数路由接不住自己的 URL');
    }

    /**
     * 重复注册同一 pattern + 同一方法：后一条会覆盖静态表里的前一条，前一条当场变死路由。
     *
     * 同时校验「按路由聚合成一条」：GET 自动补的 HEAD 与 GET 是同一个形状，
     * 分两条报会让失败列表虚增一倍，真正的噪声就被淹掉了。
     */
    public function test_the_checker_does_report_a_route_shadowed_by_an_identical_later_one(): void
    {
        $router = new Router();
        $router->add('GET', '/api/zz-dup', 'zzFirst');
        $router->add('GET', '/api/zz-dup', 'zzSecond');

        $report = $this->audit($router);

        self::assertCount(1, $report['failures'], '重复注册没被发现（或按方法虚增了条数）：' . json_encode($report['failures']));
        self::assertStringContainsString('重复注册', $report['failures'][0]);
        self::assertStringContainsString('GET/HEAD', $report['failures'][0], '失败行应列出受影响的方法集而不是逐方法各报一条');
    }

    /** 白名单必须双向：条目对应的覆盖关系真的存在才留着，否则它只是下一个「已审名额」。 */
    public function test_the_shadow_allowlist_has_no_stale_entry(): void
    {
        /** @var App $app */
        $app = resolve(App::class);
        $report = $this->audit($app->getRouter());

        $unused = array_diff(array_keys(self::INTENTIONAL_SHADOWS), $report['shadows']);
        self::assertSame([], array_values($unused), 'INTENTIONAL_SHADOWS 存在已失效条目（该覆盖关系已不存在），请删除：'
            . implode(', ', $unused));
    }

    /* ==================== 审计主体 ==================== */

    /**
     * 用 router 自己的 `match()` 逐条验证：每条路由都要被自己生成的 URL 命中，且命中的是自己。
     *
     * 一条路由的多个方法（GET 与其自动补的 HEAD）通常同生同死，所以失败**按路由聚合**成一条，
     * 方法集写进行文本；`checked` 同样按路由计。
     *
     * @return array{checked: int, failures: list<string>, shadows: list<string>}
     */
    private function audit(Router $router): array
    {
        $checked = 0;
        /** @var list<string> $failures */
        $failures = [];
        /** @var list<string> $shadows 被放行的同 pattern 覆盖（供白名单反向校验） */
        $shadows = [];

        foreach ($router->getRoutes() as $route) {
            $concrete = $this->concretize($route->getPattern());
            if ($concrete === null) {
                // 造不出 URL 就等于没测这条路由，算失败而不是跳过（跳过是空转的入口）。
                $failures[] = $this->describe($route) . '：无法从 pattern 生成可 dispatch 的 URL';
                continue;
            }

            $checked++;

            /** @var array<string, list<string>> $buckets 失败原因 => 受影响方法 */
            $buckets = [];
            foreach ($route->getMethods() as $method) {
                $result = $router->match($method, $concrete);

                if (!$result->isFound()) {
                    $key = $result->status === RouteResult::METHOD_NOT_ALLOWED
                        ? '405，方法被别的同名路由占了（allowed: ' . implode('/', $result->allowedMethods) . '）'
                        : '404，压根没匹配上任何路由';
                    $buckets[$key][] = $method;
                    continue;
                }

                if ($result->route === $route) {
                    continue;
                }

                $samePattern = $result->route?->getPattern() === $route->getPattern();
                if ($samePattern && $this->isAllowedShadow($route, $result->route)) {
                    $shadows[] = (string) $route->getPattern();
                    continue;
                }

                $key = '命中的是' . ($samePattern ? '重复注册' : '被更靠前的路由') . ' → ' . $this->describe($result->route)
                    . ($samePattern ? '（同 pattern 后注册者覆盖前者，前者已成死路由）' : '');
                $buckets[$key][] = $method;
            }

            foreach ($buckets as $reason => $methods) {
                $failures[] = $this->describe($route)
                    . '（' . implode('/', array_unique($methods)) . ' ' . $concrete . '）：' . $reason;
            }
        }

        return ['checked' => $checked, 'failures' => $failures, 'shadows' => array_values(array_unique($shadows))];
    }

    /**
     * 只放行「框架内置路由被本应用 app 标签那一条覆盖」。
     *
     * 判据两边都钉住：被吞的那一条必须**没有来源标签**（框架直接 `$app->get()` 注册的，
     * 不经 RouteRegistry 打标），吞人的那一条必须是 `app`。反过来的形状（插件吞掉 app 路由）
     * 是真缺陷，绝不能被同一张白名单顺手放过。
     */
    private function isAllowedShadow(Route $loser, ?Route $winner): bool
    {
        $pattern = (string) $loser->getPattern();
        if (!isset(self::INTENTIONAL_SHADOWS[$pattern])) {
            return false;
        }

        return $this->sourceOf($loser) === '' && $this->sourceOf($winner ?? $loser) === 'app';
    }

    /** 出处标签（app / app:admin / plugin:frontend…）；框架自己注册的路由没有标签，返回空串。 */
    private function sourceOf(Route $route): string
    {
        try {
            /** @var RouteRegistry $registry */
            $registry = resolve(RouteRegistry::class);

            return (string) ($registry->sourceOf($route) ?? '');
        } catch (\Throwable) {
            return ''; // 合成 Router（对照用例）里没有 registry
        }
    }

    /** 出处标签 + pattern + 路由名，让失败直接指到该改的那个文件。 */
    private function describe(?Route $route): string
    {
        if ($route === null) {
            return '(不存在)';
        }

        $source = $this->sourceOf($route);

        return ($source === '' ? '' : "[{$source}] ") . $route->getPattern()
            . ($route->getName() === null ? '' : ' #' . $route->getName());
    }

    /**
     * pattern → 一个真实可 dispatch 的 URL；造不出来返回 null。
     *
     * 可选参数按「不传」的最短形状生成（`/api/x/{id?}` → `/api/x`），
     * 这才是它作为可选参数被使用的那一面。
     */
    private function concretize(string $pattern): ?string
    {
        $out = $pattern;
        $guard = 0;

        while (preg_match(self::PLACEHOLDER, $out, $m) === 1) {
            if (++$guard > 12) {
                return null; // 占位符没被替换掉 = 正则自己出问题了，别死循环
            }

            $constraint = ($m[3] ?? '') === '' ? '[^/]+' : $m[3];
            $sample = $this->sampleFor($constraint);
            if ($sample === null) {
                return null;
            }

            // 可选参数连同它前面的 `/` 一起去掉（Route::compile 把 `/(?:group)?` 做成了一体）
            $replacement = (($m[2] ?? '') === '?') ? '' : $sample;
            $needle = ($m[2] ?? '') === '?' ? '/' . $m[0] : $m[0];
            $pos = ($m[2] ?? '') === '?' ? strrpos($out, $needle) : strpos($out, $needle);
            $out = $pos === false
                ? str_replace($m[0], $replacement, $out)
                : (substr($out, 0, (int) $pos) . $replacement . substr($out, (int) $pos + strlen($needle)));
        }

        return $out === '' ? null : $out;
    }

    /** 按约束挑第一个整段匹配的候选值；约束本身不合法时返回 null（当成缺陷报出来）。 */
    private function sampleFor(string $constraint): ?string
    {
        foreach (self::SAMPLES as $sample) {
            $matched = @preg_match('#^(?:' . $constraint . ')$#', $sample);
            if ($matched === false) {
                return null;
            }
            if ($matched === 1) {
                return $sample;
            }
        }

        return null;
    }

    /** @param list<string> $failures */
    private function failureText(array $failures): string
    {
        return "这些路由注册了却永远匹配不到（前端照抄即 404，`route:list` 里却列得出来）：\n  "
            . implode("\n  ", $failures)
            . "\n贪婪兜底路由（`{x:.+}`）必须注册在最后，或者干脆改走 App::notFound()；"
            . '动态匹配是先到先得，见 tests/RouteReachabilityTest.php 的类注释。';
    }
}
