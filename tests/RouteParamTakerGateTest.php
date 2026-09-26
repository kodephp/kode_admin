<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Testing\TestCase;
use Kode\Http\App;
use Kode\Http\Routing\Route;
use Kode\Http\Routing\Router;

/**
 * `$this->param('x')` 只能读 URL pattern 里真有的 `{x}`（#68「json() 当取参器」的姊妹门禁）。
 *
 * 为什么单独锁这一条：`param()` 与 `query()` 在控制器里挨着写、签名一样、**读的来源毫无交集** ——
 * `Kode\Http\Request::param()` 只回 `_route_params[$name]`（路由匹配的产物），不回落查询串也不回落请求体。
 * 所以把 `param('path')` 写在一条 pattern 只有 `{name}` 的路由上，PHP 不报错、框架不报错、控制器单测也不报错，
 * 那个参数只是**恒为默认值**：
 *  - `GET /api/plugins/{name}/files/read` 的 `param('path')` ⇒ 「路径非法」400 无条件返回，
 *    代码编辑器点开任何文件都失败（这就是「/code-editor 打不开」的真身）；
 *  - 两个 code-search 的 `param('keyword')` ⇒ 搜索框敲什么都回「关键词必填」；
 *  - `logs()` 的 `param('page'/'size'/'level')` ⇒ 日志抽屉永远停在第一页，
 *    而 total 走的是 COUNT(*)，于是「一共只有 20 条」看着完全正常。
 * 三处都是本次实测出来的（前端 `api/kode.ts` 一律以 `?path=` / `?keyword=` / `?page=` 发查询串）。
 *
 * 口径是**逐方法对账**，不是「全仓 param 键 ⊆ 全仓占位符并集」：
 * 后者会放过「`{id}` 路由与 `{name}` 路由互借键名」的错法，而上面三个缺陷恰好都是
 * 「别的路由有这个占位符，这条没有」。
 */
final class RouteParamTakerGateTest extends TestCase
{
    protected bool $independentApp = true;

    /** 至少分析这么多个「路由 → 控制器方法」对，否则解析规则一改就会静默空转。 */
    private const MIN_METHODS_ANALYZED = 120;

    /** pattern 占位符（与 `Route::compile()` 同一套语法：`{name}`、`{id?}`、`{path:.+}`）。 */
    private const PLACEHOLDER = '/\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\??\s*(?::\s*(?:[^{}]|\{\d+(?:,\d*)?\})+?)?\s*\}/';

    /** 读 `_route_params` 的取参写法：`param('x')`，以及走 `ApiResponse::eid()` 的 `eid('x')`。 */
    private const TAKER = '/\$this\s*->\s*(?:param|eid)\s*\(\s*([\'"])([a-zA-Z_][a-zA-Z0-9_]*)\1/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    public function test_every_param_taker_matches_its_own_route_placeholder(): void
    {
        /** @var Router $router */
        $router = resolve(App::class)->getRouter();
        $bindings = $this->collectBindings($router);

        // 解析器自己先对账：本次出事的那条必须真的被认出来了（闭包 handler → 控制器方法）。
        // 少了这一条，下面的断言可以靠「一个 handler 都没解析出来」蒙过去。
        self::assertArrayHasKey(
            \app\admin\controllers\PluginController::class . '::fileRead',
            $bindings,
            '没解析出 /api/plugins/{name}/files/read 的 handler：路由形状变了，本门禁正在空转'
        );
        self::assertSame(
            ['name'],
            $bindings[\app\admin\controllers\PluginController::class . '::fileRead'],
            '这条路由的占位符集合变了（{name} 是唯一的路由参数，path 走查询串）'
        );

        $offenders = [];
        $analyzed = 0;

        foreach ($bindings as $key => $placeholders) {
            [$class, $method] = explode('::', $key, 2);
            $body = $this->methodBody($class, $method);
            if ($body === null) {
                continue;
            }
            $analyzed++;

            foreach ($this->findOffenders($body, $placeholders) as $k) {
                $offenders[] = $key . ' 取走 param(\'' . $k . '\')，但它绑定的 pattern 里没有 {' . $k . '} —— '
                    . '这个参数恒为默认值（改用 query()/post()，或把占位符写进路由）';
            }
        }

        self::assertGreaterThanOrEqual(
            self::MIN_METHODS_ANALYZED,
            $analyzed,
            '绑上了却只读到 ' . $analyzed . ' 个方法体（下限 ' . self::MIN_METHODS_ANALYZED . '）：'
            . 'handler 反射或类文件定位变了，本门禁正在空转'
        );
        self::assertSame([], $offenders, "param() 取的是路由占位符，不是查询串/请求体：\n  " . implode("\n  ", $offenders));
    }

    /**
     * 判据自身的对照：反例必须报出、正例必须放过。
     */
    public function test_the_rule_itself_catches_the_shape_it_describes(): void
    {
        $body = "<?php\n    \$path = (string) \$this->param('path');\n    \$id = \$this->eid('id');\n";
        self::assertSame(
            ['path', 'id'],
            $this->findOffenders($body, ['name']),
            '越界键没被报出来（正则或写法变了）：' . json_encode($this->findOffenders($body, ['name']))
        );
        self::assertSame([], $this->findOffenders($body, ['name', 'path', 'id']), 'pattern 里有的键不该报');
        self::assertSame(
            [],
            $this->findOffenders("<?php \$x = \$this->query('path'); \$y = Request::input('z');", []),
            'query()/input() 不受这条约束，别把它们也算成 offender'
        );
    }

    /* ==================== 工具 ==================== */

    /**
     * 路由表 → 「Class::method => 该方法绑定过的全部占位符（并集）」。
     *
     * @return array<string, list<string>>
     */
    private function collectBindings(Router $router): array
    {
        $bindings = [];

        foreach ($router->getRoutes() as $route) {
            if (!$route instanceof Route) {
                continue;
            }

            preg_match_all(self::PLACEHOLDER, (string) $route->getPattern(), $ph);

            foreach ($this->handlersOf($route) as $key) {
                /** @var list<string> $existing */
                $existing = $bindings[$key] ?? [];
                $bindings[$key] = array_values(array_unique([...$existing, ...$ph[1]]));
            }
        }

        return $bindings;
    }

    /**
     * 一条路由背后真正的「Class::method」。三种形状：
     *  - 插件路由：`$manager->addRoute(..., [SomeController::class, 'method'])`
     *  - 字符串：`'SomeController@method'`
     *  - 管理端 `app/admin/routes.php`：`fn($req) => resolve(PluginController::class)->logs()`
     *    —— 反射闭包自己那几行源码，把「解析出的类 + 紧接着调用的方法」取出来
     *
     * 认不出控制器的闭包（框架自带的探针等）直接不计入，不算缺陷。
     *
     * @return list<string>
     */
    private function handlersOf(Route $route): array
    {
        $handler = $route->getHandler();

        if (is_array($handler) && isset($handler[0], $handler[1])
            && is_string($handler[0]) && is_string($handler[1]) && class_exists($handler[0])) {
            return [$handler[0] . '::' . $handler[1]];
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);

            return class_exists($class) ? [$class . '::' . $method] : [];
        }

        return $handler instanceof \Closure ? $this->handlersInClosure($handler) : [];
    }

    /** @return list<string> */
    private function handlersInClosure(\Closure $closure): array
    {
        $ref = new \ReflectionFunction($closure);
        $file = $ref->getFileName();
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $lines = $file === false ? false : file($file);
        if ($start === false || $end === false || $lines === false) {
            return [];
        }

        $src = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        if (preg_match_all(
            '/(?:resolve|app|make)\s*\(\s*\\\\?([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z0-9_]*)*)::class\s*\)\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/',
            $src,
            $hits,
            PREG_SET_ORDER
        ) === 0) {
            return [];
        }

        $out = [];
        foreach ($hits as $hit) {
            $class = $this->resolveWrittenClass($file, $hit[1]);
            if ($class !== null) {
                $out[] = $class . '::' . $hit[2];
            }
        }

        return $out;
    }

    /** 闭包里写的是 `PluginController::class` 这种**短名**，得按所在文件的 use 语句还原成全名。 */
    private function resolveWrittenClass(string|false $file, string $written): ?string
    {
        if ($file === false) {
            return null;
        }
        if (class_exists($written)) {
            return $written;
        }

        $src = (string) file_get_contents($file);

        // `use app\admin\controllers\PluginController;`（也可能带分组花括号，本仓没有那种形状）
        if (preg_match(
            '/^use\s+((?:[A-Za-z_][A-Za-z0-9_]*\\\\)+' . preg_quote($written, '/') . ');/m',
            $src,
            $m
        ) === 1 && class_exists($m[1])) {
            return $m[1];
        }

        // 与路由文件同命名空间
        if (preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+);/m', $src, $m) === 1
            && class_exists($m[1] . '\\' . $written)) {
            return $m[1] . '\\' . $written;
        }

        return null;
    }

    /** @return string|null 方法体源码（从签名行开始），拿不到就跳过这条绑定 */
    private function methodBody(string $class, string $method): ?string
    {
        try {
            $ref = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return null;
        }

        $file = $ref->getFileName();
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $lines = $file === false ? false : file($file);
        if ($start === false || $end === null || $lines === false) {
            return null;
        }

        // 长度必须是 end-start+1：拿 end 当长度会把**后面那些方法**一起读进来，
        // 于是「A 方法没取 param」被算成「A 取了 B 的 param」，一次错出上百条假失败。
        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    /**
     * 判据本体：方法体 + 它绑定的占位符集合 → 越界取参的键。
     *
     * @param list<string> $placeholders
     * @return list<string>
     */
    private function findOffenders(string $body, array $placeholders): array
    {
        if (preg_match_all(self::TAKER, $body, $m) < 1) {
            return [];
        }

        $out = [];
        foreach (array_unique($m[2]) as $k) {
            if (!in_array($k, $placeholders, true)) {
                $out[] = $k;
            }
        }

        return $out;
    }
}
