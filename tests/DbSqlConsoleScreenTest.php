<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\DbController;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * SQL 控制台（POST /api/db/query）执行前屏幕 + 只读事务的回归。
 *
 * 这个面板以应用自己的 DB 账号跑任意文本，而本机该账号是 superuser：
 * 实测 `SELECT length(pg_read_file('/etc/hosts'))` 真回 1510 字节、
 * `pg_read_file('/etc/passwd')` 9344 字节、`lo_import()` 能把宿主文件搬进大对象。
 * READ ONLY 事务挡不住这些（它们是「读」），所以只能靠执行前的名字拦截。
 *
 * 名字拦截的前提是「屏幕文本 = 可执行文本」，本文件两头都测：
 *  - 漏判（放行）：`"pg_read_file"()` 这种引用标识符写法在 PG 里是**真调用**，
 *    屏幕若把引号内容整段擦掉，函数名就消失了 —— 修复前实测 200 + 1510 字节。
 *  - 误判（拦死正常查询）：`WHERE "method" = 'DELETE'` 里的字面量不是关键字。
 */
final class DbSqlConsoleScreenTest extends TestCase
{
    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        HttpRequest::clear();

        parent::tearDown();
    }

    /**
     * 跑一条控制台查询，返回 {status, body}。
     * 控制台的 sql 来自 Request::all()（query + parsedBody + json 合并），
     * 测试里直接 withParsedBody 绑定即可。
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function query(string $sql): array
    {
        HttpRequest::setRequest(
            (new ServerRequest('POST', '/api/db/query'))->withParsedBody(['sql' => $sql])
        );
        $response = (new DbController())->sql();
        $this->assertInstanceOf(ResponseInterface::class, $response);

        return ['status' => $response->getStatusCode(), 'body' => (array) json_decode((string) $response->getBody(), true)];
    }

    /** 断言被拒，并回显 msg 方便定位是哪条规则挡下的。 */
    private function assertRefused(string $sql, string $needle): array
    {
        $result = $this->query($sql);
        $this->assertSame(400, $result['status'], "未拒绝：{$sql}");
        $this->assertStringContainsString($needle, (string) ($result['body']['msg'] ?? ''), "拒绝理由不对：{$sql}");

        return $result;
    }

    /** 屏幕必须按函数名拦住服务端能力函数（这些 READ ONLY 挡不住）。 */
    public function test_server_side_functions_are_refused_before_execution(): void
    {
        foreach ([
            "SELECT pg_read_file('/etc/passwd')",
            "SELECT lo_import('/etc/hosts')",
            "SELECT lo_export(1, '/tmp/kode_pwn')",
            'SELECT pg_sleep(3)',
            "SELECT set_config('statement_timeout', '1', false)",
            "SELECT dblink('host=169.254.169.254', 'SELECT 1')",
            'SELECT pg_terminate_backend(1)',
        ] as $sql) {
            $this->assertRefused($sql, '禁止');
        }
    }

    /**
     * 引用标识符写法不是豁免：PG 里函数名带双引号照样解析。
     * 修复前实测本用例返回 200 且 length 为 1510（/etc/hosts 的真实字节数）。
     */
    public function test_quoted_identifier_call_is_refused(): void
    {
        foreach ([
            "SELECT length(\"pg_read_file\"('/etc/hosts'))",
            "SELECT \"lo_import\"('/etc/hosts')",
            "SELECT * FROM \"pg_catalog\".\"pg_stat_file\"('/etc/passwd')",
        ] as $sql) {
            $result = $this->assertRefused($sql, '禁止调用函数');
            $this->assertArrayNotHasKey('data', $result['body'], "被拒的响应里不该有结果集：{$sql}");
        }
    }

    /** 合法查询不能被屏幕误杀：字面量里的写类关键字不算关键字。 */
    public function test_keyword_looking_literals_still_run(): void
    {
        $result = $this->query("SELECT 'DELETE' AS kw, 'drop table x;' AS stmt, 1 AS \"one\"");
        $this->assertSame(200, $result['status'], '误判：' . json_encode($result['body']['msg'] ?? ''));
        $this->assertSame('DELETE', (string) $result['body']['data'][0]['kw']);
        $this->assertSame('drop table x;', (string) $result['body']['data'][0]['stmt']);
    }

    /** 美元引用也是字面量：`$$DROP TABLE x$$` 不该被当成 DROP。 */
    public function test_dollar_quoted_literal_still_runs(): void
    {
        $result = $this->query("SELECT \$\$DROP TABLE x\$\$ AS txt");
        $this->assertSame(200, $result['status'], '误判：' . json_encode($result['body']['msg'] ?? ''));
        $this->assertSame('DROP TABLE x', (string) $result['body']['data'][0]['txt']);
    }

    /** 引号/注释不成对时无法判定语句类型，必须直接拒（不能放过屏幕上不确定的部分）。 */
    public function test_unbalanced_quotes_and_comments_are_refused(): void
    {
        foreach ([
            "SELECT 'unclosed",
            'SELECT /* unclosed',
            'SELECT $$unclosed',
            'SELECT 1 "unclosed',
        ] as $sql) {
            $this->assertRefused($sql, '不成对');
        }
    }

    /** 多语句与写类 CTE：屏幕按词拦下，走不到服务端。 */
    public function test_multi_statement_and_data_modifying_cte_are_refused(): void
    {
        $this->assertRefused('SELECT 1; SELECT 2', '禁止多语句');
        $this->assertRefused(
            'WITH x AS (DELETE FROM kode_users RETURNING id) SELECT * FROM x',
            '禁止 DELETE 关键字'
        );
        $this->assertRefused('WITH x AS (SELECT 1) INSERT INTO kode_kv SELECT 1 RETURNING 1', '禁止');
    }

    /** 只允许单条 SELECT/WITH 起头。 */
    public function test_non_read_statements_are_refused(): void
    {
        $this->assertRefused('SHOW TABLES', '仅允许 SELECT/WITH');
        $this->assertRefused("VACUUM; SELECT 1 /*x*/", '仅允许 SELECT/WITH');
    }

    /** 外层包裹的 LIMIT 是硬封顶：内层要 500 行也只回 200。 */
    public function test_row_count_is_capped(): void
    {
        $result = $this->query('SELECT generate_series(1, 500) AS n LIMIT 500');
        $this->assertSame(200, $result['status'], '查询失败：' . json_encode($result['body']['msg'] ?? ''));
        $this->assertCount(200, $result['body']['data'] ?? []);
    }

    /**
     * 超时在事务内生效（SET LOCAL），并且回滚后不留在池里的连接上。
     * 语句超时本身若泄漏，后续所有业务查询都会 5 秒被砍——那是全站故障。
     */
    public function test_statement_timeout_applies_inside_and_not_after(): void
    {
        $result = $this->query('SELECT current_setting(\'statement_timeout\') AS t');
        $this->assertSame(200, $result['status'], '查询失败：' . json_encode($result['body']['msg'] ?? ''));
        $this->assertSame('5s', (string) $result['body']['data'][0]['t']);

        $leaked = Db::select('SHOW statement_timeout');
        $this->assertNotSame('5s', (string) ($leaked[0]['statement_timeout'] ?? ''), 'SET LOCAL 泄漏到了池里的连接');
    }

    /** 失败路径也要把事务计数器归零，否则同 worker 后续读全被当成事务内读。 */
    public function test_failing_query_leaves_no_open_transaction(): void
    {
        $this->assertFalse(Db::inTransaction());
        $this->assertRefused('SELECT 1 / 0', '查询失败');
        $this->assertFalse(Db::inTransaction(), '查询失败后事务计数器没归零');

        $result = $this->query('SELECT 1 AS one');
        $this->assertSame(200, $result['status'], '前一条失败把后续查询带崩了');
    }
}
