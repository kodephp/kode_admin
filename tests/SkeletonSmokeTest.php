<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Application;
use Kode\Framework\Testing\TestCase;

/**
 * 骨架端到端冒烟测试。
 *
 * 目的有三个：
 *  1. 保证 `composer test` 开箱可用（骨架曾声明了测试命令却没有 tests/ 目录，命令必然失败）；
 *  2. 保证 create-project 拿到的项目能真实引导（容器 + 路由 + 中间件全链路）；
 *  3. 守住版本契约——骨架版本（config app.version）与内核版本（Application::VERSION）
 *     是两个独立演进的号，此处锁定「两者都必须存在且非空」，防止发版时漏改。
 */
final class SkeletonSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    public function test_app_boots(): void
    {
        self::assertInstanceOf(Application::class, $this->app());
    }

    public function test_health_endpoint_is_ok(): void
    {
        $this->get('/health')
            ->assertStatus(200)
            ->assertSee('ok');
    }

    public function test_health_reports_framework_kernel_version(): void
    {
        $payload = $this->get('/health')->json();

        self::assertSame(
            Application::VERSION,
            $payload['version'] ?? null,
            '/health 返回的 version 必须是内核版本常量'
        );
    }

    public function test_ping_endpoint(): void
    {
        $this->get('/ping')->assertStatus(200)->assertSee('pong');
    }

    /**
     * 骨架版本契约：config(app.version) 必须存在且非空。
     *
     * 与 composer.json 的 version 字段、git tag 三者同步；任一处漏改都会在发版后
     * 让运维无法判断线上应用的骨架版本，故在此固化为断言。
     */
    public function test_skeleton_version_is_configured(): void
    {
        $version = config('app.version');

        self::assertIsString($version);
        self::assertNotSame('', $version, 'config(app.version) 不能为空');
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+/',
            $version,
            'config(app.version) 必须是语义化版本号，当前值：' . $version
        );
    }

    public function test_root_route_registered(): void
    {
        $this->get('/')->assertStatus(200);
    }
}
