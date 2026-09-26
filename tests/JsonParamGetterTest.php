<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Http\Controller;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Http\Response;

/**
 * 取值入口回归：`json()` 是响应构造器，不是取参器。
 *
 * 为什么单独锁这一条：控制器基类同时提供 `json(mixed $data, int $status)`（构造响应）
 * 与 `input()/query()/post()/param()`（取入参）。写成 `$this->json('step')` 时
 * PHP 不会报任何错——它返回一个 Response 对象，于是
 * `(bool) $resp` 恒为 true、`(int) $resp` 恒为 1、`(string) $resp` 直接抛 Error。
 * 这类调用曾同时存在于 4 个管理端控制器里（备份恢复、清理、迁移步长、定时任务增删、
 * 特性开关读写），表现是「接口 500」或「参数被静默忽略」，而单测全绿——
 * 因为这些端点没有端到端用例。本测试锁两层：机制取证 + 全仓静态扫描。
 */
final class JsonParamGetterTest extends TestCase
{
    /** 只暴露取值能力的探针控制器：把 protected 的取参器转成公开方法 */
    private static function probe(): object
    {
        return new class extends Controller
        {
            public function readInput(string $key, mixed $default = null): mixed
            {
                return $this->input($key, $default);
            }

            public function readJson(string $key, mixed $default = null): mixed
            {
                return $this->json($key, is_int($default) ? $default : 200);
            }
        };
    }

    private static function bindJsonRequest(string $body): void
    {
        HttpRequest::setRequest(
            new ServerRequest(
                'POST',
                '/api/probe',
                [],
                ['content-type' => 'application/json'],
                $body
            )
        );
    }

    protected function tearDown(): void
    {
        HttpRequest::clear();

        parent::tearDown();
    }

    public function test_input_reads_json_body(): void
    {
        self::bindJsonRequest('{"step":7,"enabled":false,"name":"nightly"}');

        $probe = self::probe();
        self::assertSame(7, $probe->readInput('step'), 'JSON 体里的 step 应取到 7');
        self::assertFalse($probe->readInput('enabled'), 'false 必须原样取到，不能被默认值吞掉');
        self::assertSame('nightly', $probe->readInput('name'));
        self::assertNull($probe->readInput('absent'), '缺失键返回 null');
        self::assertSame(3, $probe->readInput('absent', 3), '缺失键回落调用方给的默认值');
    }

    public function test_json_builder_returns_response_not_a_scalar(): void
    {
        self::bindJsonRequest('{"step":7}');

        $probe = self::probe();
        $returned = $probe->readJson('step');

        self::assertInstanceOf(Response::class, $returned, 'json() 返回的是响应对象');

        // 下面三条就是旧代码的全部后果：布尔恒真、整数恒 1、字符串直接炸。
        self::assertTrue((bool) $returned, '(bool) Response 恒为 true —— toggle 关不掉任务的原因');
        self::assertSame(1, @((int) $returned), '(int) Response 恒为 1 —— 迁移 step 参数被吃掉的原因');

        $this->expectException(\Error::class);
        (string) $returned;
    }

    /**
     * 全仓扫描：任何控制器都不得把 `json()` 当取参器用。
     */
    public function test_no_controller_uses_json_as_param_getter(): void
    {
        $offenders = [];
        $root = dirname(__DIR__) . '/';

        foreach (self::phpFiles() as $file) {
            $src = (string) file_get_contents($file);

            // 第一个实参是字符串字面量 => 取参意图；数组/变量 => 响应构造，合法。
            if (preg_match_all(
                '/\$this->\s*json\s*\(\s*(\x27[^\x27\\\\]*(?:\\\\.[^\x27\\\\]*)*\x27|"[^"\\\\]*(?:\\\\.[^"\\\\]*)*")/',
                $src,
                $hits,
                PREG_SET_ORDER
            )) {
                foreach ($hits as $hit) {
                    $line = substr_count(substr($src, 0, (int) strpos($src, $hit[0])), "\n") + 1;
                    $offenders[] = str_replace($root, '', $file) . ':' . $line
                        . ' json(' . $hit[1] . ', …)';
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "控制器把响应构造器 json() 当取参器用了（值会被静默吞成 true/1，或直接 Error）。" .
            " 请改用 input()/query()/post()/param()：\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * 控制器与插件里所有 PHP 源文件。
     *
     * @return list<string>
     */
    private static function phpFiles(): array
    {
        $root = dirname(__DIR__);
        $files = [];

        foreach (['app', 'plugins'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
