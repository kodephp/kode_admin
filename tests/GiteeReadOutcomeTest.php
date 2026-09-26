<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Testing\TestCase;
use Kode\Plugins\frontend\GiteeReleaseController;

/**
 * gitee 读侧的「读不到」与「一个 release 都没有」必须是两件事（AGENTS §6/§7）。
 *
 * 现场是 `fetchGiteeReleases()` 只看 `curl_exec() === false`，然后
 * `is_array($data) ? $data : []` —— 它既不看 HTTP 状态，也没防住另一件事：
 * gitee 的错误回包是一个 **JSON 对象**，`json_decode(..., true)` 之后照样是数组。
 * 实测（2026-09-26，直接问 gitee API）：
 *   令牌无效 → HTTP 401 + {"message":"401 Unauthorized: Access token does not exist"}
 *   仓库不存在 → HTTP 404 + {"message":"Not Found Project"}
 * 于是 `checkUpdates()` 拿 `$releases[0] ?? null` 取不到（键是 message），
 * 回 `has_update: false`，页面上是「已是最新版本」—— 令牌已经死了，这句话恰好在骗人；
 * 而 `releasesList()` 把 `{"message": …}` 当成 release 数组交出去。
 * `fetchGiteeReleaseByTag()` 同形：任何失败都回 null，安装入口因此报
 * 「未找到 tag=1.2.3 的 release」，把排障方向指到 tag 上而不是令牌上。
 *
 * 本文件不连网：判定拆成纯函数，输入就是实测到的那几种真实回包。
 */
final class GiteeReadOutcomeTest extends TestCase
{
    private const LIST_SUBJECT = '仓库 owner/repo 的 release 列表';

    /* ==================== 列表形状 ==================== */

    public function test_empty_list_is_a_real_read(): void
    {
        $res = GiteeReleaseController::giteeListVerdict(200, '[]', self::LIST_SUBJECT);

        self::assertTrue($res['ok'], '真的一个 release 都没有，不该被说成读不到');
        self::assertSame([], $res['data']);
        self::assertSame('', $res['reason']);
    }

    public function test_real_list_passes_through_untouched(): void
    {
        $body = '[{"tag_name":"v1.2.3","assets":[{"name":"app.zip"}]},{"tag_name":"v1.2.2","assets":[]}]';
        $res = GiteeReleaseController::giteeListVerdict(200, $body, self::LIST_SUBJECT);

        self::assertTrue($res['ok']);
        self::assertCount(2, $res['data']);
        self::assertSame('v1.2.3', $res['data'][0]['tag_name']);
    }

    public function test_invalid_token_is_not_read_as_no_releases(): void
    {
        // 实测回包：HTTP 401 + {"message":"401 Unauthorized: Access token does not exist"}
        $body = '{"message":"401 Unauthorized: Access token does not exist"}';
        $res = GiteeReleaseController::giteeListVerdict(401, $body, self::LIST_SUBJECT);

        self::assertFalse($res['ok']);
        self::assertSame([], $res['data']);
        self::assertStringContainsString('令牌', $res['reason']);
        self::assertStringContainsString('401', $res['reason']);
        // 原因是念给人听的：得点出读的是哪一样东西
        self::assertStringContainsString(self::LIST_SUBJECT, $res['reason']);
    }

    public function test_missing_repo_is_named_as_such(): void
    {
        // 实测回包：HTTP 404 + {"message":"Not Found Project"}
        $res = GiteeReleaseController::giteeListVerdict(404, '{"message":"Not Found Project"}', self::LIST_SUBJECT);

        self::assertFalse($res['ok']);
        self::assertStringContainsString('404', $res['reason']);
        self::assertStringContainsString('不存在', $res['reason']);
    }

    public function test_forbidden_is_not_folded_into_the_token_message(): void
    {
        // 403 在 gitee 上是权限/限流，与「令牌不存在」不是一回事，文案不许混
        $res = GiteeReleaseController::giteeListVerdict(403, '{"message":"Forbidden"}', self::LIST_SUBJECT);

        self::assertFalse($res['ok']);
        self::assertStringContainsString('403', $res['reason']);
        self::assertStringNotContainsString('无效', $res['reason']);
    }

    public function test_server_side_errors_keep_the_status_code(): void
    {
        foreach ([429, 500, 502, 503] as $status) {
            $res = GiteeReleaseController::giteeListVerdict($status, '', self::LIST_SUBJECT);
            self::assertFalse($res['ok'], 'HTTP ' . $status . ' 不算读到');
            self::assertStringContainsString((string) $status, $res['reason']);
        }
    }

    public function test_transport_failure_is_not_a_read(): void
    {
        // curl 报错/超时：状态码 0、正文空
        $res = GiteeReleaseController::giteeListVerdict(0, '', self::LIST_SUBJECT);

        self::assertFalse($res['ok']);
        self::assertStringContainsString('连接', $res['reason']);
    }

    public function test_an_error_object_is_never_handed_out_as_the_list(): void
    {
        // 这一条钉的是旧写法最坏的那一支：HTTP 200 但回的是对象（或网关的 HTML 错误页）。
        // 旧代码 `is_array($data) ? $data : []` 会把 {"message":…} 原样当列表交出去，
        // 前端拿到的就不是数组了。
        foreach ([
            '{"message":"ok"}',
            '<html><body>502 Bad Gateway</body></html>',
            '',
            'null',
            '"a string"',
        ] as $body) {
            $res = GiteeReleaseController::giteeListVerdict(200, $body, self::LIST_SUBJECT);
            self::assertFalse($res['ok'], '不该把非列表回包当数据：' . $body);
            self::assertSame([], $res['data']);
        }
    }

    /* ==================== 单个 release 形状 ==================== */

    public function test_release_object_passes_through(): void
    {
        $body = '{"tag_name":"v1.2.3","assets":[{"name":"app.zip","browser_download_url":"https://gitee.com/a"}]}';
        $res = GiteeReleaseController::giteeReleaseVerdict(200, $body, 'tag=v1.2.3 的 release');

        self::assertTrue($res['ok']);
        self::assertSame('v1.2.3', $res['data']['tag_name']);
        self::assertCount(1, $res['data']['assets']);
    }

    public function test_missing_tag_names_the_tag_and_admits_the_other_reading(): void
    {
        $res = GiteeReleaseController::giteeReleaseVerdict(404, '{"message":"Not Found"}', 'tag=v9.9.9 的 release');

        self::assertFalse($res['ok']);
        self::assertStringContainsString('tag=v9.9.9 的 release', $res['reason']);
        self::assertStringContainsString('404', $res['reason']);
        // 404 在 gitee 上同时是「仓库不存在/对这枚令牌不可见」，只报「tag 不存在」是半个假因
        self::assertStringContainsString('不可见', $res['reason']);
    }

    public function test_dead_token_does_not_read_as_a_missing_tag(): void
    {
        $res = GiteeReleaseController::giteeReleaseVerdict(401, '{"message":"401 Unauthorized"}', 'tag=v1.2.3 的 release');

        self::assertFalse($res['ok']);
        self::assertStringContainsString('令牌', $res['reason']);
        // 这一条挡住的是旧现场：安装入口把任何失败都念成「未找到 tag=…」
        self::assertStringNotContainsString('未找到 tag', $res['reason']);
    }

    public function test_a_list_is_not_read_as_a_release_object(): void
    {
        foreach (['[]', '[{"tag_name":"v1.2.3"}]'] as $body) {
            $res = GiteeReleaseController::giteeReleaseVerdict(200, $body, 'tag=v1.2.3 的 release');
            self::assertFalse($res['ok'], '不该把这份回包当单个 release：' . $body);
            self::assertSame([], $res['data']);
        }
    }

    /* ==================== 静态口径：判定不许被绕开 ==================== */

    public function test_both_fetch_helpers_route_through_the_verdict(): void
    {
        $src = $this->controllerSource();
        $methods = $this->bodiedMethods($src, ['fetchGiteeReleases', 'fetchGiteeReleaseByTag', 'giteeGet']);

        self::assertCount(3, $methods, '两个抓取方法 + 一个传输口都得找得到（改名要同步这条门禁）');
        foreach (['fetchGiteeReleases', 'fetchGiteeReleaseByTag'] as $name) {
            $body = $methods[$name];
            self::assertStringContainsString('Verdict(', $body, $name . ' 必须把判定交给 verdict，不许自己下结论');
            self::assertStringContainsString('giteeGet(', $body, $name . ' 必须走同一个传输口');
            self::assertStringNotContainsString('is_array($data) ?', $body, $name . ' 不得复活「解码即当数据」的旧写法');
        }
        // 状态码必须由传输口读出来：不读状态就分不开「401 令牌死了」和「一个 release 都没有」
        self::assertStringContainsString('CURLINFO_HTTP_CODE', $methods['giteeGet']);
        self::assertStringContainsString('curl_errno', $methods['giteeGet'], '传输失败必须被区分出来，而不是当成空响应');
    }

    public function test_the_read_endpoints_declare_availability(): void
    {
        $src = $this->controllerSource();
        $methods = $this->bodiedMethods($src, ['releasesList', 'checkUpdates']);
        self::assertCount(2, $methods);
        foreach ($methods as $name => $body) {
            self::assertStringContainsString("'available'", $body, $name . ' 的出参必须带 available');
            self::assertStringContainsString("'reason'", $body, $name . ' 的出参必须带 reason');
        }
    }

    /* ==================== 工具 ==================== */

    private function controllerSource(): string
    {
        $path = dirname(__DIR__) . '/plugins/frontend/GiteeReleaseController.php';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * 按方法名切出**非注释**的方法体（口径见 AGENTS §6：先剥注释再扫，
     * 否则文档里那句「旧写法长什么样」会被当成实现命中）。
     *
     * @param list<string> $names
     * @return array<string, string>
     */
    private function bodiedMethods(string $src, array $names): array
    {
        $clean = '';
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok)) {
                $clean .= in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true) ? "\n" : $tok[1];
            } else {
                $clean .= $tok;
            }
        }

        $out = [];
        foreach ($names as $name) {
            $at = strpos($clean, 'function ' . $name . '(');
            if ($at === false) {
                continue;
            }
            $brace = strpos($clean, '{', $at);
            self::assertNotFalse($brace, $name . ' 找不到方法体');
            $depth = 0;
            $i = (int) $brace;
            $len = strlen($clean);
            for (; $i < $len; $i++) {
                if ($clean[$i] === '{') {
                    $depth++;
                } elseif ($clean[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $out[$name] = substr($clean, (int) $brace, $i - (int) $brace + 1);
        }

        return $out;
    }
}
