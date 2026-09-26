<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\AuditController;
use app\admin\controllers\AuthController;
use app\admin\controllers\SyslogController;
use app\concerns\RetentionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Kode\Framework\Http\Controller;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 清理类接口的保留期入参闸门（AGENTS.md §4「破坏性入参禁止静默夹取」）。
 *
 * 三个入口（审计日志 / 系统日志 / 登录日志）原先各写一份
 * `min(3650, max(7, (int) query('days', 90)))`：漏传、传 0、传负数统统被夹成
 * 「删掉 7 天前的全部记录」，一次手误就是一场无告警的批量删除；而且三份代码
 * 只要哪天改漏一个，那个入口就继续静默夹取。现在判定收进 `RetentionGuard`，
 * 越界一律 400，并加静态门禁盯着旧写法有没有回来。
 *
 * 只测 400 分支（都在碰到 DELETE 之前返回），不碰开发库里的真实日志。
 */
final class RetentionGuardTest extends TestCase
{
    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    /**
     * 非法 days 样本：[入参原文（null 表示整个键不传）, 期望错误里必须出现的词]。
     *
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function badDays(): array
    {
        return [
            'missing' => [null, '必填'],
            'zero' => ['0', '7..3650'],
            'negative' => ['-1', '7..3650'],
            'too small' => ['6', '7..3650'],
            'too large' => ['4000', '7..3650'],
            'not a number' => ['abc', '整数'],
            'fractional' => ['7.5', '整数'],
        ];
    }

    #[DataProvider('badDays')]
    public function test_audit_clean_refuses_instead_of_clamping(?string $days, string $needle): void
    {
        $body = $this->call('DELETE', $this->uri('/api/audits/clean', $days), new AuditController(), 'clean');

        self::assertSame(400, (int) $body['code']);
        self::assertStringContainsString($needle, (string) $body['msg']);
        // 拒绝就不能已经删过：成功信封才会带 deleted 计数。
        self::assertArrayNotHasKey('deleted', (array) ($body['data'] ?? []));
    }

    #[DataProvider('badDays')]
    public function test_syslog_clean_refuses_instead_of_clamping(?string $days, string $needle): void
    {
        $body = $this->call('DELETE', $this->uri('/api/syslogs/clean', $days), new SyslogController(), 'clean');

        self::assertSame(400, (int) $body['code']);
        self::assertStringContainsString($needle, (string) $body['msg']);
        self::assertArrayNotHasKey('deleted', (array) ($body['data'] ?? []));
    }

    #[DataProvider('badDays')]
    public function test_login_log_clean_refuses_instead_of_clamping(?string $days, string $needle): void
    {
        $body = $this->call(
            'DELETE',
            $this->uri('/api/auth/login-logs/clean', $days),
            new AuthController(),
            'loginLogsClean',
            (object) ['uid' => 1, 'username' => 'retention-probe']
        );

        self::assertSame(400, (int) $body['code']);
        self::assertStringContainsString($needle, (string) $body['msg']);
        self::assertArrayNotHasKey('deleted', (array) ($body['data'] ?? []));
    }

    /** 未认证优先于参数校验：别让匿名请求靠 400 文案试探接口。 */
    public function test_login_log_clean_stays_401_before_it_complains_about_days(): void
    {
        $body = $this->call('DELETE', '/api/auth/login-logs/clean?days=0', new AuthController(), 'loginLogsClean');

        self::assertSame(401, (int) $body['code']);
    }

    /** 三个入口共用同一份判定：边界值口径必须一致。 */
    public function test_guard_accepts_the_boundaries_and_leaves_the_rest_to_the_caller(): void
    {
        $guard = $this->guard();

        foreach ([['7', 7], ['3650', 3650], [90, 90], ['014', 14]] as [$raw, $want]) {
            $out = $guard->check($raw);
            self::assertNull($out['error'], '入参 ' . var_export($raw, true) . ' 本应放行');
            self::assertSame($want, $out['days']);
        }
    }

    /** 旧写法（夹取 / 兜默认值）一旦回到 app/ 或 plugins/ 就直接失败。 */
    public function test_no_retention_clamp_or_default_survives_in_the_codebase(): void
    {
        $offenders = [];
        $roots = [dirname(__DIR__) . '/app', dirname(__DIR__) . '/plugins'];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $src = $this->codeOnly((string) file_get_contents($file->getPathname()));
                $relative = str_replace(dirname(__DIR__) . '/', '', $file->getPathname());
                // 写法一：直接夹进区间。
                if (preg_match('/min\(\s*3650\s*,\s*max\(\s*7\b/', $src) === 1
                    || preg_match('/max\(\s*7\s*,\s*min\(\s*3650\b/', $src) === 1) {
                    $offenders[] = $relative . '（夹取 days）';
                }
                // 写法二：给 days 兜个默认值 —— 只在「这个默认值和删除语句同处一个方法」时算违规。
                // 统计/趋势那几个读接口拿 days 当窗口，带默认值是对的，不能一起打死。
                foreach ($this->methodBodies($src) as $name => $body) {
                    if (str_contains($body, 'DELETE FROM')
                        && preg_match("/query\(\s*'days'\s*,/", $body) === 1) {
                        $offenders[] = $relative . '::' . $name . "（query('days', 默认值)）";
                    }
                }
            }
        }

        self::assertSame([], $offenders, '仍在替用户决定保留期: ' . implode(', ', $offenders));
    }

    /**
     * 判据盯的是**不变式**，不是旧写法的字面形状 —— 上面那条门禁照
     * `min(3650, max(7, query('days', 90)))` 这一份具体写法扫，于是它之后新增的清理入口
     * 整个躲过了它：
     *  - `WorkflowBridgeController::pruneExecutions` 写的是 `min(365, max(1, (int) Request::input('days', 30)))`
     *    —— 数字不同、取参器不同，而破坏性更大（服务端 `dry_run` 默认 false，days 漏传即
     *    「删掉昨天之前的全部执行记录 + webhook 日志 + 旧版本」，响应只回一句「已清理 N 条」）；
     *  - `ScheduleController::prune()` 同款夹取，且删除语句压根不在方法体里
     *    （`$dispatcher->pruneRunHistory($days)`），所以「同方法内有 DELETE FROM」也抓不住它。
     *
     * 现在的口径与常量取值无关，且认「委托出去的删除」：凡是按 days 删东西的入口，
     * days 必须由 `retentionDays()` 判，并且它的 `error` 必须被回成一个 400。
     *
     * 为什么这两个入口只有静态门禁、没有上面的那种 400 行为断言：它们**没有预览模式**，
     * 一旦闸门哪天被摘掉，那条行为用例跑起来就是先把开发库的历史删了再报红 ——
     * 用例本身会变成数据事故。判定与 400 回环已由上面三个入口逐条验过，这里钉的是「新入口别再漏」。
     */
    public function test_every_entry_that_deletes_by_days_uses_the_shared_guard(): void
    {
        $candidates = $this->daysRetentionEntries();

        // 防空转：两侧扫描面都得真有删除入口 —— 漏掉第四个入口的正是「清理接口都在 app 里」这个假设。
        $app = array_filter(array_keys($candidates), static fn(string $k): bool => str_starts_with($k, 'app/'));
        $plugins = array_filter(array_keys($candidates), static fn(string $k): bool => str_starts_with($k, 'plugins/'));
        self::assertNotEmpty($app, 'app/ 下一条按 days 删行的方法都没扫到，判据是空的');
        self::assertNotEmpty($plugins, 'plugins/ 下一条按 days 删行的方法都没扫到，判据是空的');
        // 委托型删除必须在候选面里，否则这条线又退化成「只看 DELETE FROM 的字面量」。
        self::assertArrayHasKey(
            'app/admin/controllers/ScheduleController.php::prune',
            $candidates,
            '删除语句在别的类里就不算清理入口，这正是 ScheduleController::prune 躲过旧门禁的原因'
        );

        $missing = $this->entriesMissingTheGuard($candidates);
        self::assertSame([], $missing, '按 days 删行却没走共用闸门: ' . implode(', ', array_column($missing, 0)));

        // 正对照一：被修掉的那份旧写法喂进同一个判据必须判红（否则「全绿」只说明扫描面没扫到东西）。
        self::assertSame(
            ['legacy::pruneExecutions'],
            array_column($this->entriesMissingTheGuard([
                'legacy::pruneExecutions' => "\$days = min(365, max(1, (int) Request::input('days', 30)));"
                    . " \$cutoff = date('Y-m-d H:i:s', time() - \$days * 86400);"
                    . " Db::delete(\"DELETE FROM kode_lowcode_workflow_executions WHERE created_at < ?\", [\$cutoff]);",
            ]), 0),
            '判据抓不住旧写法，它就抓不住下一个新入口'
        );

        // 正对照二：调了闸门却把 error 丢掉 —— 判定形同虚设，同样必须红。
        self::assertSame(
            ['legacy::pruneRuns'],
            array_column($this->entriesMissingTheGuard([
                'legacy::pruneRuns' => "\$days = \$this->retentionDays(\$this->query('days'))['days'];"
                    . " \$dispatcher->pruneRunHistory(\$days);",
            ]), 0),
            '调了 retentionDays 但没接住它的 error，等于没判'
        );

        // 反向对照：走闸门 + 回 400 → 放行。
        self::assertSame([], $this->entriesMissingTheGuard([
            'ok::clean' => "\$guard = \$this->retentionDays(\$this->query('days'));"
                . " if (\$guard['error'] !== null) { return \$this->fail(\$guard['error'], 400); }"
                . " Db::delete(\"DELETE FROM kode_audit_logs WHERE created_at < ?\", [\$cutoff]);",
        ]));
        // 反向对照：按主键删行的方法（同样含 DELETE FROM）不该成为候选 —— 过滤条件是 days，不是删除本身。
        self::assertArrayNotHasKey(
            'plugins/frontend/WorkflowBridgeController.php::budgetDelete',
            $candidates,
            '按主键删行被算进了保留期这条线'
        );
        // 反向对照：只读接口把 days 夹成窗口上限是**保护**，不能被这条线打死。
        self::assertArrayNotHasKey(
            'plugins/frontend/WorkflowBridgeController.php::webhookDeliveryStats',
            $candidates,
            '只按 days 读、一行都不删的入口被误判成清理入口'
        );
    }

    /**
     * 扫描 app/ 与 plugins/：返回「按 days 删东西」的那些方法。
     *
     * 两种形态都算：方法体里自己写 `DELETE FROM`，或把 days 交给一个 prune/clean/purge  callee。
     *
     * @return array<string, string> 形如 `相对路径::方法名` => 方法体
     */
    private function daysRetentionEntries(): array
    {
        $out = [];
        foreach ([dirname(__DIR__) . '/app', dirname(__DIR__) . '/plugins'] as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = str_replace(dirname(__DIR__) . '/', '', $file->getPathname());
                $src = $this->codeOnly((string) file_get_contents($file->getPathname()));
                foreach ($this->methodBodies($src) as $name => $body) {
                    if (!str_contains($body, "'days'")) {
                        continue;
                    }
                    $deletes = str_contains($body, 'DELETE FROM')
                        || preg_match('/->\s*(?:prune|clean|purge)\w*\s*\(/i', $body) === 1;
                    if ($deletes) {
                        $out[$relative . '::' . $name] = $body;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * 判定：哪些候选方法没把保留期交给共用闸门（或交给了却没接住它的 error）。
     *
     * @param array<string, string> $methods
     * @return list<array{0: string, 1: string}> [方法标识, 原因]
     */
    private function entriesMissingTheGuard(array $methods): array
    {
        $out = [];
        foreach ($methods as $where => $body) {
            if (!str_contains($body, 'retentionDays(')) {
                $out[] = [$where, 'days 由本方法自己决定'];
            } elseif (preg_match("/\\\$?\w*\[\s*'error'\s*\]/", $body) !== 1
                || str_contains($body, '400') === false) {
                $out[] = [$where, '调了闸门却没把 error 回成 400'];
            }
        }

        return $out;
    }

    /**
     * 按 `function` 关键字切分方法体（本仓方法不嵌套，够这个门禁用）。
     *
     * @return array<string, string> 方法名 => 方法体源码片段
     */
    private function methodBodies(string $src): array
    {
        $out = [];
        if (preg_match_all('/\bfunction\s+(\w+)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE) === false) {
            return $out;
        }
        $total = count($m[0]);
        foreach ($m[1] as $i => [$name, $offset]) {
            $start = $offset + strlen($m[0][$i][0]);
            $end = $i + 1 < $total ? $m[1][$i + 1][1] : strlen($src);
            $out[$name] = substr($src, $start, max(0, $end - $start));
        }

        return $out;
    }

    /**
     * 去掉注释后的纯代码文本。
     *
     * 闸门讲的是「代码里别再出现这种写法」，而 `RetentionGuard` 的注释正好在引用旧写法
     * 作为反面教材 —— 不剥注释，这段说明文字会把它自己判成违规。
     */
    private function codeOnly(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)
                && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** 暴露 protected 判定，用于纯逻辑断言（不触碰任何控制器状态）。 */
    private function guard(): object
    {
        return new class extends Controller {
            use RetentionGuard;

            /** @return array{days: int, error: string|null} */
            public function check(mixed $raw): array
            {
                return $this->retentionDays($raw);
            }
        };
    }

    private function uri(string $path, ?string $days): string
    {
        return $path . ($days === null ? '' : '?days=' . rawurlencode($days));
    }

    /**
     * 直接调控制器方法（绕开中间件），拿归一化后的信封。
     *
     * 查询串要显式喂给 `withQueryParams()`：`Kode\Http\Psr7\Message\ServerRequest`
     * 的构造只把 queryParams 置空，真实链路里由 `App::run()` / ServerRequestFactory
     * 解析后注入 —— 测试自己注入请求就得补这一步，否则 `query('days')` 恒为 null，
     * 断言会退化成「只测了漏传分支」。
     *
     * @return array{code: int, msg: string, data: array<string, mixed>}
     */
    private function call(string $method, string $uri, object $controller, string $action, ?object $auth = null): array
    {
        $request = (new ServerRequest($method, $uri))
            ->withQueryParams($this->parseQuery($uri));
        if ($auth !== null) {
            $request = $request->withAttribute('auth', $auth);
        }
        HttpRequest::setRequest($request);

        $response = $controller->{$action}();
        $decoded = (array) json_decode((string) $response->getBody(), true);

        return [
            'code' => (int) ($decoded['code'] ?? $response->getStatusCode()),
            'msg' => (string) ($decoded['msg'] ?? ''),
            'data' => (array) ($decoded['data'] ?? []),
        ];
    }

    /** @return array<string, mixed> */
    private function parseQuery(string $uri): array
    {
        $query = (string) parse_url($uri, PHP_URL_QUERY);
        parse_str($query, $parsed);

        return $parsed;
    }
}
