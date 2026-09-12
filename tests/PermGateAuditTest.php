<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\http\middleware\RequirePerm;
use app\support\PluginContract;
use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;
use Kode\Framework\Testing\TestCase;

/**
 * 权限码门禁兑现审计（阶段 2.3）+ 门禁消歧（阶段 2.5）端到端验证。
 *
 * 背景：`permissions()` 声明的权限码进入权限目录供角色勾选，RequirePerm 是它在
 * 路由上的唯一消费端。此前审计只查「菜单 auth_code ⊂ permissions()」，从不校验
 * 「挂门禁的码是否声明过」——未声明的码永远进不了权限目录，非超管用户无论怎么
 * 勾角色都过不了这道门禁，等于永久锁死（声明⟺实现漂移的反方向：实现了消费、
 * 忘了声明供给）。本类把这条红线固化为回归基线。
 *
 * 同时验证反向噪声的清理：被 RequirePerm 真实消费的权限码（典型于写操作）
 * 不再被误判成「无菜单引用」pending。
 */
final class PermGateAuditTest extends TestCase
{
    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    /**
     * 用全新 PluginManager 只加载夹具插件，避免连带重跑 config/plugins.php
     * 里的既有插件，也保证 plugin:<name> 来源标签只覆盖本夹具。
     */
    private function bootFixturePlugin(string $class): void
    {
        $manager = new PluginManager(resolve(\Kode\DI\Container::class));
        $manager->load([$class]);
    }

    public function test_gate_with_undeclared_perm_is_drift(): void
    {
        $this->bootFixturePlugin(GateUnfulfilledPlugin::class);

        $audit = PluginContract::audit(new GateUnfulfilledPlugin());

        self::assertFalse($audit['ok'], '门禁码未声明必须判漂移');
        $joined = implode("\n", $audit['drift']);
        self::assertStringContainsString('fixture_undeclared:view', $joined, $joined);
        self::assertStringContainsString('路由门禁', $joined, $joined);
        self::assertStringContainsString('permissions()', $joined, $joined);
    }

    public function test_gate_with_declared_perm_passes_zero_drift(): void
    {
        $this->bootFixturePlugin(GateFulfilledPlugin::class);

        $audit = PluginContract::audit(new GateFulfilledPlugin());

        self::assertTrue($audit['ok'], '门禁码已声明不应产生漂移：'
            . implode('；', $audit['drift']));
    }

    public function test_perm_without_menu_and_without_gate_is_pending(): void
    {
        // 阶段 2.5 仍需在「既无菜单引用、也无路由门禁引用」时提示——
        // 这才是真正勾了等于没用的死权限码。
        // 注意：插件整体未声明菜单时不做交叉检查（阶段 2.5 的既有边界），
        // 因为权限码也可能只被前端 v-access 按钮消费，后端无从验证。
        $this->bootFixturePlugin(PermOrphanPlugin::class);

        $audit = PluginContract::audit(new PermOrphanPlugin());

        self::assertTrue($audit['ok'], 'pending 不阻断');
        self::assertNotEmpty($audit['pending']);
        $joined = implode("\n", $audit['pending']);
        self::assertStringContainsString('fixture_orphan:write', $joined, $joined);
        self::assertStringContainsString('无菜单引用', $joined, $joined);
        self::assertStringContainsString('无路由门禁引用', $joined, $joined);
        // 被菜单引用的 view 码不应出现在 pending 中
        self::assertStringNotContainsString('fixture_orphan:view', $joined, $joined);
    }

    public function test_require_perm_exposes_its_codes(): void
    {
        // 审计依赖此 getter 读取门禁码；构造参数必须原样可读
        $mw = new RequirePerm('a:view', 'a:create', 'a:delete');
        self::assertSame(['a:view', 'a:create', 'a:delete'], $mw->perms());
        self::assertSame([], (new RequirePerm())->perms());
    }
}

/**
 * 路由挂了 RequirePerm 但没有任何插件声明该码 → 阶段 2.3 漂移。
 * 用全新 PluginManager 驱动真实 register()，拿到真实的 plugin:gate_unfulfilled 来源标签。
 */
final class GateUnfulfilledPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'gate_unfulfilled';
    }

    public function register(PluginManager $m): void
    {
        $m->addRoute('gate_unfulfilled.index', 'GET', '/api/gate-unfulfilled', static function () {
            return 'ok';
        })->middleware(new RequirePerm('fixture_undeclared:view'));
    }

    public function boot(PluginManager $m): void
    {
    }
}

/**
 * 门禁码由本插件 permissions() 声明（跨插件复用同一码亦合法）→ 零漂移。
 * 复用 notices:view 而非自造新码：allDeclaredPerms() 的比对基准是
 * PluginMarket::discover() 的结果，内联夹具不在其中。
 */
final class GateFulfilledPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'gate_fulfilled';
    }

    public function permissions(): array
    {
        return ['notices:view'];
    }

    public function register(PluginManager $m): void
    {
        $m->addRoute('gate_fulfilled.index', 'GET', '/api/gate-fulfilled', static function () {
            return 'ok';
        }, new RequirePerm('notices:view'));
    }

    public function boot(PluginManager $m): void
    {
    }
}

/**
 * 声明了菜单 + 两个权限码，其中 write 码既不被菜单引用、也不挂任何路由门禁
 * → 阶段 2.5 pending（view 码被菜单引用，不应出现）。
 * 必须声明 menus() 才会触发交叉检查（见测试方法注释的边界说明）。
 */
final class PermOrphanPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'perm_orphan';
    }

    public function menus(): array
    {
        return [
            ['title' => '孤儿面板', 'path' => '/fixture-orphan', 'icon' => 'box',
             'auth_code' => 'fixture_orphan:view'],
        ];
    }

    public function permissions(): array
    {
        return ['fixture_orphan:view', 'fixture_orphan:write'];
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}
