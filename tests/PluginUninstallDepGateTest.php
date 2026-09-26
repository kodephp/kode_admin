<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\PluginController;
use app\support\PluginDependency;
use app\support\PluginMarket;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 卸载侧的下游依赖门禁（install/resume/upgrade 都有门禁，「拆」这一步原先没有）。
 *
 * 缺口的形状：插件 A `requires()` B，卸载 B 时既不校验也不级联——A 仍在 enabled，
 * 却当场失去 B 注册的服务与事件源；带 `purge=1` 还会把 B 的表 DROP 掉，
 * A 的下次请求直接 500。pause 有级联、resume 有门禁，唯独卸载两条都不沾。
 *
 * 门禁口径（本文件同时守住这三条）：
 *  1. 只认「已启用」的下游：paused 的下游恢复时由 resume 的依赖门禁兜住，uninstalled 的没在跑；
 *  2. 单插件卸载 400 并点名下游，让人去改而不是替他做决定；
 *  3. 批量卸载里同批提交的名字互不阻塞（[A, B] 任意顺序都能成对卸掉），
 *     否则报错指向「顺序不对」这种假因；
 *  4. 但豁免只发给「真会被卸掉」的那些：批内有一个卸不掉时，blocked 清单要如实报出
 *     批处理完后仍然活着的阻塞方，而不是只看批外那几个。
 *
 * 隔离：用临时插件文件 plugins/uninst_*.php，tearDown 删文件 + 删 kode_plugins 行 +
 * flush 两个引擎的静态缓存（同 PluginDependencyTest 的口径）。
 */
final class PluginUninstallDepGateTest extends TestCase
{
    private const BASE = 'uninst_gate_base';

    private const CHILD = 'uninst_gate_child';

    private const LEAF = 'uninst_gate_leaf';

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $tempNames = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        $this->tempNames = [self::BASE, self::CHILD, self::LEAF];
    }

    protected function tearDown(): void
    {
        foreach ($this->tempNames as $name) {
            try {
                Db::delete('DELETE FROM kode_plugins WHERE name = ?', [$name]);
            } catch (\Throwable) {
            }
            PluginMarket::forgetStatusCache($name);
        }
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
        PluginMarket::flush();
        PluginDependency::flush();

        parent::tearDown();
    }

    public function test_uninstall_is_blocked_while_a_dependent_is_enabled(): void
    {
        $this->writePair();

        $res = $this->callUninstall(self::BASE);

        self::assertSame(400, $res['code'], '被依赖方仍被已启用插件引用，卸载必须被挡下');
        self::assertStringContainsString(self::CHILD, $res['msg'], '报错要点名是哪个下游挡的');
        // fail() 的第三参是**信封顶层**合并（与 install/resume 的 `dependencies` 同一口径），不是 errors 子键
        self::assertSame([self::CHILD], $res['raw']['dependents'] ?? null);
        self::assertSame(
            PluginMarket::ENABLED,
            PluginMarket::statusOf(self::BASE),
            '被挡下就不能已经翻状态——状态翻了下游就是悬空的'
        );
    }

    /** 下游不再「已启用」（暂停）后，卸载该放行：门禁只挡真会当场失效的那批。 */
    public function test_uninstall_proceeds_once_dependent_is_paused(): void
    {
        $this->writePair();
        PluginMarket::setStatus(self::CHILD, PluginMarket::PAUSED);

        $res = $this->callUninstall(self::BASE);

        self::assertSame(200, $res['code'], '下游已暂停，不该再拦：' . $res['msg']);
        self::assertSame(PluginMarket::UNINSTALLED, PluginMarket::statusOf(self::BASE));
    }

    /** 无下游依赖的被依赖方（普通插件）绝不能被这道闸误伤。 */
    public function test_plugin_without_dependents_uninstalls_normally(): void
    {
        $this->writePair();
        PluginMarket::setStatus(self::BASE, PluginMarket::ENABLED);

        $res = $this->callUninstall(self::CHILD);

        self::assertSame(200, $res['code'], 'child 没有人依赖它，卸载不该被拦：' . $res['msg']);
    }

    /**
     * 批量卸载：同批提交 [依赖方, 被依赖方] 时互不阻塞，且顺序无关；
     * 只提交被依赖方时必须 blocked，且状态不动。
     */
    public function test_batch_uninstall_exempts_names_in_the_same_batch(): void
    {
        $this->writePair();

        $only = $this->callBatch([self::BASE]);
        self::assertSame([], $only['uninstalled'] ?? [], '下游还在启用，单独卸被依赖方不能算成功');
        self::assertSame([self::BASE], array_column($only['blocked'] ?? [], 'name'));
        self::assertSame(PluginMarket::ENABLED, PluginMarket::statusOf(self::BASE));

        // 正序
        $forward = $this->callBatch([self::CHILD, self::BASE]);
        self::assertSame([self::CHILD, self::BASE], $forward['uninstalled'] ?? []);
        self::assertSame([], $forward['blocked'] ?? []);

        // 反序：同一条批，结果必须一样（顺序不该决定成败）
        PluginMarket::setStatus(self::CHILD, PluginMarket::ENABLED);
        PluginMarket::setStatus(self::BASE, PluginMarket::ENABLED);
        $reverse = $this->callBatch([self::BASE, self::CHILD]);
        self::assertSame([self::BASE, self::CHILD], $reverse['uninstalled'] ?? []);
        self::assertSame([], $reverse['blocked'] ?? []);
    }

    /**
     * 豁免只对**本批真能卸掉**的名字成立；卸不掉的那些要如实报出全部存活阻塞方。
     *
     * 场景：批 = [child, base]，批外还有个已启用的 leaf 依赖 child。
     * child 被 leaf 卡住 ⇒ child 留在启用态 ⇒ 它也就继续挡着 base。
     * 「一条都不落」这一半靠 PluginDependency::dependents() 给的是**传递闭包**才成立
     * （base 的下游里除了 child 还有 leaf，批内豁免盖不住它）；
     * 不动点剔除保证报出来的阻塞方是「批处理完后真正还活着的那些」，
     * 而不是「leaf」这种只看单轮、让人以为处理完 leaf 就能卸 base 的半截原因。
     */
    public function test_batch_uninstall_never_releases_a_partial_batch(): void
    {
        $this->writePair();
        $this->writeTempPlugin(self::LEAF, "['" . self::CHILD . "']");
        PluginMarket::setStatus(self::LEAF, PluginMarket::ENABLED);
        PluginDependency::flush();
        PluginMarket::flush();

        $res = $this->callBatch([self::CHILD, self::BASE]);

        self::assertSame([], $res['uninstalled'] ?? [], '一条都卸不掉时不能留下半批');
        self::assertSame([self::CHILD, self::BASE], array_column($res['blocked'] ?? [], 'name'));
        self::assertSame(
            [self::LEAF],
            $res['blocked'][0]['dependents'],
            'child 的阻塞方是批外的 leaf'
        );
        $baseBlockers = (array) ($res['blocked'][1]['dependents'] ?? []);
        self::assertContains(self::CHILD, $baseBlockers, 'child 仍在启用，它就是 base 的阻塞方之一');
        self::assertContains(self::LEAF, $baseBlockers, '传递闭包里的下游同样还活着');
        self::assertSame(PluginMarket::ENABLED, PluginMarket::statusOf(self::BASE));
        self::assertSame(PluginMarket::ENABLED, PluginMarket::statusOf(self::CHILD));
    }

    /** 上述场景解除后（leaf 一并入批）三条必须都能成批卸掉，顺序无关。 */
    public function test_batch_uninstall_succeeds_once_the_whole_chain_is_included(): void
    {
        $this->writePair();
        $this->writeTempPlugin(self::LEAF, "['" . self::CHILD . "']");
        PluginMarket::setStatus(self::LEAF, PluginMarket::ENABLED);
        PluginDependency::flush();
        PluginMarket::flush();

        $res = $this->callBatch([self::BASE, self::CHILD, self::LEAF]);

        self::assertSame([], $res['blocked'] ?? [], '整条链都在批内，不该再拦任何人');
        self::assertSame([self::BASE, self::CHILD, self::LEAF], $res['uninstalled'] ?? []);
    }

    // ─── 夹具 ──────────────────────────────────────────────────────

    /** 写一对插件：child `requires()` base，两者都置为 enabled。 */
    private function writePair(): void
    {
        $this->writeTempPlugin(self::BASE, '[]');
        $this->writeTempPlugin(self::CHILD, "['" . self::BASE . "']");
        PluginMarket::setStatus(self::BASE, PluginMarket::ENABLED);
        PluginMarket::setStatus(self::CHILD, PluginMarket::ENABLED);
        PluginDependency::flush();
        PluginMarket::flush();

        self::assertSame(
            [self::CHILD],
            PluginDependency::dependents(self::BASE),
            '夹具没搭起来：依赖图里查不到下游，后面的断言全是空转'
        );
    }

    private function writeTempPlugin(string $pluginName, string $requiresBody): void
    {
        $className = $pluginName . 'Plugin';
        $path = dirname(__DIR__) . '/plugins/' . $className . '.php';
        $code = <<<PHP
<?php
declare(strict_types=1);

namespace Kode\\Plugins;

use Kode\\Framework\\Plugin\\PluginInterface;
use Kode\\Framework\\Plugin\\PluginManager;

final class {$className} implements PluginInterface
{
    public function name(): string { return '{$pluginName}'; }
    public function register(PluginManager \$manager): void {}
    public function boot(PluginManager \$manager): void {}
    public function version(): string { return '1.0.0'; }
    public function requires(): array { return {$requiresBody}; }
}
PHP;
        file_put_contents($path, $code);
        $this->tempFiles[] = $path;
    }

    /**
     * 直接调控制器（绕开中间件），拿归一化信封。
     *
     * @return array{code: int, msg: string, data: array<string, mixed>, raw: array<string, mixed>}
     */
    private function callUninstall(string $name): array
    {
        $request = (new ServerRequest('POST', '/api/plugins/' . $name . '/uninstall?confirm=1'))
            ->withAttribute('_route_params', ['name' => $name])
            ->withQueryParams(['confirm' => '1']);
        HttpRequest::setRequest($request);

        return $this->envelope(resolve(PluginController::class)->uninstall());
    }

    /**
     * @param list<string> $names
     * @return array<string, mixed>
     */
    private function callBatch(array $names): array
    {
        $request = (new ServerRequest('POST', '/api/plugins/batch/uninstall'))
            ->withParsedBody(['names' => $names, 'confirm' => true]);
        HttpRequest::setRequest($request);

        $res = $this->envelope(resolve(PluginController::class)->batchUninstall());

        return (array) ($res['data'] + ['blocked' => [], 'uninstalled' => []]);
    }

    /**
     * 拆信封：`{code,msg,dur,data?,…}`——fail() 的 extra 是顶层键，所以整包（raw）也要留着。
     *
     * @return array{code: int, msg: string, data: array<string, mixed>, raw: array<string, mixed>}
     */
    private function envelope(object $response): array
    {
        $decoded = (array) json_decode((string) $response->getBody(), true);

        return [
            'code' => (int) ($decoded['code'] ?? $response->getStatusCode()),
            'msg'  => (string) ($decoded['msg'] ?? ''),
            'data' => (array) ($decoded['data'] ?? []),
            'raw'  => $decoded,
        ];
    }
}
