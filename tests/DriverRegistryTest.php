<?php

declare(strict_types=1);

namespace app\tests;

use app\support\DriverRegistry;
use app\support\PluginContract;
use app\support\PluginMarket;
use PHPUnit\Framework\TestCase;

/**
 * 驱动注册表测试（`drivers()` 的消费端闭环）。
 *
 * 覆盖五件事：
 *  1. 已启用插件声明的驱动进入注册表（storage_driver → local）；
 *  2. 只读接口语义——has / allowed（排序）/ sourceOf（来源插件名）；
 *  3. 启停即时生效——paused 插件的驱动下线，恢复后回归（缓存必须被清）；
 *  4. 非法驱动名静默丢弃，不拖垮整个注册表；
 *  5. 跨插件同名驱动冲突判漂移（注册表只保留先发现者，后声明者会静默失效）。
 *
 * 隔离性：第 3、5 组会写 kode_plugins 状态行或用临时插件文件，
 * tearDown 精确还原/删除，并 flush 两个引擎的静态缓存，不留脏状态。
 */
final class DriverRegistryTest extends TestCase
{
    /** @var list<string> 本测试创建的临时插件文件 */
    private array $tempFiles = [];

    /** @var list<string> 本测试改过状态的插件名（tearDown 还原） */
    private array $touched = [];

    protected function setUp(): void
    {
        \Kode\Framework\Application::make(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->touched as $name) {
            PluginMarket::setStatus($name, PluginMarket::ENABLED);
        }
        $this->touched = [];
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
        PluginMarket::flush();
        DriverRegistry::flush();
    }

    public function test_enabled_plugin_drivers_are_registered(): void
    {
        DriverRegistry::flush();
        $available = DriverRegistry::available();

        $this->assertArrayHasKey('local', $available, 'storage_driver 插件声明的 local 必须进注册表');
        $this->assertSame('storage_driver', $available['local'], '来源必须指向声明它的插件');
    }

    public function test_readonly_interfaces(): void
    {
        $this->assertTrue(DriverRegistry::has('local'));
        $this->assertTrue(DriverRegistry::has(' LOCAL '), 'has() 必须容忍大小写与空白');
        $this->assertFalse(DriverRegistry::has('oss'), '未声明的驱动不得可用');

        $allowed = DriverRegistry::allowed();
        $this->assertContains('local', $allowed);
        $sorted = $allowed;
        sort($sorted);
        $this->assertSame($sorted, $allowed, 'allowed() 必须按字典序返回（前后端共用一份 SoT）');

        $this->assertSame('storage_driver', DriverRegistry::sourceOf('local'));
        $this->assertNull(DriverRegistry::sourceOf('oss'));
    }

    public function test_paused_plugin_driver_drops_out_immediately(): void
    {
        // 临时改 storage_driver 状态，tearDown 会还原。
        PluginMarket::setStatus('storage_driver', PluginMarket::PAUSED);
        $this->touched[] = 'storage_driver';

        $this->assertFalse(
            DriverRegistry::has('local'),
            '插件暂停后其声明的驱动必须立即下线（否则存储页还能新建已暂停插件的驱动）'
        );
        $this->assertNull(DriverRegistry::sourceOf('local'));

        PluginMarket::setStatus('storage_driver', PluginMarket::ENABLED);
        $this->assertTrue(DriverRegistry::has('local'), '恢复启用后驱动必须回归');
    }

    public function test_uninstalled_plugin_driver_drops_out(): void
    {
        PluginMarket::setStatus('storage_driver', PluginMarket::UNINSTALLED);
        $this->touched[] = 'storage_driver';

        $this->assertFalse(DriverRegistry::has('local'));
    }

    public function test_illegal_driver_names_are_dropped_not_fatal(): void
    {
        $this->writeTempPlugin('drv_probe_bad', "return ['BAD-NAME', ' oss ', 42, 'a', 's3'];");
        DriverRegistry::flush();

        $this->assertFalse(DriverRegistry::has('BAD-NAME'), '大写开头不合法，必须丢弃');
        $this->assertFalse(DriverRegistry::has('42'), '非字符串必须丢弃');
        $this->assertFalse(DriverRegistry::has('a'), '长度不足 2 必须丢弃');
        $this->assertTrue(DriverRegistry::has('s3'), '合法驱动必须正常注册');
    }

    /** 跨插件同名驱动：注册表保留先发现者，审计必须判漂移（不能静默失效）。 */
    public function test_duplicate_driver_across_plugins_is_drift(): void
    {
        $this->writeTempPlugin('drv_probe_a', "return ['local'];");
        $this->writeTempPlugin('drv_probe_b', "return ['local'];");
        PluginMarket::flush();
        DriverRegistry::flush();

        // 两个临时插件 + 现有 storage_driver 都声明 local：注册表只留一个。
        $available = DriverRegistry::available();
        $this->assertArrayHasKey('local', $available);
        $this->assertLessThanOrEqual(1, count($available));

        $driftA = implode("\n", PluginContract::audit(new \Kode\Plugins\DrvProbeAPlugin())['drift']);
        $this->assertStringContainsString('冲突', $driftA, '同名驱动必须判漂移');
        $this->assertStringContainsString('local', $driftA);
    }

    /** 无冲突时不得误报（防审计噪声）。 */
    public function test_distinct_drivers_produce_no_conflict_drift(): void
    {
        $this->writeTempPlugin('drv_probe_c', "return ['cos'];");
        $this->writeTempPlugin('drv_probe_d', "return ['s3'];");
        PluginMarket::flush();
        DriverRegistry::flush();

        $this->assertSame([], PluginContract::audit(new \Kode\Plugins\DrvProbeCPlugin())['drift'],
            '声明互不冲突的驱动不得报漂移');
    }

    /**
     * 写一个临时插件文件到 plugins/。
     *
     * 类名与文件名必须一致（PSR-4）；插件名由 name() 返回，与文件名无关。
     */
    private function writeTempPlugin(string $pluginName, string $driversBody): string
    {
        $className = $this->camel($pluginName) . 'Plugin';
        $path = dirname(__DIR__) . '/plugins/' . $className . '.php';
        $code = <<<PHP
<?php

declare(strict_types=1);

namespace Kode\\Plugins;

use Kode\\Framework\\Plugin\\PluginInterface;
use Kode\\Framework\\Plugin\\PluginManager;

final class {$className} implements PluginInterface
{
    public function name(): string
    {
        return '{$pluginName}';
    }

    public function configSchema(): array
    {
        return ['k' => ['label' => 'K', 'type' => 'string']];
    }

    public function drivers(): array
    {
        {$driversBody}
    }

    public function register(PluginManager \$m): void
    {
    }

    public function boot(PluginManager \$m): void
    {
    }
}
PHP;
        $this->tempFiles[] = $path;
        file_put_contents($path, $code);

        return $path;
    }

    /** `drv_probe_c` → `DrvProbeC`（用于拼类名）。 */
    private function camel(string $pluginName): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $pluginName)));
    }
}
