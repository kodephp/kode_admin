<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\middleware\RequirePerm;
use app\support\PermCatalog;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;

/**
 * 权限码的「授予面 ⟺ 消费面」对账。
 *
 * 消费面 = 真实注册路由上挂的 RequirePerm 码；授予面 = 角色页能勾到的码
 * （app\support\PermCatalog，唯一派生口）。两张面此前从没对齐过：
 * 审计拿 `permissions()` 声明面当授予面用，而 `PermsController::catalog()`
 * 只出「菜单码 ∪ 按钮码」—— 于是插件路由上 17 个码里 11 个操作码
 * （workflow:edit、notices:create/update/delete、wallet:adjust/create/export/transfer、
 * gitee:edit、lowcode:edit/publish）谁都没勾得到，非超管调写接口恒 403，
 * 而审计全绿（fail-closed，所以没有任何报错指向真正的原因）。
 *
 * 本类比的是**注册后的路由表**而不是源码 grep：注释里写「已挂门禁」不算，
 * 码没进授予面也不算。
 */
final class PermCatalogGrantabilityTest extends TestCase
{
    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    /** 真实注册路由上挂着的 RequirePerm 码（去重）。 */
    private function requiredCodes(): array
    {
        $codes = [];
        /** @var \Kode\Http\App $app */
        $app = resolve(\Kode\Http\App::class);
        foreach ($app->getRouter()->getRoutes() as $route) {
            foreach ($route->getMiddlewares() as $mw) {
                if (!$mw instanceof RequirePerm) {
                    continue;
                }
                foreach ($mw->perms() as $code) {
                    $code = trim((string) $code);
                    if ($code !== '') {
                        $codes[$code] = true;
                    }
                }
            }
        }
        $list = array_keys($codes);
        sort($list);

        return $list;
    }

    public function test_the_scan_finds_a_non_trivial_number_of_gates(): void
    {
        // 防空转：路由表解析不出来 / RequirePerm 换了挂载方式，requiredCodes() 会静默变空，
        // 下面那条「全部勾得到」就变成对空集合的真命题。
        self::assertGreaterThanOrEqual(
            15,
            count($this->requiredCodes()),
            '路由上的 RequirePerm 码少于 15 个：扫描面失效了，后面的对断言不能信'
        );
    }

    public function test_every_gate_code_is_grantable_from_the_role_editor(): void
    {
        $grantable = PermCatalog::codes();
        $missing = array_values(array_diff($this->requiredCodes(), $grantable));

        self::assertSame(
            [],
            $missing,
            '这些权限码挂在路由上却进不了权限目录，任何角色都勾不到（非超管永久 403）：'
            . implode('、', $missing)
            . '。补法二选一：给对应菜单页/按钮配这个码，或让所属插件在 permissions() 里声明它。'
        );
    }

    /**
     * 正对照：目录里必须真有「只来自 permissions() 声明」的码 —— 挡住
     * 「第三张输入面没接线，本类只是靠菜单码凑齐」这种假绿。
     * 判据从库里现推，不写死码名：库里没有、目录里有的那些，就是第三张面补进来的。
     */
    public function test_catalog_offers_codes_no_menu_or_button_carries(): void
    {
        $fromDb = [];
        foreach (Db::select("SELECT auth_code AS c FROM kode_menus WHERE auth_code <> ''") as $r) {
            $fromDb[(string) $r['c']] = true;
        }
        foreach (Db::select('SELECT code AS c FROM kode_menu_buttons') as $r) {
            $fromDb[(string) $r['c']] = true;
        }
        // 插件 menus() 里声明的码也算菜单面（它只是还没同步进表）。
        // 这一腿必须真的取到东西：mutate 掉 permissions() 那条腿时本用例先是**照样绿**，
        // 因为 menuDeclaredCodes() 的 catch (\Throwable) 把「取不到插件」吞成空集，
        // 于是「库里没有」这个前提恒成立。现在不兜了，取不到就炸。
        $menuDeclared = $this->menuDeclaredCodes();
        self::assertNotEmpty(
            $menuDeclared,
            '一个插件的 menus() 码都没扫到：本用例的比对基准是空的，绿不说明任何事'
        );
        foreach (array_keys($menuDeclared) as $code) {
            $fromDb[$code] = true;
        }

        $onlyFromPermissions = array_values(array_diff(PermCatalog::codes(), array_keys($fromDb)));

        self::assertNotSame(
            [],
            $onlyFromPermissions,
            '权限目录里没有任何「仅由 permissions() 声明」的码：PermCatalog 的第三张输入面没生效，'
            . '写侧操作码（create/update/delete/edit/…）就又勾不到了'
        );
        // 这些码得真的被路由消费，否则只是把死码搬进目录，不算修好任何东西。
        $consumed = array_intersect($onlyFromPermissions, $this->requiredCodes());
        self::assertNotEmpty(
            $consumed,
            '仅由 permissions() 补进目录的码没有任何路由在用：对照失效（扫到的可能是别的来源）'
        );
    }

    /** @return array<string,true> 各插件 menus() 声明的 auth_code 集合 */
    private function menuDeclaredCodes(): array
    {
        $out = [];
        /** @var \Kode\Framework\Plugin\PluginManager $manager */
        $manager = resolve(\Kode\Framework\Plugin\PluginManager::class);
        foreach ($manager->all() as $plugin) {
            if (!method_exists($plugin, 'menus')) {
                continue;
            }
            foreach ((array) $plugin->menus() as $menu) {
                $code = (string) ($menu['auth_code'] ?? '');
                if ($code !== '') {
                    $out[$code] = true;
                }
            }
        }

        return $out;
    }
}
