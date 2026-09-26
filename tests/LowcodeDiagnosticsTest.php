<?php

declare(strict_types=1);

namespace app\tests;

use app\support\AuditTrail;
use app\support\HotCache;
use app\support\Shard;
use app\support\SysConfig;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Plugins\FrontendPlugin;

/**
 * 低代码护栏回传端点（`POST /api/plugins/frontend/diagnostics`）真的把账落下去了。
 *
 * 收口前的形状是「永远回 code:0 ok，永远一行都不写」：handler 写的是
 * `Db::insert('kode_audit_logs', [...])`，而 `Db::insert(string $sql, array $bindings)`
 * 的第一个参数是 **SQL** —— 一张表名进去，prepare 当场语法错误；就算它通了，
 * 审计表也**没有 `message` 列**（列只有 user_id/username/method/path/status/duration_ms/ip/时间），
 * 请求体本来就不该往审计里放（§4）。外层再套一个 `catch (\Throwable)` 静默 + 恒回 ok，
 * 于是「上报成功」与「上报从未发生」在客户端完全同一个样子。
 * 实测口径：`SELECT count(*) FROM kode_audit_logs WHERE username='frontend-shell'` = 0，
 * 而这张表的结构（含分片表）里从来没有过一行这个用户名。
 *
 * 更要紧的是**门禁站在坏的那一边**：`PluginAnonymousRouteGateTest` 的 `WRITE_GATES`
 * 要求 handler 里出现 `"Db::insert('kode_audit_logs'"` 这串字面量才算有守卫 ——
 * 那正是坏掉的那一行。守卫符号现在钉两枚（准入的 `tooManyAttempts(` + 落点的 `AuditTrail::record(`），
 * 少任何一枚都判红。
 *
 * 这是匿名可写入口，所以按 §4 复用 `AttemptGuard` 的预算；用例把预算拨到 3 并恢复原状，
 * 断言「第 4 次拒掉且不再落库」。
 */
final class LowcodeDiagnosticsTest extends TestCase
{
    protected bool $independentApp = true;

    private const PROBE_IP = '203.0.113.77';

    /** 本用例开始前 `auth.login_max_attempts` 的原文（null = 原本没这一行） */
    private ?string $maxAttemptsRawBefore = null;

    private bool $baselineCaptured = false;

    /** 用例开始时刻：只清理这个时间点之后由本文件写下的行，不碰别人的数据。 */
    private string $startedAt = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));

        $this->startedAt = date('Y-m-d H:i:s');
        $this->captureBudgetBaseline();
        $this->setBudget(3);
        $this->clearBudget();

        $_POST = ['source' => 'zz-phpunit', 'schemaId' => 'zz-schema-1', 'manifestId' => 'zz-manifest-1'];
        HttpRequest::setRequest(
            (new ServerRequest('POST', '/api/plugins/frontend/diagnostics'))
                ->withAttribute('client_ip', self::PROBE_IP)
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->auditTables() as $table) {
            try {
                Db::delete(
                    "DELETE FROM {$table} WHERE username = ? AND path = ? AND created_at >= ?",
                    ['frontend-shell', '/api/plugins/frontend/diagnostics', $this->startedAt]
                );
            } catch (\Throwable) {
                // 这一片不存在/不可读：由下一条对照报出来，这里不盖住用例真正的失败原因
            }
        }
        // 基表也要扫：写侧一旦退回「落基表」的旧形状，残行就只在它那儿
        Db::delete(
            "DELETE FROM kode_audit_logs WHERE username = ? AND path = ? AND created_at >= ?",
            ['frontend-shell', '/api/plugins/frontend/diagnostics', $this->startedAt]
        );

        $this->clearBudget();
        $this->restoreBudget();

        unset($_POST);
        HttpRequest::clear();

        parent::tearDown();
    }

    /** 一次上报：回 ok 的那一行必须真的能在审计读侧枚举到的片表里查到。 */
    public function test_a_report_lands_in_a_table_the_audit_reader_enumerates(): void
    {
        $out = FrontendPlugin::diagnostics();

        self::assertSame(0, $out['code'] ?? null, '已发布契约要求 code 恒 0（§7），失败也要分得开');
        self::assertTrue($out['data']['ok'] ?? null);
        self::assertTrue(
            $out['data']['persisted'] ?? null,
            'handler 回「已落库」但它没落库：修复前正是这一行在骗客户端'
        );

        $found = $this->probeRows();
        self::assertNotEmpty(
            $found,
            '审计读侧枚举的片表里查不到这条上报：落点不在有人读的那张表上（与 #113 同一族）'
        );
        self::assertSame(
            '/api/plugins/frontend/diagnostics',
            (string) $found[0]['path'],
            '落到了片表但不是这一条'
        );
        self::assertSame(
            self::PROBE_IP,
            (string) $found[0]['ip'],
            '来路 IP 不是 ClientIp::resolve() 的口径（旧写法读 $_SERVER[REMOTE_ADDR]，反代下永远是代理机）'
        );
    }

    /** 匿名可写入口必须有预算：拨到 3 次之后，第 4 次要拒、且不再往表里写。 */
    public function test_the_ip_budget_stops_the_flood_before_it_reaches_the_table(): void
    {
        $codes = [];
        for ($i = 0; $i < 5; $i++) {
            $out = FrontendPlugin::diagnostics();
            $codes[] = (int) ($out['code'] ?? -1);
        }

        self::assertSame(
            [0, 0, 0, 429, 429],
            $codes,
            '预算没按 auth.login_max_attempts 落地（前 3 次收、后 2 次拒）'
        );
        self::assertCount(
            3,
            $this->probeRows(),
            '被拒的那几次仍然落库了：预算只挡响应、没挡写入'
        );
    }

    /** 每一跳响应都不许把内部细节吐给匿名调用方（旧写法回带 exception->getMessage()）。 */
    public function test_no_response_carries_internal_detail(): void
    {
        $bodies = [];
        for ($i = 0; $i < 4; $i++) {
            $bodies[] = FrontendPlugin::diagnostics();
        }

        $blob = json_encode($bodies, JSON_UNESCAPED_UNICODE) ?: '';
        self::assertStringNotContainsString('SQLSTATE', $blob, '响应里回带了数据库报错原文');
        self::assertStringNotContainsString('kode_audit_logs', $blob, '响应里回带了表名/落点细节');
        self::assertStringNotContainsString('"note"', $blob, '响应里回带了内部说明键');
    }

    /* ==================== 工具 ==================== */

    /**
     * 读侧枚举到的片表（`AuditController` 扫的就是这一组）。
     *
     * @return list<string>
     */
    private function auditTables(): array
    {
        return Shard::tables(AuditTrail::BASE);
    }

    /**
     * 本用例写下的那几行（逐片查，带回片表名）。
     *
     * @return list<array<string, mixed>>
     */
    private function probeRows(): array
    {
        $rows = [];
        foreach ($this->auditTables() as $table) {
            foreach (Db::select(
                "SELECT path, ip, created_at FROM {$table} WHERE username = ? AND path = ? AND created_at >= ?",
                ['frontend-shell', '/api/plugins/frontend/diagnostics', $this->startedAt]
            ) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function captureBudgetBaseline(): void
    {
        if ($this->baselineCaptured) {
            return;
        }
        $rows = Db::select('SELECT cvalue FROM kode_sys_configs WHERE ckey = ? LIMIT 1', ['auth.login_max_attempts']);
        $this->maxAttemptsRawBefore = $rows === [] ? null : (string) ($rows[0]['cvalue'] ?? '');
        $this->baselineCaptured = true;
    }

    /** 走生产那条路（SysConfig + 失效热缓存），不反射改私有静态：要测的就是真判定链。 */
    private function setBudget(int $n): void
    {
        SysConfig::set('auth.login_max_attempts', (string) $n);
        HotCache::forget('config:auth.login_max_attempts');
    }

    /** 原本没有这一行就删回去，原本有就写回原值；两条都要失效热缓存。 */
    private function restoreBudget(): void
    {
        if ($this->maxAttemptsRawBefore === null) {
            Db::delete('DELETE FROM kode_sys_configs WHERE ckey = ?', ['auth.login_max_attempts']);
        } else {
            Db::update(
                'UPDATE kode_sys_configs SET cvalue = ?, updated_at = NOW() WHERE ckey = ?',
                [$this->maxAttemptsRawBefore, 'auth.login_max_attempts']
            );
        }
        HotCache::forget('config:auth.login_max_attempts');
    }

    /** 清掉本探针 IP 的两把计数键（键形状由 `AttemptGuard::attemptKeys()` 决定）。 */
    private function clearBudget(): void
    {
        foreach (['lowcode_diag_ip:' . self::PROBE_IP, 'lowcode_diag_user:' . self::PROBE_IP] as $key) {
            try {
                cache()->delete($key);
            } catch (\Throwable) {
                // 缓存不可用时 AttemptGuard 一律放行，用例结论不受影响
            }
        }
    }
}
