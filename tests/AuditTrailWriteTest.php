<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\AuditController;
use app\admin\controllers\FeatureFlagController;
use app\support\AuditTrail;
use app\support\HashId;
use app\support\Shard;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 审计流水「写在哪儿、读在哪儿」的收口（AGENTS.md §3「产物要落在有人管辖的目录」的 DB 版）。
 *
 * 修复前写入侧有两份各决定落表的代码：`AuditMiddleware` 走 `Shard::table()` 落分片表，
 * `FeatureFlagController::logChange()` 却写死 `INSERT INTO kode_audit_logs`（**基表**），
 * 而所有读侧（`/api/audits`、`/api/audits/mine`、`/api/feature-flags/history`）只遍历
 * `Shard::tables()`（返回 `_00.._NN`，不含基表）。后果不是报错而是**账本缺一页**：
 * 特性开关的变更历史端点永远列不出这个控制器自己记的账，实测基表积了 167 行、
 * 最新一条停在 2026-09-06，没有任何入口读它。
 *
 * 所以这里钉三件事：写侧能选到的表必须都在读侧的枚举里（不变式，比逐个入口断言更耐改）、
 * 开关变更真的能从它自己的 history 里查到、超长入参被截而不是整行消失
 * （两个写入口都 `catch (\Throwable)` 静默，一次报错就等于一条不存在的审计）。
 */
final class AuditTrailWriteTest extends TestCase
{
    protected bool $independentApp = true;

    /** 本轮进程用的探针用户名（tearDown 按它精确删行，不碰别人的数据）。 */
    private string $probe = '';

    /** @var list<string> 本用例建的特性开关 key */
    private array $flags = [];

    /** @var list<array{0: string, 1: int}> 本用例写的审计行（片表 + id：各片序列独立，必须成对记） */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        $this->probe = 'zztrail' . substr(bin2hex(random_bytes(8)), 0, 10);
    }

    protected function tearDown(): void
    {
        foreach ($this->flags as $key) {
            Db::delete('DELETE FROM kode_feature_flags WHERE key = ?', [$key]);
        }
        foreach ($this->rows as [$table, $id]) {
            Db::delete("DELETE FROM {$table} WHERE id = ?", [$id]);
        }
        foreach (Shard::tables(AuditTrail::BASE) as $table) {
            Db::delete("DELETE FROM {$table} WHERE username = ?", [$this->probe]);
        }
        // 基表也要扫一遍：写侧一旦退回「落基表」这个旧形状，残行就只在它那儿
        Db::delete('DELETE FROM kode_audit_logs WHERE username = ?', [$this->probe]);

        HttpRequest::clear();
        parent::tearDown();
    }

    /**
     * 不变式：写侧可能选中的表，必须都在读侧枚举的表集合里。
     *
     * 基表（`kode_audit_logs`）是 DDL 模板与历史归档，不在 `Shard::tables()` 里 ——
     * 收口前 `logChange()` 落的就是它，于是这条不变式被破坏且**没有任何一处会报错**。
     */
    public function test_every_table_a_writer_can_pick_is_one_the_reader_enumerates(): void
    {
        $readSet = Shard::tables(AuditTrail::BASE);
        self::assertNotEmpty($readSet, '读侧枚举为空，这条不变式就成了空转');

        $keys = [
            ['1', '203.0.113.7'],          // 超管（AuditMiddleware 的常态）
            ['991001', '203.0.113.7'],     // 普通账号
            ['0', '203.0.113.8'],          // 未登录：恒按 ip 落片
            ['', '203.0.113.9'],           // 载荷里没有 uid
            ['7', ''],                     // 匿名且取不到 ip（测试环境常见）
            ['7', '2001:db8::42'],         // IPv6 来路
        ];
        foreach ($keys as [$uid, $ip]) {
            $table = AuditTrail::tableFor($uid, $ip);
            self::assertContains(
                $table,
                $readSet,
                "落片选到了读侧不扫的表（uid={$uid} ip={$ip}）：这条审计写进去了但永远查不到"
            );
        }
    }

    /** 端到端：改一次开关，它自己的 history 端点必须列得出这一条（修复前恒为空）。 */
    public function test_a_flag_change_shows_up_in_its_own_history_endpoint(): void
    {
        $flag = $this->probe;   // key 只允许小写字母开头 + a-z0-9._-，探针名已满足
        $created = $this->call('POST', '/api/any?key=' . $flag, new FeatureFlagController(), 'store', (object) [
            'uid' => 991001,
            'username' => $this->probe,
        ]);
        self::assertSame(200, $created['code'], '开关没建起来，后面的断言会假绿');
        self::assertSame('created', $created['data']['action'] ?? null);
        $this->flags[] = $flag;

        $listed = $this->call('GET', '/api/any', new FeatureFlagController(), 'history')['data'];
        $probe = $this->probe;
        $mine = array_values(array_filter(
            (array) $listed,
            static fn($row) => (string) ($row['username'] ?? '') === $probe
        ));

        self::assertNotEmpty(
            $mine,
            'history() 查不到本控制器刚写的变更：写侧落的表不在读侧的枚举里'
        );
        self::assertSame('/api/feature-flags', $mine[0]['path']);
        self::assertSame('POST', $mine[0]['method']);
        // 出参按 §4 混淆：user_id 必须是 HashId，不能是裸的 991001
        self::assertSame(HashId::enc(991001), $mine[0]['user_id']);
    }

    /** 助手 `record()` 写的行，`/api/audits` 列表第一页就要能翻到。 */
    public function test_a_record_written_through_the_helper_is_listed(): void
    {
        $id = AuditTrail::record(
            '4242',
            $this->probe,
            'PUT',
            '/api/zz-trail-probe',
            200,
            12,
            '203.0.113.5'
        );
        self::assertGreaterThan(0, $id, '拿不到行 id：清理与断言都失去依据（Db::insert 的返回值变了？）');
        $this->rows[] = [AuditTrail::tableFor('4242', '203.0.113.5'), $id];

        $listed = $this->call('GET', '/api/any?username=' . $this->probe, new AuditController(), 'index');
        self::assertSame(200, $listed['code']);
        $rows = (array) $listed['data'];
        self::assertCount(1, $rows, '探针行在列表里不是一行（读侧表集或过滤失配）');
        self::assertSame('/api/zz-trail-probe', $rows[0]['path']);
        self::assertSame(HashId::enc(4242), (string) $rows[0]['user_id'], '列表出参的 user_id 没按 §4 混淆');
    }

    /**
     * 超长值必须**截断后落库**，而不是整行消失。
     *
     * 收口前 `logChange()` 对 path 一个字符都没截（列宽 255），而两个写入口都静默 catch：
     * 一次 22001 报错在页面上完全不可见，只是那条审计从此不存在。
     */
    public function test_overlong_values_are_clipped_instead_of_vanishing(): void
    {
        // 多字节：按字节截会切成半个字符，pgsql 连「无效 UTF-8」一起报错，行照样消失
        $wide = str_repeat('审', 200);
        $id = AuditTrail::record('1', $wide, 'GET', $wide, 200, 0, '203.0.113.6');
        self::assertGreaterThan(0, $id);

        $table = AuditTrail::tableFor('1', '203.0.113.6');
        $this->rows[] = [$table, $id];
        $rows = Db::select("SELECT username, path FROM {$table} WHERE id = ?", [$id]);
        self::assertCount(1, $rows, '截断没生效：整行被 pgsql 拒了');
        self::assertSame(mb_substr($wide, 0, 64), (string) $rows[0]['username'], 'username 没按列宽截到 64 字符');
        self::assertSame(mb_substr($wide, 0, 255), (string) $rows[0]['path'], 'path 没按列宽截到 255 字符');
    }

    /**
     * @return array{code: int, msg: string, data: mixed}
     */
    private function call(string $method, string $uri, object $controller, string $action, mixed $payload = null): array
    {
        $query = (string) parse_url($uri, PHP_URL_QUERY);
        $parsed = [];
        if ($query !== '') {
            parse_str($query, $parsed);
        }
        $request = (new ServerRequest($method, $uri))->withQueryParams($parsed);
        if ($payload !== null) {
            $request = $request->withAttribute('auth', $payload);
        }
        HttpRequest::setRequest($request);

        $response = $controller->{$action}();
        $decoded = (array) json_decode((string) $response->getBody(), true);

        return [
            'code' => (int) ($decoded['code'] ?? $response->getStatusCode()),
            'msg' => (string) ($decoded['msg'] ?? ''),
            'data' => $decoded['data'] ?? null,
        ];
    }
}
