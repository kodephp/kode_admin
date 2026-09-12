<?php

declare(strict_types=1);

namespace app\tests;

use app\support\AppPackager;
use PHPUnit\Framework\TestCase;

/**
 * 打包命令（pack:phar / pack:bin / pack:verify）冒烟测试。
 *
 * 引擎逻辑由 `AppPackagerTest` 覆盖；本类走**真实子进程**（`php kode <cmd>`），
 * 验证入口薄壳 → 框架 CLI → 命令发现 → 命令执行 → 输出与退出码这条完整链路，
 * 以及各命令 docblock 里承诺的退出码契约（0/1/2）。
 *
 * 刻意不覆盖的部分（会写 25MB 产物或访问外网）：
 *   - `pack:phar` 真正构建（需 phar.readonly=0，耗时且写 build/）；
 *   - `pack:bin` 的 micro.sfx 下载（外部网络 IO）。
 *   这两项由命令行真机验证，见 docs/PACKAGING.md §9。
 */
final class PackCommandsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function test_pack_phar_list_runs_without_building(): void
    {
        // 干跑不写任何产物：即使 phar.readonly=1 也应成功。
        [$code, $out] = $this->execCommand(['pack:phar', '--list']);

        $this->assertSame(0, $code, 'pack:phar --list 应退出 0：' . $out);
        $this->assertStringContainsString('将进包文件清单', $out);
        $this->assertStringContainsString('项目根：', $out);
        // must_include 逐项打勾，证明干跑与真实构建用同一份清单口径。
        foreach ((array) AppPackager::config()['must_include'] as $need) {
            $this->assertStringContainsString('✓ ' . $need, $out, "必备入口未通过干跑校验：{$need}");
        }
    }

    public function test_pack_phar_list_json_matches_engine_collect(): void
    {
        [$code, $out] = $this->execCommand(['pack:phar', '--list', '--json']);

        $this->assertSame(0, $code, 'pack:phar --list --json 应退出 0：' . $out);

        $data = json_decode($out, true);
        $this->assertIsArray($data, '输出应为 JSON：' . $out);
        $this->assertArrayHasKey('files', $data);
        $this->assertArrayHasKey('paths', $data);

        // 命令与引擎必须同源：JSON 里的清单 == AppPackager::collect()。
        $this->assertSame(AppPackager::collect(), $data['paths'], '命令清单与引擎 collect() 漂移');
        $this->assertSame(count($data['paths']), (int) $data['files']);

        foreach ((array) AppPackager::config()['must_include'] as $need) {
            $this->assertContains($need, $data['paths']);
        }
        foreach ((array) AppPackager::config()['exclude_dirs'] as $dir) {
            foreach ($data['paths'] as $rel) {
                $this->assertFalse(
                    str_starts_with($rel, rtrim($dir, '/') . '/'),
                    "排除目录的条目不应出现在进包清单：{$rel}"
                );
            }
        }
    }

    public function test_pack_phar_refuses_when_phar_readonly(): void
    {
        if (ini_get('phar.readonly') === '0') {
            $this->markTestSkipped('phar.readonly=0——本用例只覆盖只读拦截分支');
        }

        [$code, $out] = $this->execCommand(['pack:phar']);

        $this->assertSame(1, $code, 'phar.readonly=1 时应退出 1：' . $out);
        $this->assertStringContainsString('打包中止', $out);
        $this->assertStringContainsString('phar.readonly', $out);
    }

    public function test_pack_verify_accepts_real_artifact(): void
    {
        $artifact = AppPackager::buildDir() . '/' . AppPackager::config()['phar_filename'];
        if (!is_file($artifact)) {
            $this->markTestSkipped('build/kode.phar 不存在（先执行 pack:phar）');
        }

        [$code, $out] = $this->execCommand(['pack:verify', $artifact]);

        $this->assertSame(0, $code, '真实产物应通过校验：' . $out);
        $this->assertStringContainsString('校验通过', $out);
        $this->assertStringContainsString('has_halt_compiler', $out);
        $this->assertStringContainsString('stub_maps_alias', $out);

        [$jcode, $jout] = $this->execCommand(['pack:verify', $artifact, '--json']);
        $this->assertSame(0, $jcode);
        $data = json_decode($jout, true);
        $this->assertTrue($data['ok'], 'JSON 应报告 ok=true：' . $jout);
        $this->assertSame('phar', $data['checks']['tier']);
    }

    public function test_pack_verify_rejects_missing_artifact(): void
    {
        [$code, $out] = $this->execCommand(['pack:verify', $this->root . '/build/no-such.phar']);

        $this->assertSame(1, $code, '产物缺失应退出 1：' . $out);

        [$jcode, $jout] = $this->execCommand(['pack:verify', $this->root . '/build/no-such.phar', '--json']);
        $this->assertSame(1, $jcode);
        $data = json_decode($jout, true);
        $this->assertFalse($data['ok']);
        $this->assertNotEmpty($data['errors']);
    }

    public function test_pack_verify_detects_forged_artifact(): void
    {
        $dir = sys_get_temp_dir() . '/kode_pack_cmd_' . bin2hex(random_bytes(4));
        $this->mkdirP($dir);
        $forged = $dir . '/forged.phar';
        file_put_contents($forged, "<?php\necho 'not a phar';\n");

        try {
            [$code, $out] = $this->execCommand(['pack:verify', $forged]);

            $this->assertSame(1, $code, '伪造文件必须被判失败：' . $out);
            $this->assertStringContainsString('校验未通过', $out);
        } finally {
            @unlink($forged);
            @rmdir($dir);
        }
    }

    public function test_pack_bin_reports_platform_gate(): void
    {
        if (AppPackager::binaryPlatformIssues() === []) {
            $this->markTestSkipped('当前平台满足二进制档条件——本用例只覆盖门禁分支');
        }

        [$code, $out] = $this->execCommand(['pack:bin']);

        $this->assertSame(1, $code, '平台不满足时应退出 1：' . $out);
        $this->assertStringContainsString('不满足二进制档位条件', $out);
        foreach (AppPackager::binaryPlatformIssues() as $issue) {
            $this->assertStringContainsString($issue, $out, "门禁应逐条说明原因：{$issue}");
        }
        // 必须给出可执行的替代方案，而不是只说「不行」。
        $this->assertStringContainsString('pack:phar', $out);
        $this->assertStringContainsString('docker build', $out);
    }

    /**
     * 以子进程运行 `php kode <argv...>`，cwd 固定为项目根。
     *
     * @param list<string> $argv
     * @return array{0: int, 1: string} [退出码, 合并后的输出]
     */
    private function execCommand(array $argv): array
    {
        $cmd = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($this->root . '/kode')
            . ' ' . implode(' ', array_map('escapeshellarg', $argv))
            . ' 2>&1';

        exec($cmd, $output, $code);

        return [$code, implode("\n", $output)];
    }

    private function mkdirP(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $this->fail("无法创建临时目录：{$dir}");
        }
    }
}
