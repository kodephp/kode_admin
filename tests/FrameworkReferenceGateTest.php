<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\SystemController;
use app\support\PluginRuntimeDetail;
use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;

/**
 * 「引用了根本不存在的类」回归。
 *
 * 这一族缺陷的共同点：写错的类名只会抛出 Error，而 Error 也是 \Throwable，
 * 于是外层 `catch (\Throwable) { return []; }`（或记 'disconnected'）把它伪装成
 * 「结果为空 / 服务不可用」，测试与页面都看不出异常。修之前实测到的症状：
 *  - SystemController::health() 的数据库探测走 `\Kode\Framework\Database\Db::query()`
 *    → 恒 disconnected；缓存探测走 `\Kode\Framework\Cache\Cache::driver()` → 恒 disconnected；
 *  - 插件路由详情 `resolve(\Kode\Http\AppData::class)` → 恒空（实际是 Kode\Http\App）；
 *  - 登录/建用户派发的 `\Kode\Event\Facades\Event::dispatch()`（Kode\Event 没有 Facades 目录）
 *    → WalletPlugin 在 events()/boot() 里声明的 user.created / user.login.success
 *    监听器永远收不到事件，「新用户自动开户」这条已对外声明的能力实际不存在。
 */
final class FrameworkReferenceGateTest extends TestCase
{
    protected bool $independentApp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        HttpRequest::setRequest(new ServerRequest('GET', '/api/system/health'));
    }

    protected function tearDown(): void
    {
        HttpRequest::clear();

        parent::tearDown();
    }

    /** 健康检查不再把「类不存在」当成「服务挂了」。 */
    public function test_database_health_probe_reports_connected(): void
    {
        $body = $this->healthBody();
        $this->assertSame('connected', $body['database']['status'], '数据库探测恒失败过：类名写错');
    }

    /**
     * redis 探测按真实情况回答：可达 connected，扩展未装/服务未起 unavailable，
     * 唯独不该是类不存在伪装出来的 disconnected/unknown。
     */
    public function test_redis_health_probe_does_not_lie(): void
    {
        $status = $this->healthBody()['redis']['status'];
        $this->assertContains($status, ['connected', 'unavailable']);

        $reachable = extension_loaded('redis')
            && ($socket = @fsockopen('127.0.0.1', 6379, $errno, $errstr, 0.5)) !== false;
        if ($reachable) {
            fclose($socket);
            $this->assertSame('connected', $status, '本机 redis 在线，探测必须报 connected');
        }
    }

    /** 插件路由详情能取到路由（曾因 resolve 不存在的类恒返回 []）。 */
    public function test_plugin_routes_are_resolved(): void
    {
        $routes = PluginRuntimeDetail::detail('wallet')['routes'] ?? [];
        $this->assertNotEmpty($routes, 'wallet 插件已注册 9 条路由，详情不该为空');
        $paths = array_column($routes, 'path');
        $this->assertContains('/api/wallet/transfer', $paths);
    }

    /** 事件按插件声明的名字确实有监听器，且派发到监听器的是 Event 对象。 */
    public function test_user_events_reach_plugin_listeners(): void
    {
        $dispatcher = resolve(Dispatcher::class);
        $this->assertTrue($dispatcher->hasListeners('user.created'), '插件声明的监听器未注册');
        $this->assertTrue($dispatcher->hasListeners('user.login.success'));

        $seen = null;
        $dispatcher->listen('gate.probe', static function (Event $event) use (&$seen): void {
            $seen = $event->get('user_id');
        });
        $dispatcher->dispatch('gate.probe', ['user_id' => 7]);
        $this->assertSame(7, $seen, 'dispatch(name, payload) 不是插件监听器消费的调用形态');
    }

    /**
     * 静态门禁：全仓（app/ + plugins/）不许再出现「根本不存在的 Kode 类」引用。
     *
     * 只扫 token，不扫文本：docblock 里写「原本用的是 Kode\X」不会被误报。
     * namespace 声明（`namespace Kode\Plugins\wallet;`）是命名空间不是类，跳过。
     */
    public function test_no_reference_to_a_nonexistent_kode_class(): void
    {
        $files = [];
        foreach (['app', 'plugins'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    dirname(__DIR__) . '/' . $dir,
                    \FilesystemIterator::SKIP_DOTS
                )
            );
            foreach ($iterator as $file) {
                if (strtolower($file->getExtension()) === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        // 门禁自身必须扫到文件，否则空目录也能「通过」
        $this->assertGreaterThan(50, count($files));

        $missing = [];
        foreach ($files as $path) {
            $isNamespaceDeclaration = false;
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (is_array($token) && $token[0] === T_NAMESPACE) {
                    $isNamespaceDeclaration = true;
                    continue;
                }
                if (!is_array($token)
                    || !in_array($token[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                    continue;
                }
                $name = ltrim((string) $token[1], '\\');
                if ($isNamespaceDeclaration) {
                    $isNamespaceDeclaration = false;
                    continue;
                }
                if (!str_starts_with($name, 'Kode\\')) {
                    continue;
                }
                if (!class_exists($name) && !interface_exists($name)
                    && !trait_exists($name) && !enum_exists($name)) {
                    $missing[] = basename($path) . ': ' . $name;
                }
            }
        }

        $this->assertSame([], $missing, '引用了不存在的 Kode 类（会被 catch(\\Throwable) 静默吞掉）');
    }

    /** @return array<string, mixed> */
    private function healthBody(): array
    {
        $response = (new SystemController())->health();
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = json_decode((string) $response->getBody(), true)['data'] ?? [];
        $this->assertArrayHasKey('database', (array) $data, 'health 信封缺 data.database');

        return (array) $data;
    }
}
