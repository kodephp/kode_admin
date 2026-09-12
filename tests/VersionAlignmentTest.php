<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Testing\TestCase;

/**
 * 版本对齐测试：composer.json 的 version 是唯一事实源（SoT），
 * 其余位置只能与它一致，任何一处漏改都会在发版前被这里拦住。
 *
 * 背景：本骨架的「骨架版本」有三处需要同步——
 *   1. composer.json 的 "version" 字段（发版事实源）
 *   2. config/app.php 的 env 兜底默认值（.env 缺失时生效）
 *   3. phpunit.xml 的 APP_VERSION（测试进程注入值）
 * 任一处不一致会让运维与 CI 读到的版本号互相矛盾，故固化为断言。
 */
final class VersionAlignmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    public function test_composer_json_version_is_semver(): void
    {
        $version = $this->composerVersion();

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $version,
            'composer.json 的 version 必须是语义化版本号，当前值：' . $version
        );
    }

    public function test_runtime_version_matches_composer_json(): void
    {
        $composer = $this->composerVersion();
        $runtime = (string) config('app.version');

        $this->assertSame(
            $composer,
            $runtime,
            "config(app.version) 必须等于 composer.json 的 version（当前 {$runtime} ≠ {$composer}）"
        );
    }

    public function test_config_default_matches_composer_json(): void
    {
        // 校验 config/app.php 里的 env 兜底默认值本身也已同步（防止测试环境
        // 靠 phpunit.xml 注入掩盖了 config 默认值过期）。
        $composer = $this->composerVersion();
        $config = file_get_contents(dirname(__DIR__) . '/config/app.php');
        $this->assertNotFalse($config);

        $this->assertStringContainsString(
            "env('APP_VERSION', '{$composer}')",
            $config,
            "config/app.php 的 APP_VERSION 兜底默认值必须与 composer.json 一致（当前应为 {$composer}）"
        );
    }

    public function test_phpunit_env_matches_composer_json(): void
    {
        $composer = $this->composerVersion();
        $xml = file_get_contents(dirname(__DIR__) . '/phpunit.xml');
        $this->assertNotFalse($xml);

        $this->assertStringContainsString(
            '<env name="APP_VERSION" value="' . $composer . '"/>',
            $xml,
            "phpunit.xml 的 APP_VERSION 必须与 composer.json 一致（当前应为 {$composer}）"
        );
    }

    public function test_composer_and_runtime_use_same_semver_shape(): void
    {
        $runtime = (string) config('app.version');

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $runtime,
            'config(app.version) 必须是纯语义化版本号（禁止 dev/master 等后缀），当前值：' . $runtime
        );
    }

    /** @return string composer.json 的 version 字段 */
    private function composerVersion(): string
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);
        $this->assertIsArray($json);

        return (string) ($json['version'] ?? '');
    }
}
