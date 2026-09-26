<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\MigrationController;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 迁移状态面板回归：applied/pending 必须与 migrations 表一致。
 *
 * 旧实现查的是 `\Kode\Framework\Database\Db`（不存在的类），而 catch 的是
 * \Throwable —— Class not found 是 Error，同样是 Throwable，于是被吞成
 * 「一条已执行记录都没有」。面板于是长期显示 applied_count:0 + 全部文件待执行。
 */
final class MigrationStatusTest extends TestCase
{
    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        HttpRequest::setRequest(new ServerRequest('GET', '/api/migrations'));
    }

    protected function tearDown(): void
    {
        HttpRequest::clear();

        parent::tearDown();
    }

    /** @return array<string, mixed> 信封里的 data（业务字段全在 data 下，别在顶层读） */
    private function statusData(): array
    {
        $response = (new MigrationController())->status();
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $data = (array) ($body['data'] ?? []);
        $this->assertArrayHasKey('applied_count', $data, '信封 data 缺字段，测试会假通过');

        return $data;
    }

    public function test_applied_count_matches_the_migrations_table(): void
    {
        $rows = Db::select('SELECT migration FROM migrations');
        $this->assertNotEmpty($rows, '本机 migrations 表为空，这条取证无效');

        $data = $this->statusData();
        $this->assertSame(count($rows), (int) $data['applied_count']);
    }

    /** 已执行的迁移绝不能再出现在 pending 里（旧实现把全部文件列成待执行）。 */
    public function test_applied_migrations_are_not_listed_as_pending(): void
    {
        $applied = array_column(
            Db::select('SELECT migration FROM migrations'),
            'migration'
        );
        $data = $this->statusData();
        $pendingNames = array_column((array) $data['pending'], 'name');
        // 前置：迁移目录确实有文件，否则 pending 为空是假通过
        $this->assertNotEmpty(glob(dirname(__DIR__) . '/database/migrations/*.php'));

        $overlap = array_intersect($applied, $pendingNames);
        $this->assertSame([], array_values($overlap), '已执行的迁移仍在 pending 列表里');
    }

    /** applied + pending 必须等于文件总数：两边都不许凭空多/少。 */
    public function test_applied_and_pending_partition_the_migration_files(): void
    {
        $files = array_map(
            static fn(string $p): string => basename($p, '.php'),
            glob(dirname(__DIR__) . '/database/migrations/*.php') ?: []
        );
        $data = $this->statusData();
        $appliedNames = array_column(
            Db::select('SELECT migration FROM migrations'),
            'migration'
        );
        $pendingNames = array_column((array) $data['pending'], 'name');

        $this->assertSame(count($files), count($appliedNames) + count($pendingNames));
        $this->assertSame(0, count(array_intersect($appliedNames, $pendingNames)));
        $this->assertEmpty(array_diff($files, array_merge($appliedNames, $pendingNames)));
    }

    public function test_batches_carry_created_at_for_the_timeline(): void
    {
        $batches = (array) $this->statusData()['batches'];
        $this->assertNotEmpty($batches);
        $first = (array) $batches[0];
        $row = (array) $first[0];
        $this->assertArrayHasKey('created_at', $row);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',
            (string) $row['created_at'],
            'created_at 原样透出即可，前端时间线直接读它'
        );
    }
}
