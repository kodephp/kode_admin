<?php

declare(strict_types=1);

namespace app\tests;

use app\support\PluginConfig;
use app\support\PluginContract;
use app\support\PluginMarket;
use Kode\Database\Db\Db;
use PHPUnit\Framework\TestCase;

/**
 * 插件配置（schema 驱动）测试。
 *
 * 覆盖三件事：
 *  1. schema 规范化——类型/字段名的降级与丢弃是「有理由的」，不是静默出错；
 *  2. 字段校验——必填/数值区间/布尔/select/长度，拒绝时必须给出可读原因；
 *  3. 存储往返与 secret 语义——掩码只作用于表单回显，运行时读取拿真值；
 *     回传掩码值或空值等于「保持不变」，否则掩码会覆盖真值。
 *
 * 隔离性：第 3 组会用真实插件 storage_driver 写入 `kode_sys_configs`
 * （键前缀 plugin.storage_driver.），tearDown 精确删除本测试写入的行，不留脏数据。
 */
final class PluginConfigTest extends TestCase
{
    private const NAME = 'storage_driver';

    protected function setUp(): void
    {
        \Kode\Framework\Application::make(dirname(__DIR__));
    }

    public function test_declared_lists_only_plugins_with_config_schema(): void
    {
        $declared = array_column(PluginConfig::declared(), 'name');
        self::assertContains(self::NAME, $declared);
        // DemoPlugin 未实现 configSchema()，不应出现。
        self::assertNotContains('demo', $declared);

        $row = null;
        foreach (PluginConfig::declared() as $d) {
            if ($d['name'] === self::NAME) {
                $row = $d;
            }
        }
        self::assertNotNull($row);
        self::assertSame(5, $row['fields']);
        self::assertSame(1, $row['required']);   // bucket
        self::assertSame(2, $row['secret']);     // ak / sk
    }

    public function test_normalize_schema_coerces_unknown_type_and_drops_bad_names(): void
    {
        $schema = PluginConfig::normalizeSchema([
            'quantum' => ['label' => '非法类型', 'type' => 'quantum'],
            'mode'    => ['label' => '模式', 'type' => 'select', 'options' => []],
            '1bad'    => ['label' => '字段名以数字开头'],
            'UPPER'   => ['label' => '字段名含大写'],
            'ok'      => ['label' => '正常字段'],
        ]);

        self::assertArrayHasKey('quantum', $schema);
        self::assertSame('string', $schema['quantum']['type'], '未知类型必须降级为 string');

        // select 没有 options 时降级为 string，避免生成点不开的表单
        self::assertSame('string', $schema['mode']['type']);

        self::assertArrayNotHasKey('1bad', $schema);
        self::assertArrayNotHasKey('UPPER', $schema);
        self::assertArrayHasKey('ok', $schema);

        // 单字符字段名合法（收紧到 2 位会让插件作者踩「字段被静默丢弃」的坑）
        $single = PluginConfig::normalizeSchema(['x' => ['label' => 'X']]);
        self::assertArrayHasKey('x', $single);
    }

    public function test_normalize_schema_casts_defaults_by_type(): void
    {
        $schema = PluginConfig::normalizeSchema([
            'flag'   => ['label' => '开关', 'type' => 'boolean', 'default' => 'true'],
            'ratio'  => ['label' => '比例', 'type' => 'number', 'default' => '1.500'],
            'neg'    => ['label' => '反例', 'type' => 'boolean', 'default' => '0'],
            'picked' => ['label' => '选择', 'type' => 'select', 'default' => 'nope', 'options' => ['a', 'b']],
        ]);

        self::assertSame('1', $schema['flag']['default']);
        self::assertSame('0', $schema['neg']['default']);
        self::assertSame('1.5', $schema['ratio']['default']);
        // default 不在 options 内时回落到首个可选项，而不是留一个无效值
        self::assertSame('a', $schema['picked']['default']);
    }

    public function test_validate_field_matrix(): void
    {
        $port = PluginConfig::normalizeSchema([
            'port' => ['label' => '端口', 'type' => 'number', 'min' => 1, 'max' => 65535],
        ])['port'];
        $mode = PluginConfig::normalizeSchema([
            'mode' => ['label' => '模式', 'type' => 'select', 'options' => ['fast', 'slow']],
        ])['mode'];
        $flag = PluginConfig::normalizeSchema([
            'flag' => ['label' => '开关', 'type' => 'boolean'],
        ])['flag'];
        $name = PluginConfig::normalizeSchema([
            'title' => ['label' => '标题', 'type' => 'string', 'required' => true, 'max' => 8],
        ])['title'];

        self::assertNull(PluginConfig::validateField('port', $port, '443'));
        self::assertStringContainsString('必须是数值', PluginConfig::validateField('port', $port, 'abc'));
        self::assertStringContainsString('不能大于 65535', PluginConfig::validateField('port', $port, '70000'));
        self::assertStringContainsString('不能小于 1', PluginConfig::validateField('port', $port, '0'));

        self::assertNull(PluginConfig::validateField('mode', $mode, 'fast'));
        self::assertStringContainsString('取值非法', PluginConfig::validateField('mode', $mode, 'xyz'));

        foreach (['1', '0', 'true', 'false'] as $v) {
            self::assertNull(PluginConfig::validateField('flag', $flag, $v), "布尔值 {$v} 应通过");
        }
        self::assertStringContainsString('必须是布尔值', PluginConfig::validateField('flag', $flag, 'yes'));

        self::assertStringContainsString('必填', PluginConfig::validateField('title', $name, ''));
        self::assertNull(PluginConfig::validateField('title', $name, '标题'));
        self::assertStringContainsString('超长', PluginConfig::validateField('title', $name, '一二三四五六七八九'));
    }

    public function test_save_rejects_unknown_field_without_writing(): void
    {
        $r = PluginConfig::save(self::NAME, ['no_such_field' => 'x']);
        self::assertFalse($r['ok']);
        self::assertSame([], $r['saved']);
        self::assertStringContainsString('未在 schema 中声明', $r['errors'][0]);
    }

    public function test_save_rejects_required_violation_without_writing(): void
    {
        $r = PluginConfig::save(self::NAME, ['bucket' => '']);
        self::assertFalse($r['ok']);
        self::assertSame([], $r['saved']);
        self::assertStringContainsString('必填', $r['errors'][0]);
    }

    public function test_roundtrip_and_secret_masking(): void
    {
        $this->assertNotEmpty(PluginMarket::discover()[self::NAME] ?? '');

        try {
            // 1) 写入普通字段 + 敏感字段
            $r = PluginConfig::save(self::NAME, [
                'bucket' => 'kode-config-test',
                'prefix' => 'tests/',
                'sk'     => 'real-secret-value',
            ]);
            self::assertTrue($r['ok'], implode('；', $r['errors']));
            self::assertSame(['bucket', 'prefix', 'sk'], $r['saved']);

            // 2) 表单回显：敏感字段必须掩码，普通字段回真值
            $all = PluginConfig::allFor(self::NAME);
            self::assertSame('kode-config-test', $all['values']['bucket']['value']);
            self::assertTrue($all['values']['bucket']['saved']);
            self::assertSame(PluginConfig::MASK, $all['values']['sk']['value'], '敏感字段回显必须掩码');

            // 3) 运行时读取拿真值（掩码只作用于表单，不影响插件运行）
            self::assertSame('real-secret-value', PluginConfig::get(self::NAME, 'sk'));
            self::assertSame('tests/', PluginConfig::get(self::NAME, 'prefix'));

            // 4) 回传掩码值 = 保持不变，不得把 ****** 写进库覆盖真值
            $r2 = PluginConfig::save(self::NAME, ['sk' => PluginConfig::MASK]);
            self::assertTrue($r2['ok']);
            self::assertSame([], $r2['saved']);
            self::assertSame(['sk'], $r2['skipped']);
            self::assertSame('real-secret-value', PluginConfig::get(self::NAME, 'sk'), '掩码值不得覆盖真值');

            // 5) 未配置的字段回落 schema 默认值（storage_driver 默认均为空）
            self::assertSame('', PluginConfig::get(self::NAME, 'ak'));
            // 未知字段名不参与默认值查找，直接返回调用方兜底
            self::assertSame('fallback', PluginConfig::get(self::NAME, 'not_a_field', 'fallback'));
        } finally {
            $this->dropPluginConfigRows(self::NAME);
        }
    }

    public function test_get_falls_back_to_schema_default(): void
    {
        try {
            $this->dropPluginConfigRows(self::NAME);
            // storage_driver 的 endpoint 默认值为空 → 回落到调用方兜底值
            self::assertSame('https://default.example', PluginConfig::get(self::NAME, 'endpoint', 'https://default.example'));
        } finally {
            $this->dropPluginConfigRows(self::NAME);
        }
    }

    /**
     * 契约审计必须能拦下「声明了 schema 但生成不出表单」的写法。
     */
    public function test_audit_config_schema_catches_bad_declarations(): void
    {
        $stub = new class {
            public function configSchema(): array
            {
                return [
                    'no_label'  => ['type' => 'string'],
                    'bad_type'  => ['label' => '坏类型', 'type' => 'quantum'],
                    'Bad-Name'  => ['label' => '坏字段名', 'type' => 'string'],
                    'empty_sel' => ['label' => '空选项', 'type' => 'select'],
                    'bad_def'   => ['label' => '坏默认值', 'type' => 'select', 'default' => 'nope', 'options' => ['a']],
                    'bad_num'   => ['label' => '坏数值', 'type' => 'number', 'default' => 'not-a-number'],
                ];
            }
        };

        $drift = PluginContract::auditConfigSchema($stub);
        self::assertGreaterThanOrEqual(6, count($drift), implode('；', $drift));
        $joined = implode("\n", $drift);

        self::assertStringContainsString('no_label', $joined);
        self::assertStringContainsString('缺 label', $joined);
        self::assertStringContainsString('quantum', $joined);
        self::assertStringContainsString('Bad-Name', $joined);
        self::assertStringContainsString('empty_sel', $joined);
        self::assertStringContainsString('nope', $joined);
        self::assertStringContainsString('not-a-number', $joined);
    }

    public function test_audit_config_schema_is_clean_for_real_plugins(): void
    {
        $classes = PluginMarket::discover();

        /** @var object $plugin */
        $plugin = new $classes[self::NAME]();
        $drift = PluginContract::auditConfigSchema($plugin);

        self::assertSame([], $drift, implode('；', $drift));

        // 未实现 configSchema() 的插件不产生任何漂移
        $demo = new $classes['demo']();
        self::assertSame([], PluginContract::auditConfigSchema($demo));
    }

    /** 精确清理本测试写入的插件配置行（只按 plugin.<name>. 前缀删）。 */
    private function dropPluginConfigRows(string $name): void
    {
        Db::delete('DELETE FROM kode_sys_configs WHERE ckey LIKE ?', [PluginConfig::PREFIX . $name . '%']);
    }
}
