<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Testing\TestCase;
use Kode\Http\App;
use Kode\Http\Routing\Route;

/**
 * 静态门禁：文档里写出来的每一个端点，必须真的挂在路由表上。
 *
 * 对账范围见 self::DOC_FILES（`docs/API.md` 是前端同学的唯一契约来源，AGENTS §7 明说
 * 「前端只看这节」；`README-ADMIN.md` 的端点表是它的精简副本，同样有人照抄）。
 * 文档比代码老得快——路由改名/删除后，只有文档仍然信心十足地指向一个 404。
 * 这类漂移不报错：`curl` 打到不存在的端点，前端只会当成后端没做。
 * 实测抽掉过两型：钱包篇写过 `/api/wallet/recharge|consume|freeze` 等 7 个
 * 从来不存在的路径；`GET/POST | A、B` 这种「方法集 × 路径集」的表格写法
 * 更是把配对关系抹平了（`POST /api/configs/{key}` 看着合法，真实写入却是 `POST /api/configs`）。
 *
 * 只做文档 → 路由这一个方向：路由有 600+ 条，本文档是精选册而非全量清单，
 * 反向断言只会逼着人往表里灌水。
 */
final class ApiDocRouteGateTest extends TestCase
{
    /** 参与对账的文档（都用同一套「方法 | 路径」表格写法）。 */
    private const DOC_FILES = ['docs/API.md', 'README-ADMIN.md'];

    /**
     * 已知例外：文档写了、但**按环境开关条件注册**的端点。
     *
     * 每条都必须写清「为什么合法」，否则例外清单就是漂移的藏身处。
     *
     * @var array<string, string>
     */
    private const ENV_GATED = [
        // app/api/routes.php：仅当 C_API_REGISTER=true 才注册，文档同段已注明默认关闭。
        'POST /c-api/auth/register' => '条件注册：`C_API_REGISTER=true` 才挂上（默认关闭）',
    ];

    /**
     * 解析出的端点行数下限：解析规则一改就跑空，本门禁会静默变成永真断言。
     *
     * 实测两份文档合计 159 条；下限留了约 30 条余量，够正常增删表格行，
     * 但挡得住「正则不再匹配任何行」这种空转。
     */
    private const MIN_PARSED_ROWS = 130;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    public function test_documented_endpoints_are_all_registered(): void
    {
        $routes = $this->registeredRoutes();
        self::assertNotEmpty($routes, '路由表解析失败，下面的断言会形同虚设');

        $documented = $this->documentedEndpoints();
        self::assertGreaterThanOrEqual(
            self::MIN_PARSED_ROWS,
            count($documented),
            '只解析出 ' . count($documented) . ' 条端点（下限 ' . self::MIN_PARSED_ROWS . '）：'
            . '表格解析规则和 ' . implode(' / ', self::DOC_FILES) . ' 的写法已经对不上，本门禁正在空转'
        );

        $ghosts = [];
        foreach (array_keys($documented) as $key) {
            if (isset($routes[$key])) {
                continue;
            }
            if (isset(self::ENV_GATED[$key])) {
                continue; // 条件注册，例外清单里点名过理由
            }
            $ghosts[] = $key . '　← ' . $documented[$key];
        }

        self::assertSame(
            [],
            $ghosts,
            "文档承诺了这些端点，路由表里却没有（前端照抄即 404）：\n  "
            . implode("\n  ", $ghosts)
            . "\n要么把端点补回来，要么把文档那几行改掉——留着一行假契约最坏。"
        );
    }

    /** 例外清单不能变成化石：条件端点若改成无条件注册，就该把这行删掉。 */
    public function test_env_gated_allowlist_is_still_conditional(): void
    {
        $routes = $this->registeredRoutes();
        foreach (self::ENV_GATED as $key => $why) {
            self::assertArrayNotHasKey(
                $key,
                $routes,
                "「{$key}」如今已在默认配置下注册（{$why}），请把这条例外从 ENV_GATED 删掉，"
                . '否则以后真有同名漂移会被它挡掉'
            );
        }
    }

    /**
     * 路由表 → `METHOD /a/b/{c}` 键集合（路径参数去掉正则约束、去掉结尾 `/`）。
     *
     * @return array<string, true>
     */
    private function registeredRoutes(): array
    {
        /** @var App $app */
        $app = resolve(App::class);

        $out = [];
        foreach ($app->getRouter()->getRoutes() as $route) {
            if (!$route instanceof Route) {
                continue;
            }
            $path = self::normalizePath($route->getPattern());
            foreach ($route->getMethods() as $method) {
                $out[strtoupper((string) $method) . ' ' . $path] = true;
            }
        }

        return $out;
    }

    /**
     * 从文档的端点表里抽出 `[METHOD, 路径]` 组合（键 = `METHOD /path`，值 = 出处文件）。
     *
     * 认这三种写法：多方法格（`GET/POST`）、多路径格（`A、B`）、
     * 路径末段的花括号枚举（`{install,pause,resume,upgrade}` = 四条独立路由）。
     * 查询串（`?days=`）与行尾斜杠都不是路径的一部分，先剥掉。
     *
     * @return array<string, string>
     */
    private function documentedEndpoints(): array
    {
        $out = [];
        foreach (self::DOC_FILES as $rel) {
            $src = file_get_contents(dirname(__DIR__) . '/' . $rel);
            self::assertIsString($src, $rel . ' 读不到，本门禁无从对账');

            foreach (explode("\n", (string) $src) as $line) {
                if (!preg_match('/^\|\s*((?:GET|POST|PUT|PATCH|DELETE)(?:\/[A-Z]+)*)\s*\|\s*([^|]+?)\s*\|/u', $line, $m)) {
                    continue;
                }
                $methods = explode('/', strtoupper($m[1]));
                $cell = str_replace('`', '', trim($m[2]));
                foreach (preg_split('/、/u', $cell) ?: [] as $part) {
                    $path = self::normalizePath((string) preg_replace('/\?.*$/u', '', trim($part)));
                    // 只核对 /api 与 /c-api 两族：/ping、/health 这类探活路由由框架注册，形状不同
                    if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/c-api/')) {
                        continue;
                    }
                    foreach (self::expandAlternation($path) as $one) {
                        foreach ($methods as $method) {
                            $key = $method . ' ' . $one;
                            // 两份文档写同一个端点很常见，出处合并列出，别让后读的盖掉前一份。
                            $out[$key] = isset($out[$key]) ? $out[$key] . ' + ' . $rel : $rel;
                        }
                    }
                }
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * `{install,pause,resume,upgrade}` → `install`/`pause`/`resume`/`upgrade` 四条路径。
     *
     * @return list<string>
     */
    private static function expandAlternation(string $path): array
    {
        if (!preg_match('/\{([a-z][a-z0-9-]*(?:,[a-z][a-z0-9-]*)+)\}/u', $path, $m)) {
            return [$path];
        }
        $out = [];
        foreach (explode(',', $m[1]) as $option) {
            foreach (self::expandAlternation(str_replace($m[0], $option, $path)) as $one) {
                $out[] = $one;
            }
        }

        return $out;
    }

    /** `{id:[A-Za-z0-9]+}` → `{id}`；顺带去掉结尾斜杠（路由与文档都不该带）。 */
    private static function normalizePath(string $path): string
    {
        $out = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^{}]*)?\}/u', '{$1}', trim($path)) ?? $path;
        $out = rtrim($out, '/');

        return $out === '' ? '/' : $out;
    }
}
