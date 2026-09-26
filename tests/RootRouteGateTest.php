<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Http\RouteRegistry;
use Kode\Http\App;
use Kode\Framework\Testing\TestCase;

/**
 * 「顶层」路由与令牌签发面收口。
 *
 * 本仓的门禁都按**来源标签**划：`CoreWriteGateTest`/`CoreReadGateTest` 扫 `app:admin`、
 * `ApiWriteGateTest` 读 `app:api`、`PluginAnonymousRouteGateTest` 扫 `plugin:*`。
 * 于是**不属于这三家的那一圈**长期谁都不管 —— `app/routes.php` 打的是 `app`，
 * 而 `app/routes/*.php` 逐个文件 glob 加载、各打一个 `routes:<文件名>`（历史上只按 `app`
 * 精确匹配的分类器看不见后者：`app/routes/bench.php` 的三条匿名压测口就是这么一直挂着的）。
 * 骨架就在这个空档里挂着一条 `POST /auth/login`：它不比凭证，直接 `jwt()->issue(['uid' => 1, ...])`，
 * 而 `AccountScope::isAdmin()` 认「uid=1 即超管」（`RequirePerm` 的旁路）。
 * 一枚空 POST 换到的签名合法令牌，因此能以超管口径读全站名单/配置/聚合看板（实测 200、`scope:"all"`）。
 *
 * 所以本类固化两条：
 *  1. **顶层路由白名单**：只允许 `GET /health`（示例路由、`app/controllers` 的属性扫描、
 *     `/bench/*` 压测口都已收口；后者改由 `BENCH_ROUTES` 显式开启）；
 *  2. **签发点必有凭证判定**：任何以含 `uid` 的载荷铸访问令牌的文件，必须同时含该入口的
 *     身份判据符号（验密 / 验签 / 防爆破），并逐条写清「这条凭什么给这个人发」。
 *
 * 第 2 条为什么要独立于第 1 条存在：签发点可以出现在任何标签下（admin、插件、未来的子应用），
 * 路由白名单只管得到「挂在哪条路由上」，管不到「谁在铸令牌」。
 */
final class RootRouteGateTest extends TestCase
{
    /**
     * 允许存在的顶层路由：pattern => `来源标签 方法集`（'|' 连接方法；router 给 GET 自动补了 HEAD）。
     *
     * 来源标签一并锁死：只写 pattern + 方法的话，把同一条路由从 `app` 挪到别的未管辖标签下
     * 也能过，而「挂在哪、谁登记的」正是这条门禁要问的两件事。
     *
     * 加条目前先答两句：这条为什么不属于 admin/api/插件而必须挂在根上？它要不要鉴权？
     * （顶层路由排在 FrontendPlugin 的 SPA 兜底 `GET /{path:.+}` 之前，注册即公开。）
     */
    private const ROOT_ROUTES = [
        '/health' => 'app GET|HEAD',
    ];

    /**
     * 已有专属门禁的来源标签前缀（本类不许重复管，否则同一条路由两处白名单、改一处漏一处）。
     *
     * `app:admin` → CoreWriteGateTest/CoreReadGateTest，`app:api` → ApiWriteGateTest，
     * `plugin:*` → PluginAnonymousRouteGateTest。
     */
    private const GOVERNED_SOURCES = ['app:admin', 'app:api', 'plugin:'];

    /**
     * 已审的访问令牌签发点：文件（相对项目根）=> 该文件必须出现的身份判据符号。
     *
     * 符号必须**真的出现在源码里**（口径同 `PluginAnonymousRouteGateTest`：
     * 注释里写一句「已验密」不算过），因为这类缺陷的形态就是「重构时把守卫抽丢了」。
     */
    private const MINT_SITES = [
        // 真实登录：AttemptGuard 计数 + password_verify + status 判定，缺一都发不出令牌
        'app/admin/controllers/AuthController.php' => ['password_verify', 'tooManyAttempts'],
        // 签发原语本身：只有 authenticate()（验签 + 解密 + 外层 uid 与密文 uid 比对）通过的载荷才走到重签
        'app/auth/EncryptedJwtGuard.php' => ['authenticate'],
    ];

    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    // ------------------------------------------------------------------ 顶层路由面

    /**
     * 来源标签是否属于「无人管辖的顶层」。
     *
     * 判定是**排除式**的：只剔掉未打标（框架自带的 /ping、/health/live、/health/ready ——
     * 不由本仓登记，删了也删不掉）和另外三家有专属门禁的标签，其余一律算顶层。
     * 所以「新增一种标签写法」不会让一条根上路由悄悄掉出统计，反而会撞白名单断言。
     */
    private function isUngovernedTopLevel(string $source): bool
    {
        if ($source === '') {
            return false;
        }
        foreach (self::GOVERNED_SOURCES as $prefix) {
            if (str_starts_with($source, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string> pattern => `来源标签 方法集`
     */
    private function topLevelRoutes(): array
    {
        /** @var App $app */
        $app = resolve(App::class);
        /** @var RouteRegistry $registry */
        $registry = resolve(RouteRegistry::class);

        $out = [];
        foreach ($app->getRouter()->getRoutes() as $route) {
            $source = $registry->sourceOf($route) ?? '';
            if ($this->isUngovernedTopLevel($source)) {
                $out[(string) $route->getPattern()] = $source . ' ' . implode('|', $route->getMethods());
            }
        }
        ksort($out);

        return $out;
    }

    public function test_the_classifier_enumerates_and_discriminates(): void
    {
        // 下面的白名单断言是「除了这几条别的不许」——若来源标签口径变了导致枚举到 0 条，
        // 它会原地全绿。所以先证明分类器抓得到东西、抓到的就是 /health 本身。
        $actual = $this->topLevelRoutes();
        self::assertNotEmpty($actual, '顶层分类器枚举到 0 条路由：来源标签口径可能变了，本类会空转');
        self::assertArrayHasKey('/health', $actual, '/health 应仍被算作顶层路由，否则白名单已失去意义');

        // 反向对照：被排除的标签必须真的在路由表里有货，且真的没被算进来。
        // 只测「排除项不在结果里」是不够的 —— 一旦 app:admin 整体不再打标，那条断言就恒成立。
        /** @var App $app */
        $app = resolve(App::class);
        /** @var RouteRegistry $registry */
        $registry = resolve(RouteRegistry::class);

        $governed = [];
        foreach ($app->getRouter()->getRoutes() as $route) {
            $source = $registry->sourceOf($route) ?? '';
            foreach (self::GOVERNED_SOURCES as $prefix) {
                if (str_starts_with($source, $prefix)) {
                    $governed[$source][] = (string) $route->getPattern();
                }
            }
        }

        self::assertArrayHasKey('app:admin', $governed, 'app:admin 标签下一条路由都没有：排除口径在挡空气');
        self::assertArrayHasKey('plugin:frontend', $governed, 'plugin:* 标签下一条路由都没有：排除口径在挡空气');
        foreach ($governed as $source => $patterns) {
            foreach ($patterns as $pattern) {
                self::assertArrayNotHasKey(
                    $pattern,
                    $actual,
                    '标签 ' . $source . ' 的路由被误算进顶层（分类器的排除式判据失效）：' . $pattern
                );
            }
        }
    }

    public function test_top_level_routes_are_exactly_the_allowlist(): void
    {
        $expected = self::ROOT_ROUTES;
        $actual = $this->topLevelRoutes();
        ksort($expected);

        self::assertSame(
            $expected,
            $actual,
            '顶层路由面与白名单不符（新增、删除、来源标签或方法集变化都会到这里）。顶层 = 无人管辖的那一圈：'
            . '注册即匿名可达，且排在 SPA 兜底之前。请改挂 app/admin、app/api 或插件；'
            . '确需在根上时，把「为什么必须在根上」与它的门禁写进 ROOT_ROUTES'
        );
    }

    public function test_bench_routes_stay_off_until_the_switch_is_on(): void
    {
        // 白名单挡的是「默认状态」，这条挡的是「开关还管不管用」：
        // app/routes/bench.php 若哪天不再判 env，三条匿名口会重新出现；反向若判据写错，
        // 压测口会永远开不出来（那时这条也该红，而不是等有人要压测才发现）。
        self::assertArrayNotHasKey('/bench/static', $this->topLevelRoutes(),
            'BENCH_ROUTES 默认 false，但压测路由挂上了 —— 开关不再兜底');

        putenv('BENCH_ROUTES=true');
        try {
            $this->bootApp(dirname(__DIR__));
            $actual = $this->topLevelRoutes();
            foreach (['/bench/db', '/bench/static', '/bench/json'] as $pattern) {
                self::assertArrayHasKey($pattern, $actual, '开关已开，' . $pattern . ' 却没挂上：'
                    . implode(', ', array_keys($actual)));
                self::assertSame('routes:bench GET|HEAD', $actual[$pattern]);
            }
        } finally {
            putenv('BENCH_ROUTES');
            $this->bootApp(dirname(__DIR__));
        }
    }

    public function test_the_sample_token_mint_route_never_comes_back(): void
    {
        // 与白名单互为补充：白名单讲「现在允许什么」，这条讲「那一条具体的口不得复活」。
        // 真实登录是 `/api/auth/login`（带 /api 前缀），所以先按前缀剔掉后台与 C 端子应用。
        /** @var App $app */
        $app = resolve(App::class);

        $root = [];
        foreach ($app->getRouter()->getRoutes() as $route) {
            $pattern = (string) $route->getPattern();
            if (!str_starts_with($pattern, '/api') && !str_starts_with($pattern, '/c-api')) {
                $root[] = $pattern;
            }
        }

        self::assertNotContains('/auth/login', $root, '免密签发示例不得复活');
    }

    public function test_the_top_level_attribute_scan_dir_stays_closed(): void
    {
        // 顶层属性路由的入口就是这条配置。它一旦被改回 'app' => 'app/controllers'，
        // 那个目录里任何带 #[Get] 的示例类都会自动变成公开端点（历史上就是 `/products`）。
        // 上面的白名单挡结果，这条挡「配置先漂、路由后漂」的中间态。
        /** @var array<string, string> $dirs */
        $dirs = (array) config('routes.attributes.controllers', []);

        self::assertSame([], $dirs, 'config/routes.php 的 attributes.controllers 必须留空，实际：'
            . json_encode($dirs, JSON_UNESCAPED_SLASHES));
    }

    // ------------------------------------------------------------------ 令牌签发点

    /**
     * 从「只剩代码」的源码里找出访问令牌签发点。
     *
     * 判定式（两条并集）：
     *  - `jwt()->issue(` / `jwt()->refresh(` —— 门面拿到的就是访问令牌，载荷长什么样都不用猜；
     *  - `->issue([...])` / `->refresh([...])` 且参数表里出现 `'uid'` —— 直接对守卫调用并铸带身份的令牌。
     *
     * 第二条特意要求载荷含 `uid`：`LicenseService::issue()` 铸的是授权码而不是访问令牌，
     * 把它算进来会让门禁长期靠豁免条目运转，那是比漏检更糟的漂移。
     *
     * @return list<string> 命中的调用文本
     */
    private function mintSitesIn(string $code, string $prefix = ''): array
    {
        $found = [];

        if (preg_match_all("/->(?:issue|refresh)\s*\(\s*\[[^\]]{0,300}?'uid'/s", $code, $hits)) {
            foreach ($hits[0] as $hit) {
                $found[] = $prefix . preg_replace('/\s+/', ' ', $hit);
            }
        }

        if (preg_match_all('/\bjwt\s*\(\s*\)\s*->\s*(?:issue|refresh)\s*\(/', $code, $facade)) {
            foreach ($facade[0] as $hit) {
                $found[] = $prefix . $hit;
            }
        }

        return $found;
    }

    /** 剥掉注释，只留可执行代码（注释换成换行，行号仍大致可读）。 */
    private function codeOnly(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? "\n" : $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /**
     * @return list<string> 违规描述（空数组即通过）
     */
    private function auditMintSites(): array
    {
        $root = dirname(__DIR__);
        $offenders = [];
        $seen = [];

        foreach (['app', 'plugins'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($entry->getPathname(), strlen($root) + 1);
                $code = $this->codeOnly((string) file_get_contents($entry->getPathname()));
                $hits = $this->mintSitesIn($code, $relative . ': ');
                if ($hits === []) {
                    continue;
                }

                $seen[] = $relative;
                $allowed = self::MINT_SITES[$relative] ?? null;
                if ($allowed === null) {
                    $offenders[] = implode("\n    ", $hits)
                        . "\n    → 未登记的签发点：先答「这条凭什么给这个人发」，验完凭证再登记进 MINT_SITES";
                    continue;
                }

                foreach ($allowed as $symbol) {
                    if (!str_contains($code, $symbol)) {
                        $offenders[] = $relative . ' 已登记的判据符号 `' . $symbol . '` 不在源码里（签发点：'
                            . implode(' / ', $hits) . '）';
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(2, count($seen), '签发点扫描只看到 ' . count($seen)
            . ' 个文件：判定式或扫描根目录失效，白名单断言会空转');

        // 反向：白名单条目必须仍被判定为签发点，否则它永久占着一个「已审」名额。
        foreach (array_values(array_diff(array_keys(self::MINT_SITES), $seen)) as $stale) {
            $offenders[] = 'MINT_SITES 存在已失效条目（该文件里已无签发点），请删除：' . $stale;
        }

        return $offenders;
    }

    public function test_every_token_mint_site_verifies_credentials_first(): void
    {
        $offenders = $this->auditMintSites();

        self::assertSame([], $offenders, "发现未验凭证即铸访问令牌的位置：\n" . implode("\n", $offenders));
    }

    public function test_the_mint_scanner_sees_a_planted_violation(): void
    {
        // 对照组：把当初那条示例「顺手加回来」时本类必须变红。判定式要当场验，
        // 不能只信它对真实文件返回了空 —— 全绿也可能是正则压根没匹配上。
        $planted = "<?php\nnamespace x;\nclass A{\n public function login(){ \$u='a'; "
            . "return jwt()->issue(['uid' => 1, 'username' => \$u, 'role' => 'user']); }\n}\n";
        self::assertNotEmpty($this->mintSitesIn($this->codeOnly($planted)), '判定式匹配不到免密签发示例');

        // 同一把尺子必须放过「铸授权码」的 LicenseService，否则门禁会退化成常驻豁免清单。
        $license = "<?php\nnamespace x;\nclass B{\n public function run(\$data){ "
            . "return (new LicenseService())->issue(\$data); }\n}\n";
        self::assertSame([], $this->mintSitesIn($this->codeOnly($license)), '判定式把非令牌签发算成了违规');

        // 注释里的示例文本不得自证违规（本仓的注释与文档大量在讲这件事，包括本文件开头）。
        $docblock = "<?php\n/*\n * 示例：jwt()->issue(['uid' => 1]) 是漏洞。\n */\nnamespace x;\nclass C{}\n";
        self::assertSame([], $this->mintSitesIn($this->codeOnly($docblock)), '注释未被剥除');
    }
}
