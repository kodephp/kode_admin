<?php

declare(strict_types=1);

namespace app\tests;

use app\support\PluginContract;
use app\support\PluginMarket;
use app\support\PluginPackager;
use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;
use Kode\Framework\Testing\TestCase;

/**
 * 插件能力契约测试：把「声明⟺实现零漂移」固化为回归基线。
 *
 * 三件事必须始终成立：
 *  1. 仓库自带插件全部 audit ok（drift 恒为空）——否则市场页会亮红灯，发版前必须清零；
 *  2. 审计是只读的——生命周期方法 install/uninstall/upgrade 绝不在审计中被执行
 *     （它们有建表/删表副作用，一次只读审计不能意外建出表）；
 *  3. 「未声明」与「声明了但执行失败」必须可区分——失败归 pending/errors，
 *     绝不能被静默当成空值判成漂移（那是误报）。
 */
final class PluginContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    public function test_all_shipped_plugins_pass_zero_drift_audit(): void
    {
        $discovered = PluginMarket::discover();
        $this->assertNotEmpty($discovered, '仓库至少应自带一个插件');

        $failed = [];
        foreach ($discovered as $name => $class) {
            $audit = PluginContract::audit(new $class());
            if (!$audit['ok']) {
                $failed[$name] = $audit['drift'];
            }
        }

        $this->assertSame(
            [],
            $failed,
            '存在声明⟺实现漂移的插件：' . json_encode($failed, JSON_UNESCAPED_UNICODE)
        );
    }

    public function test_plugins_live_under_root_plugins_dir(): void
    {
        // 插件目录在框架根目录 plugins/ 下（不在 app/ 内），app/ 回归纯应用代码。
        $this->assertTrue(is_dir(PluginMarket::baseDir()));
        $this->assertStringNotContainsString('app/plugins', rtrim(PluginMarket::baseDir(), DIRECTORY_SEPARATOR));

        foreach (PluginMarket::discover() as $name => $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            $this->assertStringStartsWith(
                rtrim(PluginMarket::baseDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
                $file,
                "插件 {$name} 必须位于 plugins/ 下：{$file}"
            );
            $this->assertStringStartsWith('Kode\\Plugins\\', $class, "插件 {$name} 命名空间须为 Kode\\Plugins\\");
        }
    }

    public function test_describe_shape_is_stable(): void
    {
        $plugin = new \Kode\Plugins\NoticePlugin();
        $desc = PluginContract::describe($plugin);

        foreach (['name', 'class', 'version', 'capabilities', 'groups', 'tables', 'menus',
            'commands', 'events', 'crons',
            'permissions', 'drivers', 'requires', 'config_schema', 'meta', 'errors'] as $key) {
            $this->assertArrayHasKey($key, $desc, "describe() 缺少必备键：{$key}");
        }

        // capabilities 按 schema 顺序稳定输出，同一插件多次调用必须一致。
        $this->assertSame(PluginContract::describe($plugin)['capabilities'], $desc['capabilities']);
        // notices 声明了菜单权限码，permissions() 必须齐（否则审计会报漂移）。
        $this->assertContains('notices:view', $desc['permissions']);
    }

    public function test_audit_is_readonly_and_never_calls_lifecycle(): void
    {
        $plugin = new LifecycleSideEffectPlugin();
        $audit = PluginContract::audit($plugin);

        $this->assertTrue($audit['ok'], '生命周期方法抛异常不应记为漂移');
        $this->assertSame([], $audit['drift']);
        $this->assertSame([], $audit['pending'], 'pending 应恒为空 => 生命周期方法未被审计调用');
    }

    public function test_required_capability_with_empty_return_is_drift(): void
    {
        $drift = PluginContract::audit(new BrokenPlugin())['drift'];

        $this->assertNotEmpty($drift);
        $this->assertStringContainsString('config_schema', $drift[0], '必需能力返回空必须判为漂移');
    }

    public function test_menu_auth_code_must_be_declared_in_permissions(): void
    {
        $drift = PluginContract::audit(new BrokenPlugin())['drift'];
        $this->assertStringContainsString('permissions', implode("\n", $drift));
    }

    public function test_driver_name_pattern_and_dedup(): void
    {
        $drift = PluginContract::audit(new BrokenPlugin())['drift'];
        $joined = implode("\n", $drift);

        $this->assertStringContainsString('驱动名', $joined);
        $this->assertStringContainsString('重复', $joined);
        $this->assertMatchesRegularExpression(PluginContract::DRIVER_NAME_PATTERN, 'local');
        $this->assertDoesNotMatchRegularExpression(PluginContract::DRIVER_NAME_PATTERN, 'BAD-NAME');
    }

    public function test_missing_dependency_is_pending_not_drift(): void
    {
        $audit = PluginContract::audit(new BrokenPlugin());

        $this->assertNotEmpty($audit['pending']);
        $this->assertStringContainsString('requires', implode("\n", $audit['pending']));
    }

    public function test_failed_capability_is_reported_not_silently_emptied(): void
    {
        // configSchema() 抛异常时，describe() 必须记入 errors 而不是静默返回空。
        $desc = PluginContract::describe(new ThrowingConfigPlugin());

        $this->assertNotEmpty($desc['errors']);
        $this->assertSame([], $desc['config_schema'], '失败时 config_schema 降级为空 map');

        // 且审计把这类失败归为 pending（环境未就绪），而非 drift（声明漂移）。
        $audit = PluginContract::audit(new ThrowingConfigPlugin());
        $this->assertNotEmpty($audit['pending']);
        $this->assertStringContainsString('config_schema', implode("\n", $audit['pending']));
    }

    public function test_packaged_manifest_carrys_contract(): void
    {
        $zip = PluginPackager::pack('notices');
        $this->assertNotNull($zip, 'notices 插件必须可打包');
        try {
            $z = new \ZipArchive();
            $this->assertTrue($z->open($zip) !== false, '打包产物必须是可打开的 zip');
            $manifest = json_decode((string) $z->getFromName('plugin.json'), true);
            $z->close();
        } finally {
            @unlink((string) $zip);
        }

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('contract', $manifest, 'plugin.json 必须随包携带能力契约');
        $this->assertArrayHasKey('capabilities', $manifest['contract']);
        $this->assertSame('notices', $manifest['name']);
    }

    /**
     * 框架 v1.4.0 起 addCron() 已是真实消费方，「声明了却不调用 addCron()」
     * 不再是 pending，而是声明⟺实现漂移（该任务永远不会执行）——必须判 ok=false。
     */
    public function test_declared_crons_not_fulfilled_is_drift(): void
    {
        $audit = PluginContract::audit(new CronOnlyPlugin());

        $this->assertFalse($audit['ok'], '声明了 crons 却未注册进调度器必须判漂移');
        $joined = implode("\n", $audit['drift']);
        $this->assertStringContainsString('crons', $joined);
        $this->assertStringContainsString('未注册进调度器', $joined, '须点明漂移形态');
        $this->assertStringContainsString('addCron', $joined, '须给出兑现路径（PluginManager::addCron()）');
        $this->assertStringContainsString('nightly_sync', $joined, '须点出具体任务名');
    }

    /**
     * 兑现闭环：插件在 register() 里逐条 addCron()（来源 plugin:<name>）后，
     * 与调度器对账通过，crons 阶段不再产生 drift / pending。
     */
    public function test_fulfilled_crons_pass_zero_drift(): void
    {
        $this->registerFulfilledCrons();

        $audit = PluginContract::audit(new CronFulfilledPlugin());

        $this->assertTrue(
            $audit['ok'],
            '已用 addCron() 兑现的 crons 必须审计通过：' . implode(' | ', $audit['drift'])
        );
        $joined = implode("\n", array_merge($audit['drift'], $audit['pending']));
        $this->assertStringNotContainsString('crons', $joined, '兑现后不应再有 crons 相关 drift / pending');
    }

    /** crons() 声明形态校验：缺 name / 名重复 / 名非法，均须判漂移。 */
    public static function invalidCronDeclarationProvider(): array
    {
        return [
            '缺少 name'      => [new CronMalformedPlugin([['expression' => '0 2 * * *']]), '缺少 name'],
            '任务名重复'      => [new CronMalformedPlugin([
                ['name' => 'dup', 'expression' => '0 2 * * *'],
                ['name' => 'dup', 'expression' => '0 3 * * *'],
            ]), '重复声明'],
            '任务名非法字符'  => [new CronMalformedPlugin([
                ['name' => 'BAD NAME!', 'expression' => '0 2 * * *'],
            ]), '非法'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCronDeclarationProvider')]
    public function test_malformed_cron_declaration_is_drift(object $plugin, string $expect): void
    {
        $audit = PluginContract::audit($plugin);

        $this->assertFalse($audit['ok'], '声明形态非法必须判漂移');
        $joined = implode("\n", $audit['drift']);
        $this->assertStringContainsString('crons', $joined);
        $this->assertStringContainsString($expect, $joined);
    }

    /**
     * addCron() 自身是硬门禁：重名注册当场抛错（而不是静默覆盖），
     * 审计侧的「重复声明」判漂移与之保持一致。
     */
    public function test_add_cron_rejects_duplicate_name(): void
    {
        $manager = app(PluginManager::class);
        $manager->addCron('0 2 * * *', static function (): void {}, 'contract.duplicate_probe');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('已被占用');
        $manager->addCron('0 3 * * *', static function (): void {}, 'contract.duplicate_probe');
    }

    /** 未声明 crons() 不得产生任何 cron 相关 pending（防误报）。 */
    public function test_no_crons_declaration_means_no_cron_pending(): void
    {
        $joined = implode("\n", PluginContract::audit(new \Kode\Plugins\NoticePlugin())['pending']);
        $this->assertStringNotContainsString('crons：', $joined);
    }

    /** 声明了空 crons() 也不算声明（不报 pending）。 */
    public function test_empty_crons_is_not_a_declaration(): void
    {
        $joined = implode("\n", PluginContract::audit(new EmptyCronPlugin())['pending']);
        $this->assertStringNotContainsString('crons：', $joined);
    }

    /** plugin.json 的 kode 下限必须随真实框架版本导出，不得停在陈旧的 >=1.0。 */
    public function test_packaged_manifest_declares_real_framework_floor(): void
    {
        $constraint = PluginPackager::frameworkConstraint();
        $this->assertMatchesRegularExpression('/^>=[0-9]+\.[0-9]+$/', $constraint,
            "框架下限应为 主.次 形式，实际：{$constraint}");

        $zip = PluginPackager::pack('notices');
        $this->assertNotNull($zip);
        try {
            $z = new \ZipArchive();
            $this->assertTrue($z->open($zip) !== false);
            $manifest = json_decode((string) $z->getFromName('plugin.json'), true);
            $z->close();
        } finally {
            @unlink((string) $zip);
        }

        $this->assertSame($constraint, $manifest['kode'], 'plugin.json 的 kode 下限必须与真实环境一致');
        $this->assertSame((string) (new \Kode\Plugins\NoticePlugin())->version(), $manifest['version'] ?? '',
            'version 应取插件自身版本，而非写死的默认值');
    }

    /** readManifest() 必须能从 pack() 产出的 zip 里读回清单（installRemote 的校验前置）。 */
    public function test_read_manifest_roundtrip_from_packed_zip(): void
    {
        $zip = PluginPackager::pack('notices');
        $this->assertNotNull($zip, 'notices 必须可打包');
        try {
            $manifest = PluginPackager::readManifest($zip);
        } finally {
            @unlink((string) $zip);
        }

        $this->assertNotEmpty($manifest, 'pack() 产出的包必须含可读的 plugin.json');
        $this->assertSame('notices', $manifest['name']);
        $this->assertSame(PluginPackager::frameworkConstraint(), $manifest['kode']);
        $this->assertSame(
            PluginPackager::verifyManifest($manifest, 'notices')['version'],
            $manifest['version'],
            '自家打包的清单必须通过自己的校验'
        );
    }

    /** 缺 plugin.json 的包必须拒绝：不自述身份就无法防偷名。 */
    public function test_manifest_missing_rejected(): void
    {
        $r = PluginPackager::verifyManifest([], 'notices');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('plugin.json', $r['msg']);
    }

    /** 清单 name 与请求名不一致必须拒绝（防「请求 foo、落盘 BarPlugin.php」的状态错位）。 */
    public function test_manifest_name_mismatch_rejected(): void
    {
        $r = PluginPackager::verifyManifest(['name' => 'other'], 'notices');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('不一致', $r['msg']);
        $this->assertStringContainsString('other', $r['msg']);
    }

    /** 声明的框架下限高于当前版本必须拒绝（声明了兼容性就必须校验）。 */
    public function test_manifest_incompatible_framework_floor_rejected(): void
    {
        $r = PluginPackager::verifyManifest(['name' => 'notices', 'kode' => '>=99.0'], 'notices');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString(PluginPackager::frameworkVersion(), $r['msg']);
    }

    /** 未声明 kode 的清单视为不限框架（宽松兼容旧市场包），但 name 仍必须一致。 */
    public function test_manifest_without_kode_accepted(): void
    {
        $r = PluginPackager::verifyManifest(['name' => 'notices', 'version' => '2.5.1'], 'notices');
        $this->assertTrue($r['ok']);
        $this->assertSame('2.5.1', $r['version']);
    }

    /** 版本去 v 前缀，与 satisfies() 的口径一致。 */
    public function test_manifest_version_v_prefix_stripped(): void
    {
        $r = PluginPackager::verifyManifest(['name' => 'notices', 'version' => 'v3.0.0'], 'notices');
        $this->assertTrue($r['ok']);
        $this->assertSame('3.0.0', $r['version']);
    }

    /**
     * 走真实插件生命周期把 crons() 兑现进调度器。
     *
     * 刻意用「全新 PluginManager + 同一个容器」而非全局单例：单例已在启动期 load() 过
     * config/plugins.php 的插件，再 load() 一次会重跑它们的 boot()；新实例的插件表只含
     * 本夹具，boot() 只作用于它自己。容器是同一个，所以 addCron() 落进的 ScheduleDispatcher
     * 与审计对账读的是同一个单例，来源标签才是真实的 plugin:<name>。
     */
    /**
     * 声明了 commands() 却不调用 addCommand() → drift（命令永远不会被 kode 找到）。
     */
    public function test_declared_commands_not_fulfilled_is_drift(): void
    {
        $audit = PluginContract::audit(new CommandUnfulfilledPlugin());

        $this->assertFalse($audit['ok'], '声明了 commands 却未注册进内核必须判漂移');
        $joined = implode("\n", $audit['drift']);
        $this->assertStringContainsString('commands', $joined);
        $this->assertStringContainsString('未注册进内核', $joined);
        $this->assertStringContainsString('addCommand', $joined);
        $this->assertStringContainsString('CommandUnfulfilledCommand', $joined);
    }

    /**
     * 兑现闭环：插件在 register() 里逐条 addCommand() 后，与内核对账通过。
     */
    public function test_fulfilled_commands_pass_zero_drift(): void
    {
        $this->registerFulfilledCommands();

        $audit = PluginContract::audit(new CommandFulfilledPlugin());

        $this->assertTrue(
            $audit['ok'],
            '已用 addCommand() 兑现的 commands 必须审计通过：' . implode(' | ', $audit['drift'])
        );
        $joined = implode("\n", array_merge($audit['drift'], $audit['pending']));
        $this->assertStringNotContainsString('commands', $joined, '兑现后不应再有 commands 相关 drift / pending');
    }

    /**
     * 声明的类不是 Console\Command 子类 → drift（内核只接受 Command 子类）。
     */
    public function test_command_class_not_subclass_of_command_is_drift(): void
    {
        $audit = PluginContract::audit(new CommandBadTypePlugin());

        $this->assertFalse($audit['ok']);
        $joined = implode("\n", $audit['drift']);
        $this->assertStringContainsString('不是 Console\\Command 子类', $joined);
    }

    /**
     * 声明了 events() 却不调用 addListener() → drift（事件派发不到本插件）。
     */
    public function test_declared_events_not_fulfilled_is_drift(): void
    {
        $audit = PluginContract::audit(new EventUnfulfilledPlugin());

        $this->assertFalse($audit['ok'], '声明了 events 却未注册监听器必须判漂移');
        $joined = implode("\n", $audit['drift']);
        $this->assertStringContainsString('events', $joined);
        $this->assertStringContainsString('未注册监听器', $joined);
        $this->assertStringContainsString('addListener', $joined);
        $this->assertStringContainsString('order.created', $joined);
    }

    /**
     * 兑现闭环：插件在 register() 里逐条 addListener() 后，与派发器对账通过。
     */
    public function test_fulfilled_events_pass_zero_drift(): void
    {
        $this->registerFulfilledEvents();

        $audit = PluginContract::audit(new EventFulfilledPlugin());

        $this->assertTrue(
            $audit['ok'],
            '已用 addListener() 兑现的 events 必须审计通过：' . implode(' | ', $audit['drift'])
        );
        $joined = implode("\n", array_merge($audit['drift'], $audit['pending']));
        $this->assertStringNotContainsString('events', $joined, '兑现后不应再有 events 相关 drift / pending');
    }

    /**
     * 未声明 commands()/events() 不得产生任何相关 pending（防误报）。
     */
    public function test_no_commands_events_declaration_means_no_pending(): void
    {
        $joined = implode("\n", PluginContract::audit(new \Kode\Plugins\NoticePlugin())['pending']);
        $this->assertStringNotContainsString('commands：', $joined);
        $this->assertStringNotContainsString('events：', $joined);
    }

    /**
     * 菜单缺 title / path 不以 / 开头 / 路径重复 / 可选字段类型错误 → drift。
     */
    public function test_menu_bad_shape_is_drift(): void
    {
        $audit = PluginContract::audit(new MenuBadShapePlugin());

        $this->assertFalse($audit['ok'], '菜单形态非法必须判漂移');
        $joined = implode("\n", $audit['drift']);
        $this->assertStringContainsString('menus', $joined);
        $this->assertStringContainsString('缺 title', $joined);
        $this->assertStringContainsString('必须以 / 开头', $joined);
        $this->assertStringContainsString('重复声明', $joined);
        $this->assertStringContainsString('必须是字符串', $joined);
    }

    /**
     * 表名非法（数字开头 / 超长 / 含连字符）或重复 → drift。
     */
    public function test_table_name_format_is_drift(): void
    {
        $audit = PluginContract::audit(new TableBadNamePlugin());

        $this->assertFalse($audit['ok'], '表名非法必须判漂移');
        $joined = implode("\n", $audit['drift']);
        $this->assertStringContainsString('tables', $joined);
        $this->assertStringContainsString('非法', $joined);
        $this->assertStringContainsString('重复', $joined);
    }

    /**
     * NoticePlugin 的菜单形态必须通过（正例：title 非空、path 以 / 开头、字段类型正确）。
     */
    public function test_notice_plugin_menus_pass_shape_audit(): void
    {
        $drift = PluginContract::audit(new \Kode\Plugins\NoticePlugin())['drift'];
        $joined = implode("\n", $drift);
        $this->assertStringNotContainsString('menus：', $joined,
            'NoticePlugin 的菜单形态不应产生 drift：' . $joined);
    }

    /**
     * NoticePlugin 的表名必须通过格式校验（正例：字母开头、合法字符、长度合规）。
     */
    public function test_notice_plugin_tables_pass_format_audit(): void
    {
        $drift = PluginContract::audit(new \Kode\Plugins\NoticePlugin())['drift'];
        $joined = implode("\n", $drift);
        $this->assertStringNotContainsString('tables：', $joined,
            'NoticePlugin 的表名格式不应产生 drift：' . $joined);
    }

    /**
     * version() 返回值非法 → drift（阶段 1.6）。
     *
     * 该值会写入 plugin.json 与 kode_plugins.version，再作为 satisfies() 的
     * $installed 参与依赖门禁：非法值不报错，只会让所有依赖方落精确匹配兜底
     * 被永久误拒——市场页还能显示、安装还能进行，直到依赖门禁静默拒绝。
     */
    public static function invalidVersionProvider(): array
    {
        return [
            '返回空串'  => [new VersionMalformedPlugin(''), '返回空'],
            '返回空白'  => [new VersionMalformedPlugin('   '), '返回空'],
            '纯文本'    => [new VersionMalformedPlugin('abc'), '非法'],
            '通配 x'    => [new VersionMalformedPlugin('1.0.x'), '非法'],
            '频道标记'  => [new VersionMalformedPlugin('@stable'), '非法'],
            '缺版本号'  => [new VersionMalformedPlugin('1..2'), '非法'],
            '五段超限'  => [new VersionMalformedPlugin('1.0.0.0.0'), '非法'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidVersionProvider')]
    public function test_bad_version_format_is_drift(object $plugin, string $expect): void
    {
        $audit = PluginContract::audit($plugin);

        $this->assertFalse($audit['ok'], 'version() 格式非法必须判漂移');
        $joined = implode("\n", $audit['drift']);
        $this->assertStringContainsString('version：', $joined);
        $this->assertStringContainsString($expect, $joined, "须点明漂移形态「{$expect}」");
    }

    /** version() 合法（v 前缀 / 预发布 / 构建元数据 / 1-4 段）不得判漂移。 */
    public static function validVersionProvider(): array
    {
        return [
            '标准三段'   => ['1.0.0'],
            'v 前缀'     => ['v2.3.4'],
            '单段'       => ['1'],
            '两段'       => ['1.2'],
            '四段'       => ['1.2.3.4'],
            '预发布'     => ['1.0.0-beta.1'],
            '构建元数据' => ['2.0.0+build.7'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validVersionProvider')]
    public function test_valid_version_passes_format_audit(string $version): void
    {
        $drift = PluginContract::audit(new VersionMalformedPlugin($version))['drift'];
        $this->assertStringNotContainsString('version：', implode("\n", $drift),
            "version {$version} 不应产生 drift：" . implode(' | ', $drift));
    }

    /**
     * requires() 的版本约束无法被 satisfies() 解析 → drift（阶段 4.5 扩展）。
     *
     * 典型：Composer 惯用的区间写法 ">=1.0, <2.0"——引擎只取逗号前半段，
     * 声明的 <2.0 上界被静默丢弃（实测 2.5.0 可通过）。
     */
    public static function invalidRequiresProvider(): array
    {
        return [
            '区间逗号写法（上界被丢弃）' => [
                [['name' => 'notices', 'version' => '>=1.0, <2.0']],
                ['>=1.0, <2.0', '上界', '支持：'],
            ],
            '通配 x 被精确匹配误拒' => [
                [['name' => 'notices', 'version' => '1.0.x']],
                ['1.0.x', '误拒', '支持：'],
            ],
            '频道标记被精确匹配误拒' => [
                [['name' => 'notices', 'version' => '@stable']],
                ['@stable', '误拒', '支持：'],
            ],
            '通配星号被精确匹配误拒' => [
                [['name' => 'notices', 'version' => '1.0.*']],
                ['1.0.*', '误拒', '支持：'],
            ],
            '箭头运算符拼写错误' => [
                [['name' => 'notices', 'version' => '=>1.0']],
                ['=>1.0', '非法', '支持：'],
            ],
            'version 键类型错误' => [
                [['name' => 'notices', 'version' => ['1.0', '2.0']]],
                ['version 必须是字符串或 null', 'array'],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRequiresProvider')]
    public function test_bad_requires_constraint_is_drift(array $requires, array $expect): void
    {
        $audit = PluginContract::audit(new RequiresMalformedPlugin($requires));

        $this->assertFalse($audit['ok'], 'requires() 声明非法必须判漂移');
        $joined = implode("\n", $audit['drift']);
        foreach ($expect as $needle) {
            $this->assertStringContainsString($needle, $joined,
                "drift 应包含「{$needle}」，实际：" . $joined);
        }
    }

    /** 引擎真正支持的约束形态不得判漂移（防审计比引擎更严）。 */
    public static function validConstraintProvider(): array
    {
        return [
            '任意通配'   => ['*'],
            '大于等于'   => ['>=1.0'],
            '小于等于'   => ['<=2.0'],
            '大于'       => ['>1.0'],
            '小于'       => ['<2.0'],
            '双等'       => ['==1.2.3'],
            '不等'       => ['!=1.2.3'],
            '单等'       => ['=1.0'],
            'caret'      => ['^1.0'],
            'tilde'      => ['~1.2'],
            '精确匹配'   => ['1.2.3'],
            'v 前缀'     => ['v1.2.3'],
            '比较带空格' => ['>= 1.0'],
            'caret 空格' => ['^ 1.0'],
            'null 无约束' => [null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validConstraintProvider')]
    public function test_valid_requires_constraints_pass_audit(string|null $constraint): void
    {
        $drift = PluginContract::auditRequires(new RequiresMalformedPlugin(
            [['name' => 'notices', 'version' => $constraint]]
        ));
        $this->assertSame([], $drift,
            "约束「{$constraint}」合法，不应产生 drift：" . implode(' | ', $drift));
    }

    /**
     * 自带插件的 version() 必须通过格式校验（正例，阶段 1.6 不误报回归）。
     * 注意：version() 未声明的插件不判漂移（describe() 回落 1.0.0 是既有语义）。
     */
    public function test_shipped_plugins_versions_pass_format_audit(): void
    {
        foreach (PluginMarket::discover() as $class) {
            $drift = PluginContract::audit(new $class())['drift'];
            $joined = implode("\n", $drift);
            $this->assertStringNotContainsString('version：', $joined,
                $class . ' 的 version() 不应产生 drift：' . $joined);
        }
    }

    /** 未声明 version() 不应产生 version 相关 drift（回落默认值是既有契约）。 */
    public function test_missing_version_method_is_not_drift(): void
    {
        $joined = implode("\n", PluginContract::audit(new \Kode\Plugins\DemoPlugin())['drift']);
        $this->assertStringNotContainsString('version：', $joined,
            '未声明 version() 不是漂移：' . $joined);
    }

    private function registerFulfilledCrons(): void
    {
        $manager = new PluginManager(resolve(\Kode\DI\Container::class));
        $manager->load([CronFulfilledPlugin::class]);

        $source = 'plugin:cron_fulfilled';
        $registered = [];
        foreach (resolve(\Kode\Framework\Scheduling\ScheduleDispatcher::class)->registered() as $task) {
            if ($task->source === $source) {
                $registered[] = $task->name;
            }
        }
        $this->assertSame(
            ['cron_fulfilled.nightly', 'cron_fulfilled.hourly'],
            $registered,
            '插件 load() 后 crons() 声明的两条任务必须真实进调度器，且来源标记为 ' . $source
        );
    }

    /** 兑现命令：用全新 PluginManager 驱动真实插件 load()，拿到注册进内核的命令类。 */
    private function registerFulfilledCommands(): void
    {
        $manager = new PluginManager(resolve(\Kode\DI\Container::class));
        $manager->load([CommandFulfilledPlugin::class]);

        $kernel = resolve(\Kode\Console\Kernel::class);
        $this->assertTrue($kernel->has('plugin_cmd'), '插件 load() 后命令必须注册进内核');
        $this->assertInstanceOf(CommandFulfilledCommand::class, $kernel->resolve('plugin_cmd'));
    }

    /** 兑现事件：用全新 PluginManager 驱动真实插件 load()，拿到注册进派发器的监听器。 */
    private function registerFulfilledEvents(): void
    {
        $manager = new PluginManager(resolve(\Kode\DI\Container::class));
        $manager->load([EventFulfilledPlugin::class]);

        $dispatcher = resolve(\Kode\Event\Dispatcher::class);
        $this->assertTrue($dispatcher->hasListeners('order.created'), '插件 load() 后事件监听器必须注册');
    }
}

/** 生命周期方法全部抛异常：验证审计绝不执行它们（只读保证）。 */
final class LifecycleSideEffectPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'side_effect';
    }

    public function install(): void
    {
        throw new \RuntimeException('install() must never be called by audit');
    }

    public function uninstall(bool $purge = false): void
    {
        throw new \RuntimeException('uninstall() must never be called by audit');
    }

    public function upgrade(string $from, string $to): void
    {
        throw new \RuntimeException('upgrade() must never be called by audit');
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}

/** 故意写坏：覆盖必需能力空实现 / 菜单权限码未声明 / 驱动名非法且重复 / 依赖缺失。 */
final class BrokenPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'broken';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function menus(): array
    {
        return [['title' => '坏菜单', 'path' => '/broken', 'icon' => 'x', 'auth_code' => 'broken:view']];
    }

    public function permissions(): array
    {
        return ['broken:other'];
    }

    public function drivers(): array
    {
        return ['BAD-NAME', 'local', 'local'];
    }

    public function requires(): array
    {
        return ['no_such_plugin'];
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}

/** configSchema() 抛异常：验证失败被上报，而非静默降为空后误判漂移。 */
final class ThrowingConfigPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'throwing_config';
    }

    public function configSchema(): array
    {
        throw new \RuntimeException('simulated env failure');
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}

/** 声明了 crons() 却在 register()/boot() 里不调 addCron()：审计须判漂移（声明了永不执行）。 */
final class CronOnlyPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'cron_only';
    }

    public function crons(): array
    {
        return [
            ['name' => 'nightly_sync', 'expression' => '0 2 * * *'],
            ['name' => 'hourly_report', 'expression' => '0 * * * *'],
        ];
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}

/** 声明了空 crons()：空声明不算声明，不应产生 pending。 */
final class EmptyCronPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'empty_cron';
    }

    public function crons(): array
    {
        return [];
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}

/**
 * 声明了 crons() 且真实兑现：register() 里逐条 addCron()（覆盖 closure 与 [类, 方法] 两种处理器形态），
 * 验证审计能从 drift 翻回 ok，证明 4.6 阶段不是只报不修的单向门禁。
 */
final class CronFulfilledPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'cron_fulfilled';
    }

    public function crons(): array
    {
        return [
            ['name' => 'cron_fulfilled.nightly', 'expression' => '0 2 * * *', 'description' => '夜间全量同步'],
            ['name' => 'cron_fulfilled.hourly', 'expression' => '0 * * * *', 'description' => '每小时报表'],
        ];
    }

    public function register(PluginManager $m): void
    {
        $m->addCron('0 2 * * *', static function (): void {}, 'cron_fulfilled.nightly', '夜间全量同步');
        $m->addCron('0 * * * *', [CronFulfilledJob::class, 'run'], 'cron_fulfilled.hourly', '每小时报表');
    }

    public function boot(PluginManager $m): void
    {
    }
}

/** CronFulfilledPlugin 的元组处理器目标：验证 [类, 方法] 形态能穿过 addCron() 注册。 */
final class CronFulfilledJob
{
    public function run(): void
    {
    }
}

/**
 * crons() 声明形态异常（缺 name / 名重复 / 名非法）：构造器注入声明列表，
 * 供 invalidCronDeclarationProvider 复用同一个夹具类。
 */
final class CronMalformedPlugin implements PluginInterface
{
    /** @param array<int, mixed> $crons */
    public function __construct(private array $crons = [])
    {
    }

    public function name(): string
    {
        return 'cron_malformed';
    }

    public function crons(): array
    {
        return $this->crons;
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}

/** 测试用命令类（#[AsCommand] 声明式）。 */
#[\Kode\Console\Attribute\AsCommand(name: 'plugin_cmd', description: '插件命令', usage: 'plugin-cmd')]
final class CommandFulfilledCommand extends \Kode\Framework\Console\Command
{
    protected function handle(): int
    {
        return 0;
    }
}

/**
 * 声明了 commands() 但 register() 里未调 addCommand()：
 * 验证「声明了却未兑现」判漂移。
 */
final class CommandUnfulfilledPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'cmd_unfulfilled';
    }

    public function commands(): array
    {
        return [CommandUnfulfilledCommand::class];
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}

/** CommandUnfulfilledPlugin 的声明目标命令类（内核未注册）。 */
#[\Kode\Console\Attribute\AsCommand(name: 'unfulfilled_cmd', description: '未兑现命令', usage: 'unfulfilled-cmd')]
final class CommandUnfulfilledCommand extends \Kode\Framework\Console\Command
{
    protected function handle(): int
    {
        return 0;
    }
}

/**
 * 声明了 commands() 且 register() 里逐条 addCommand() 兑现：
 * 验证兑现后审计通过零漂移。
 */
final class CommandFulfilledPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'cmd_fulfilled';
    }

    public function commands(): array
    {
        return [CommandFulfilledCommand::class];
    }

    public function register(PluginManager $m): void
    {
        $m->addCommand(CommandFulfilledCommand::class);
    }

    public function boot(PluginManager $m): void
    {
    }
}

/** 声明的命令类不是 Console\Command 子类 → drift。 */
final class CommandBadTypePlugin implements PluginInterface
{
    public function name(): string
    {
        return 'cmd_bad_type';
    }

    public function commands(): array
    {
        return [\stdClass::class];
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}

/**
 * 声明了 events() 但 register() 里未调 addListener()：
 * 验证「声明了却未兑现」判漂移。
 */
final class EventUnfulfilledPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'evt_unfulfilled';
    }

    public function events(): array
    {
        return [
            'order.created' => static function (): void {},
        ];
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}

/**
 * 声明了 events() 且 register() 里逐条 addListener() 兑现：
 * 验证兑现后审计通过零漂移。
 */
final class EventFulfilledPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'evt_fulfilled';
    }

    public function events(): array
    {
        return [
            'order.created' => static function (): void {},
        ];
    }

    public function register(PluginManager $m): void
    {
        $m->addListener('order.created', static function (): void {});
    }

    public function boot(PluginManager $m): void
    {
    }
}

/**
 * 菜单形态异常：覆盖缺 title、path 不以 / 开头、路径重复、可选字段类型错误。
 * 供 test_menu_bad_shape_is_drift 使用。
 */
final class MenuBadShapePlugin implements PluginInterface
{
    public function name(): string
    {
        return 'menu_bad_shape';
    }

    public function menus(): array
    {
        return [
            // 1. 缺 title
            ['path' => '/reports', 'icon' => 'chart'],
            // 2. path 不以 / 开头
            ['title' => '报表', 'path' => 'reports', 'icon' => 'chart'],
            // 3. 路径重复（与第 4 条同 path）
            ['title' => '日报', 'path' => '/reports/daily', 'icon' => 'calendar'],
            // 4. 路径重复
            ['title' => '日报副本', 'path' => '/reports/daily', 'icon' => 'calendar'],
            // 5. 可选字段类型错误（icon 为整数）
            ['title' => '设置', 'path' => '/settings', 'icon' => 42],
        ];
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}

/**
 * 表名非法：覆盖数字开头、含连字符、超长、重复。
 * 供 test_table_name_format_is_drift 使用。
 */
final class TableBadNamePlugin implements PluginInterface
{
    public function name(): string
    {
        return 'table_bad_name';
    }

    public function tables(): array
    {
        return [
            '1abc_table',   // 数字开头 → 非法
            'my-table',     // 含连字符 → 非法
            str_repeat('a', 64), // 64 字符 → 超长（上限 63）
            'valid_table',  // 合法
            'valid_table',  // 重复 → 漂移
        ];
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}

/**
 * version() 返回值异常：构造器注入返回值，供 invalidVersionProvider 复用同一个夹具类。
 * 刻意不声明 configSchema/drivers/menus——保证 drift 里只有 version 那一条，
 * 用例断言不会被其它阶段的漂移淹没。
 */
final class VersionMalformedPlugin implements PluginInterface
{
    public function __construct(private string $version = '')
    {
    }

    public function name(): string
    {
        return 'version_malformed';
    }

    public function version(): string
    {
        return $this->version;
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}

/**
 * requires() 声明形态/版本约束异常：构造器注入整个 requires() 列表，
 * 供 invalidRequiresProvider 与 validConstraintProvider 复用。
 */
final class RequiresMalformedPlugin implements PluginInterface
{
    /** @param array<int, mixed> $requires */
    public function __construct(private array $requires = [])
    {
    }

    public function name(): string
    {
        return 'requires_malformed';
    }

    public function requires(): array
    {
        return $this->requires;
    }

    public function register(PluginManager $m): void
    {
    }

    public function boot(PluginManager $m): void
    {
    }
}
