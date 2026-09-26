<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\AuditController;
use app\support\AuditTrail;
use app\support\Shard;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 审计总览 `/api/audits/overview` 的聚合口径（AGENTS.md §3「归并型分页/聚合」的另一半）。
 *
 * 修复前 `overview()` 是「每片各取 `ORDER BY id DESC LIMIT 200000` 行进 PHP，再手搓
 * status/method/path/ip/user 的计数」。两个后果：
 *  1. 行预算正比于**历史流水**而不是正比于分组基数 —— 4 片 × 20 万行的关联数组足以把
 *     worker 撑成内存 fatal，而这是后台一个页面刷新；
 *  2. 被 LIMIT 截掉的那部分行不会让 `total_7d` 变小（它走的是 COUNT(*)），
 *     于是 status 分布、方法分布、路径 Top、唯一 IP/用户数、平均耗时**全部静默偏小**，
 *     页面上没有任何一处提示这是抽样。`avg_duration_ms` 尤其别扭：分子取自抽样、
 *     分母取自全量 count。
 *
 * 现在聚合恒在 SQL 侧（每片一条带窗口的腿，UNION ALL 成一个派生表）。这里钉两头：
 *  - 静态门禁：`overview()` 的函数体里不许再出现「取 N 行进 PHP 再排序」的形状
 *    （正对照用修复前的真实代码喂同一个判据，必须判红）；
 *  - 行为断言：探针行逐项进账、跨片 MAX 取到全局第一、`sum(分布) == total_7d`
 *    这类自洽不变式恒成立、「昨天」是自然日半开区间。
 *
 * 注：本地库 7 天窗口只有几千行，截断不会在这里复现 —— 那半边由静态门禁负责。
 */
final class AuditOverviewTest extends TestCase
{
    protected bool $independentApp = true;

    /** 本轮进程用的探针用户名（tearDown 按它精确删行，不碰别人的数据）。 */
    private string $probe = '';

    private string $path = '/api/zz-overview-probe';

    /** @var list<array{0: string, 1: int}> 本用例写的审计行（片表 + id） */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        $this->probe = 'zzovw' . substr(bin2hex(random_bytes(8)), 0, 9);
    }

    protected function tearDown(): void
    {
        foreach ($this->rows as [$table, $id]) {
            Db::delete("DELETE FROM {$table} WHERE id = ?", [$id]);
        }
        foreach (Shard::tables(AuditTrail::BASE) as $table) {
            Db::delete("DELETE FROM {$table} WHERE username = ?", [$this->probe]);
        }
        Db::delete('DELETE FROM kode_audit_logs WHERE username = ?', [$this->probe]);

        HttpRequest::clear();
        parent::tearDown();
    }

    // ------------------------------------------------------- 静态门禁

    /**
     * 判据本体：聚合面里不许有「按行抽样再在 PHP 里数」。
     *
     * @return string|null 违规原因；null 表示干净
     */
    private static function samplingViolation(string $body): ?string
    {
        if (preg_match('/LIMIT\s+(\d{3,})/i', $body, $m) === 1 && (int) $m[1] > 100) {
            return "按行截取的抽样上限 LIMIT {$m[1]}（行预算必须正比于分组基数，不能正比于流水）";
        }
        if (str_contains($body, 'arsort(') || str_contains($body, 'uasort(')) {
            return '在 PHP 侧给累计出来的分布排序（说明聚合发生在内存里）';
        }
        foreach (['UNION ALL', 'COUNT(DISTINCT', 'GROUP BY'] as $must) {
            if (!str_contains($body, $must)) {
                return "缺少 SQL 侧聚合要素 {$must}";
            }
        }

        return null;
    }

    /** 取出某几个方法的函数体（含注释），并顺带证明「真的扫到了东西」。 */
    private static function bodiesOf(string ...$methods): string
    {
        $file = new \ReflectionClass(AuditController::class);
        $lines = file((string) $file->getFileName());
        $out = '';
        foreach ($methods as $method) {
            $m = $file->getMethod($method);
            $out .= implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
        }

        return $out;
    }

    public function test_overview_aggregates_on_the_database_side(): void
    {
        $body = self::bodiesOf('overview', 'overviewSql', 'existingShards');
        self::assertStringContainsString('UNION ALL', $body, '没扫到聚合体，这条门禁就是空转');
        self::assertGreaterThan(3000, strlen($body), '函数体短得可疑：门禁在扫的不是实现本体');
        self::assertNull(self::samplingViolation($body), self::samplingViolation($body) ?? '');
    }

    /** 正对照：修复前的真实写法喂给同一个判据，必须判红。 */
    public function test_the_pre_fix_shape_is_rejected_by_the_same_predicate(): void
    {
        $old = <<<'PHP'
        foreach (Shard::tables('kode_audit_logs') as $table) {
            try {
                $rows = Db::select(
                    "SELECT id, status, method, path, duration_ms, ip, username, created_at
                     FROM {$table} WHERE created_at >= NOW() - INTERVAL '7 days' ORDER BY id DESC LIMIT 200000"
                );
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $row) {
                $paths[$row['path']] = ($paths[$row['path']] ?? 0) + 1;
                $ips[$row['ip']] = true;
            }
        }
        $sorted = $paths;
        arsort($sorted);
        $avg = round($totalDuration / max(1, $total7d), 2);
        PHP;

        $violation = self::samplingViolation($old);
        self::assertNotNull($violation, '判据认不出修复前的形状，那它对将来也认不出');
        self::assertStringContainsString('LIMIT 200000', (string) $violation);
    }

    // ------------------------------------------------------- 表集与形状

    /** 片表存在性核对：缺的片必须进 missing_tables，而不是被 try/catch 吃成一个正常数字。 */
    public function test_existing_shards_splits_present_from_missing(): void
    {
        $tables = Shard::tables(AuditTrail::BASE);
        $probe = 'zz_no_such_shard_table';
        $method = new \ReflectionMethod(AuditController::class, 'existingShards');
        $method->setAccessible(true);
        [$present, $missing] = $method->invoke(new AuditController(), [...$tables, $probe]);

        self::assertSame($tables, $present, '真实存在的片被误判为缺失（聚合会静默少口径）');
        self::assertSame([$probe], $missing);
    }

    /** 出参形状是前端契约（kode.ts 的 auditOverview 类型 + audits/index.vue 直接取键）。 */
    public function test_response_shape_is_pinned(): void
    {
        $data = $this->overview();

        $keys = array_keys($data);
        sort($keys);
        self::assertSame(
            ['avg_duration_ms', 'methods', 'missing_tables', 'slow_paths', 'status', 'today_24h', 'top_paths', 'total_7d', 'unique_ips', 'unique_users', 'yesterday'],
            $keys,
            '总览出参的键集变了：前端卡片会静默取到 undefined'
        );
        self::assertSame(['2xx', '3xx', '4xx', '5xx', 'other'], array_keys((array) $data['status']));
        self::assertIsArray($data['missing_tables']);
        self::assertSame([], $data['missing_tables'], '本库的片表应当齐全（缺失时下面的数字只是部分口径）');
    }

    /** 自洽不变式：每张分布表的计数之和 == 同一个窗口里的总行数。 */
    public function test_distributions_partition_the_same_row_set(): void
    {
        $this->recordOn('900101', 'GET', 200, 10, '203.0.113.201');
        $this->recordOn('900102', 'POST', 404, 20, '203.0.113.202');
        $this->recordOn('900103', 'OPTIONS', 503, 30, '203.0.113.203');

        $data = $this->overview();
        $total = (int) $data['total_7d'];

        self::assertSame($total, array_sum((array) $data['status']), '状态分布之和 != 总量（抽样与全量不同源）');
        $methods = array_column((array) $data['methods'], 'count', 'method');
        self::assertSame($total, array_sum($methods), '方法分布之和 != 总量');
        self::assertGreaterThan(0, $total);
    }

    /**
     * 探针行逐项进账，并且跨片的慢接口 Top 取的是**全局**最大值。
     *
     * 各片独立序列、独立落表（`AuditTrail::tableFor()` 按 uid 哈希路由），所以两条探针行
     * 刻意落在不同片上 —— 全局 Top1 只能靠 UNION ALL 聚出来，逐片 LIMIT + PHP 归并的写法
     * 在这里既可能把它整条截掉，也会让平均值与总量不同源。
     */
    public function test_probe_rows_land_in_every_card(): void
    {
        [$uidA, $uidB] = $this->twoShardUids();
        $before = $this->overview();
        $slow = 87654321;
        $this->recordOn($uidA, 'OPTIONS', 500, $slow, '203.0.113.204');
        $this->recordOn($uidB, 'GET', 200, 5, '203.0.113.206');

        $after = $this->overview();

        self::assertGreaterThanOrEqual(
            (int) $before['total_7d'] + 2,
            (int) $after['total_7d'],
            '探针行没进总量（并发写入只会让它偏大，不会偏小）'
        );
        self::assertGreaterThanOrEqual(
            (int) $before['unique_users'] + 1,
            (int) $after['unique_users'],
            '新用户名没被 COUNT(DISTINCT) 计到'
        );
        self::assertGreaterThanOrEqual(
            (int) ((array) $before['status'])['5xx'] + 1,
            (int) ((array) $after['status'])['5xx'],
            '5xx 那行没进状态分布'
        );
        $methods = array_column((array) $after['methods'], 'count', 'method');
        self::assertGreaterThanOrEqual(1, (int) ($methods['OPTIONS'] ?? 0), 'OPTIONS 一条都没统计到');

        $slowest = (array) ($after['slow_paths'][0] ?? []);
        self::assertSame($this->path, $slowest['path'] ?? null, '跨片 MAX(duration_ms) 的第一名不是探针行');
        self::assertSame($slow, (int) ($slowest['duration_ms'] ?? 0));

        // 平均耗时的分子分母必须同源：塞进一个 87654321ms 的行，平均值必然被抬高。
        self::assertGreaterThan(
            (float) $before['avg_duration_ms'],
            (float) $after['avg_duration_ms'],
            '总览里插进一条极慢记录而平均值不动 —— 分子或分母有一头没跟着同一集合走'
        );
    }

    /**
     * 「昨天」= 自然日半开区间 [昨天 00:00, 今天 00:00)。
     *
     * 旧写法用 `created_at <= '昨天 23:59:59'`，落在最后一秒之内（含小数）的行既不算今天
     * 也不算昨天。这里就是造一条 昨天 23:59:59.5 的行。
     */
    public function test_yesterday_window_is_half_open(): void
    {
        $before = (int) $this->overview()['yesterday'];
        [$table, $id] = $this->recordOn('900104', 'GET', 200, 7, '203.0.113.205');

        $todayStart = date('Y-m-d 00:00:00');
        $affected = Db::update(
            "UPDATE {$table} SET created_at = (?::timestamptz - INTERVAL '500 milliseconds') WHERE id = ?",
            [$todayStart, $id]
        );
        self::assertSame(1, $affected, '没改到探针行的时间，下面的断言是空转');

        $after = (int) $this->overview()['yesterday'];
        self::assertSame(
            $before + 1,
            $after,
            '昨天最后一秒内的行没算进 yesterday（旧写法 `<= 23:59:59` 的形状）'
        );
    }

    // ------------------------------------------------------- 助手

    /**
     * 找到两个会落到不同片表的 uid。
     *
     * @return array{0: string, 1: string}
     */
    private function twoShardUids(): array
    {
        $byTable = [];
        for ($n = 900200; $n < 900400; $n++) {
            $byTable[AuditTrail::tableFor((string) $n, '')][] = (string) $n;
            if (count($byTable) >= 2) {
                break;
            }
        }
        if (count($byTable) < 2) {
            self::markTestSkipped('分片配置只有一片，跨片聚合断言无从验证：' . implode(',', array_keys($byTable)));
        }
        $groups = array_values($byTable);

        return [$groups[0][0], $groups[1][0]];
    }

    /**
     * 走唯一派发口写一行（uid 决定落哪片），返回 [落到的片表, 行 id]。
     *
     * @return array{0: string, 1: int}
     */
    private function recordOn(string $uid, string $method, int $status, int $ms, string $ip): array
    {
        $id = AuditTrail::record($uid, $this->probe, $method, $this->path, $status, $ms, $ip);
        self::assertGreaterThan(0, $id, '拿不到行 id：清理与断言都失去依据');
        $table = AuditTrail::tableFor($uid, $ip);
        $this->rows[] = [$table, $id];

        return [$table, $id];
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        $request = (new ServerRequest('GET', '/api/audits/overview'))
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => 'admin']);
        HttpRequest::setRequest($request);

        $body = (array) json_decode((string) (new AuditController())->overview()->getBody(), true);
        self::assertSame(200, (int) ($body['code'] ?? 0), '总览接口本身没出 200: ' . (string) ($body['msg'] ?? ''));

        return (array) ($body['data'] ?? []);
    }
}
