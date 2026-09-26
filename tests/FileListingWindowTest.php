<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\FileController;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 媒体库目录列表的分页窗口（AGENTS.md §5「一次请求的开销必须有界」）。
 *
 * 修的是这一行的顺序：`SELECT id, path FROM kode_media WHERE path IN (?, ?, …)` 的占位符
 * 是**整个目录**每个文件一个，而不是当页那几行 ——
 * 目录里文件数无界，SQL 的绑定参数就无界，pgsql 协议在 65535 个参数处直接把请求打成 500，
 * 而 `?size=5` 的那次调用本该只绑 5 个。
 *
 * 两条断言各管一半：
 *  - 行为面：切片后 `media_id` 必须还挂得上（否则「收口」把功能收没了）；
 *  - 口径面：`array_slice` 必须排在查询之前（只测正向行为，「先查再切」和「先切再查」输出一模一样）。
 */
final class FileListingWindowTest extends TestCase
{
    protected bool $independentApp = true;

    private const DIR = 'zz_window_probe';

    /** @var list<string> 本用例落盘的文件绝对路径 */
    private array $files = [];

    /** @var list<int> 本用例写入的 kode_media 行 id */
    private array $media = [];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));

        $this->dir = base_path('storage/uploads/' . self::DIR);
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->media as $id) {
            Db::delete('DELETE FROM kode_media WHERE id = ?', [$id]);
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if ($this->files !== []) {
            @rmdir($this->dir);
        }

        HttpRequest::clear();
        parent::tearDown();
    }

    /** 逐页翻完：每一行的 media_id 都必须还是它自己那枚登记号（切片不能把关联切掉）。 */
    public function test_every_page_still_carries_its_media_id(): void
    {
        $names = ['a.txt', 'b.txt', 'c.txt'];
        $expected = [];
        foreach ($names as $i => $name) {
            $path = "{$this->dir}/{$name}";
            file_put_contents($path, "probe-{$i}");
            // mtime 写死成递减，排序结果（mtime 倒序）就是 a、b、c，不受建文件耗时影响
            touch($path, 1_700_000_000 - $i * 100);
            $this->files[] = $path;

            $rel = 'uploads/' . self::DIR . '/' . $name;
            $id = (int) Db::insert('INSERT INTO kode_media (name, path) VALUES (?, ?)', [$name, $rel]);
            self::assertGreaterThan(0, $id, '媒体行没建起来，断言会假绿');
            $this->media[] = $id;
            $expected[$rel] = \app\support\HashId::enc($id);
        }

        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            $body = $this->call(['dir' => self::DIR, 'page' => (string) $page, 'size' => '1']);
            self::assertSame(200, (int) $body['code']);
            self::assertSame(3, (int) $body['total'], 'total 被当页条数带跑');
            $rows = (array) $body['data'];
            self::assertCount(1, $rows, "第 {$page} 页不是一条");
            foreach ($rows as $row) {
                $seen[] = $row['name'] . '|' . $row['media_id'];
            }
        }

        self::assertSame(
            ['a.txt|' . $expected['uploads/' . self::DIR . '/a.txt'],
             'b.txt|' . $expected['uploads/' . self::DIR . '/b.txt'],
             'c.txt|' . $expected['uploads/' . self::DIR . '/c.txt']],
            $seen,
            '逐页结果与登记关系不符（顺序按 mtime 倒序）'
        );
    }

    /** 越界 page 依旧 400，且发生在扫目录之前。 */
    public function test_an_absurd_page_is_refused_before_the_directory_is_walked(): void
    {
        $body = $this->call(['dir' => self::DIR, 'page' => '9223372036854775807']);

        self::assertSame(400, (int) $body['code']);
        self::assertStringContainsString('翻页', (string) $body['msg']);
    }

    /** 口径：先切片再查关联 —— 绑定参数个数由当页行数决定，不是目录大小。 */
    public function test_the_media_lookup_happens_after_the_slice(): void
    {
        $code = $this->codeOnly((string) file_get_contents(
            dirname(__DIR__) . '/app/admin/controllers/FileController.php'
        ));
        $body = substr(
            $code,
            (int) strpos($code, 'public function index'),
            (int) (strpos($code, 'public function dirs') - (int) strpos($code, 'public function index'))
        );

        $slice = strpos($body, 'array_slice($items');
        $lookup = strpos($body, 'WHERE path IN');
        self::assertNotFalse($slice, 'index() 不再用 array_slice 出当页，判据失效');
        self::assertNotFalse($lookup, 'index() 不再查媒体注册表，判据失效');
        self::assertLessThan(
            (int) $lookup,
            (int) $slice,
            '整目录进 IN 列表：文件数无界 = 绑定参数无界，pgsql 在 65535 处报 500'
        );
        // 查完之后的那次切片不得还在（「先查全量再切」就是这次收口前的形状）
        self::assertStringNotContainsString(
            'page(array_slice($items',
            $body,
            '当页切片又回到了查询之后'
        );
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function call(array $query): array
    {
        HttpRequest::setRequest(
            (new ServerRequest('GET', '/api/any?' . http_build_query($query)))->withQueryParams($query)
        );

        return (array) json_decode((string) (new FileController())->index()->getBody(), true);
    }

    /** 剥掉注释与 docblock：判据说的是代码顺序，不是说明文字。 */
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
}
