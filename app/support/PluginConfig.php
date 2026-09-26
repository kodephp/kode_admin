<?php

declare(strict_types=1);

namespace app\support;

use Kode\Database\Db\Db;
use Kode\Framework\Plugin\PluginInterface;

/**
 * 插件配置（schema 驱动：插件声明 configSchema() → 后台渲染表单 → 运行时按键读取）
 *
 * 为什么需要：`configSchema()` 此前只被 PluginContract 读出展示，没有任何消费者——
 * 插件声明了配置项，后台却无处填写，属「声明⟺实现」漂移。本类补齐消费端闭环。
 *
 * 存储：复用 `kode_sys_configs`，不新建表。`cgroup='plugin'` + `ckey=plugin.<name>.<field>`
 * 与核心系统键（`site.*` / `market.*` / `password.*`）物理隔离，但仍能在系统设置页
 * 以 `plugin` 分组统一可见（对齐「配置中心并入系统设置」的收敛方向）。
 *
 * 契约红线：
 *  - schema 的 SoT 是插件代码本身（`configSchema()`），**不落库**——版本演进跟着
 *    插件走，DB 只存值，避免「代码改了 schema、库里还是旧结构」的二次漂移；
 *  - `secret` 字段读取一律掩码；回写时「掩码值/空值 = 保持不变」，否则前端回显的
 *    `******` 会被写回覆盖真值；
 *  - 提交的字段不在 schema 内一律报错（不静默丢弃），字段名不合法不建键。
 *
 * 用法：
 *   PluginConfig::allFor('storage_driver');           // 表单用：schema + 当前值
 *   PluginConfig::save('storage_driver', $submitted); // 校验并入库
 *   PluginConfig::get('storage_driver', 'bucket');    // 插件运行时读取
 */
final class PluginConfig
{
    /** 插件配置统一分组（须匹配 ConfigController 的 group 正则 ^[a-z]{2,32}$）。 */
    public const GROUP = 'plugin';

    /** 键前缀：plugin.<name>.<field>，须匹配 key 正则 ^[a-z0-9_.]{2,128}$。 */
    public const PREFIX = 'plugin.';

    /** 字段类型白名单（插件 schema 的 `type` 必须在此范围内）。 */
    public const TYPES = ['string', 'number', 'boolean', 'textarea', 'select'];

    /** 掩码值：ConfigController 读接口用的是同一个常量（两处各写一份迟早长得不一样）。 */
    public const MASK = '******';

    /** 字段名格式：小写字母开头，1–48 位小写字母/数字/下划线。 */
    public const FIELD_PATTERN = '/^[a-z][a-z0-9_]{0,47}$/';

    /**
     * 已声明配置项的插件（供设置页/审计展示，未声明 configSchema() 的插件不出现）。
     *
     * @return list<array{name: string, fields: int, required: int, secret: int}>
     */
    public static function declared(): array
    {
        $out = [];
        foreach (PluginMarket::discover() as $name => $class) {
            /** @var PluginInterface $plugin */
            $plugin = new $class();
            if (!method_exists($plugin, 'configSchema')) {
                continue;
            }
            $schema = self::normalizeSchema((array) $plugin->configSchema());
            if ($schema === []) {
                continue;
            }
            $out[] = [
                'name'     => $name,
                'fields'   => count($schema),
                'required' => count(array_filter($schema, static fn($f) => $f['required'])),
                'secret'   => count(array_filter($schema, static fn($f) => $f['secret'])),
            ];
        }

        return $out;
    }

    /**
     * 全仓「schema 声明为 secret」的完整存储键集合。
     *
     * 为什么必须有这一份：`secret` 只写在插件代码的 configSchema() 里，值却统一落在
     * `kode_sys_configs`（cgroup=plugin）。而 ConfigController 的掩码是按**键名子串**猜的
     * （secret|passwd|token|private|salt|credential），于是
     * `plugin.storage_driver.ak` / `.sk` 这种「schema 明写着 secret、键名一个字母都不中」的
     * 行会被 `GET /api/configs` 原样下发——拿着 `configs:view`（系统设置/存储配置菜单）的
     * 非超管就能看到对象存储的 AccessKey。掩码口径必须由 schema 说了算，不能两处各猜一次。
     *
     * @return array<string, true> 键名 => 是否 secret
     */
    public static function secretKeys(): array
    {
        $out = [];
        foreach (PluginMarket::discover() as $name => $class) {
            /** @var PluginInterface $plugin */
            $plugin = new $class();
            if (!method_exists($plugin, 'configSchema')) {
                continue;
            }
            foreach (self::normalizeSchema((array) $plugin->configSchema()) as $field => $def) {
                if ($def['secret'] === true) {
                    $out[self::keyFor((string) $name, (string) $field)] = true;
                }
            }
        }

        return $out;
    }

    /**
     * 单个键是否 secret（schema 说了算；非 plugin. 前缀一律 false）。
     *
     * 键形态恒为 `plugin.<name>.<field>`：插件名与字段名都不含点（名字由
     * PluginMarket::discover() 从文件名来，字段过 FIELD_PATTERN），所以按最后一个点切。
     */
    public static function isSecretKey(string $key): bool
    {
        if (!str_starts_with($key, self::PREFIX)) {
            return false;
        }
        $rest = substr($key, strlen(self::PREFIX));
        $pos = strrpos($rest, '.');
        if ($pos === false) {
            return false;
        }

        $schema = self::schemaFor(substr($rest, 0, $pos));

        return (bool) ($schema[substr($rest, $pos + 1)]['secret'] ?? false);
    }

    /**
     * 规范化 schema：补默认值并丢弃字段名不合法的项。
     *
     * `min`/`max` 语义按类型区分：`number` 是数值区间（`min` 非空），
     * 其余类型 `max` 是字符串长度上限（`min` 为 null）。
     *
     * @param array<string, mixed> $raw
     * @return array<string, array{label: string, type: string, default: string, required: bool, secret: bool, placeholder: string, options: list<string>, max: int, min: int|null}>
     */
    public static function normalizeSchema(array $raw): array
    {
        $out = [];
        foreach ($raw as $field => $def) {
            $field = (string) $field;
            if (!preg_match(self::FIELD_PATTERN, $field) || !is_array($def)) {
                continue;
            }
            $declaredType = (string) ($def['type'] ?? 'string');
            $type = in_array($declaredType, self::TYPES, true) ? $declaredType : 'string';
            $options = array_values(array_filter(
                array_map('strval', (array) ($def['options'] ?? [])),
                static fn($v) => $v !== ''
            ));
            // select 没有可选项时降级为 string，避免生成一个点不开的表单。
            if ($type === 'select' && $options === []) {
                $type = 'string';
            }

            $out[$field] = [
                // 插件声明的 label/placeholder 基本是中文，按字节切会把响应打成
                // 非法 UTF-8（json_encode 带 JSON_THROW_ON_ERROR）。
                'label'       => Clip::chars((string) ($def['label'] ?? $field), 64),
                'type'        => $type,
                'default'     => self::cast((string) ($def['default'] ?? ''), $type, $options),
                'required'    => (bool) ($def['required'] ?? false),
                'secret'      => (bool) ($def['secret'] ?? false),
                'placeholder' => Clip::chars((string) ($def['placeholder'] ?? ''), 255),
                'options'     => $options,
                // 语义按类型区分：number 的 min/max = 数值区间；其余 = 字符串长度上限。
                'max'         => $type === 'number'
                    ? (int) ($def['max'] ?? 999999)
                    : max(1, (int) ($def['max'] ?? ($type === 'textarea' ? 4000 : 512))),
                'min'         => $type === 'number' ? (int) ($def['min'] ?? 0) : null,
            ];
        }

        return $out;
    }

    /**
     * 某插件的配置全貌（表单数据源）。
     *
     * @return array{schema: array<string, array<string, mixed>>, values: array<string, array<string, mixed>>, saved: list<string>}
     */
    public static function allFor(string $name): array
    {
        $schema = self::schemaFor($name);
        if ($schema === []) {
            return ['schema' => [], 'values' => [], 'saved' => []];
        }

        $rows = [];
        try {
            foreach (Db::select('SELECT ckey, cvalue FROM kode_sys_configs WHERE cgroup = ? AND ckey LIKE ?', [
                self::GROUP,
                self::PREFIX . $name . '.%',
            ]) as $row) {
                $rows[(string) $row['ckey']] = (string) ($row['cvalue'] ?? '');
            }
        } catch (\Throwable) {
            // 表不存在（全新库未跑 schema.sql）时只回默认值，不阻断页面。
        }

        $values = [];
        $saved = [];
        foreach ($schema as $field => $def) {
            $key = self::keyFor($name, $field);
            $has = array_key_exists($key, $rows);
            $raw = $has ? ($rows[$key] ?? '') : $def['default'];
            $values[$field] = [
                'value' => $def['secret'] && $has ? self::MASK : $raw,
                'saved' => $has,
                'default' => $def['default'],
            ];
            if ($has) {
                $saved[] = $field;
            }
        }

        return ['schema' => $schema, 'values' => $values, 'saved' => $saved];
    }

    /**
     * 某插件的全部配置项（平铺 map：field => value），供备份/迁移/导入消费。
     *
     * 与 allFor() 的差异：
     *  - allFor() 面向表单渲染，返回 { schema, values, saved } 三层结构；
     *  - all() 面向数据搬运，返回 { field => raw_value } 平铺 map，可直接喂给 save()。
     *
     * secret 字段一并返回真值（不是掩码）——备份/迁移场景需要原样复原；
     * 前端展示走 allFor() 已做掩码，不会漏出。
     *
     * @return array<string, string>
     */
    public static function all(string $name): array
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return [];
        }
        $prefix = self::PREFIX . $name . '.';

        try {
            $rows = Db::select(
                'SELECT ckey, cvalue FROM kode_sys_configs WHERE cgroup = ? AND ckey LIKE ?',
                [self::GROUP, $prefix . '%']
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $ckey = (string) ($row['ckey'] ?? '');
            if (str_starts_with($ckey, $prefix)) {
                $field = substr($ckey, strlen($prefix));
                // 与 normalizeSchema 的 FIELD_PATTERN 保持一致，防越权读同前缀其它键。
                if (preg_match(self::FIELD_PATTERN, $field)) {
                    $out[$field] = (string) ($row['cvalue'] ?? '');
                }
            }
        }

        return $out;
    }

    /**
     * 校验并保存配置。
     *
     * 规则：
     *  - 提交的字段必须在 schema 内，否则报错（不静默丢弃）；
     *  - 必填字段不能为空；number 必须数值且在 [min,max] 内（min/max 由 schema 声明）；
     *  - select 值必须命中 options；secret 字段的掩码值或空值 = 保持不变（不覆盖真值）。
     *
     * @param array<string, mixed> $submitted
     * @return array{ok: bool, errors: list<string>, saved: list<string>, skipped: list<string>}
     */
    public static function save(string $name, array $submitted): array
    {
        $schema = self::schemaFor($name);
        if ($schema === []) {
            return ['ok' => false, 'errors' => ['该插件未声明配置项'], 'saved' => [], 'skipped' => []];
        }

        $errors = [];
        foreach ($submitted as $field => $value) {
            if (!isset($schema[(string) $field])) {
                $errors[] = "字段「{$field}」未在 schema 中声明";
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'saved' => [], 'skipped' => []];
        }

        // 先全量校验，再统一落库：避免校验到一半已经写脏了数据。
        $updates = [];
        $skipped = [];
        foreach ($schema as $field => $def) {
            if (!array_key_exists($field, $submitted)) {
                continue;
            }
            $submitted[$field] = is_scalar($submitted[$field]) ? (string) $submitted[$field] : '';

            if ($def['secret'] && ($submitted[$field] === self::MASK || $submitted[$field] === '')) {
                $skipped[] = $field;
                continue;
            }
            $err = self::validateField($field, $def, $submitted[$field]);
            if ($err !== null) {
                $errors[] = $err;
                continue;
            }
            $updates[$field] = $submitted[$field];
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'saved' => [], 'skipped' => $skipped];
        }

        $saved = [];
        foreach ($updates as $field => $value) {
            $def = $schema[$field];
            $key = self::keyFor($name, $field);
            $exists = Db::select('SELECT id FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);
            if ($exists === []) {
                Db::insert(
                    'INSERT INTO kode_sys_configs (ckey, cvalue, remark, ctype, ctitle, cgroup, sort, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [$key, $value, $def['placeholder'], $def['type'], $def['label'], self::GROUP, self::sortOf($schema, $field)]
                );
            } else {
                Db::update(
                    'UPDATE kode_sys_configs SET cvalue = ?, ctype = ?, ctitle = ?, cgroup = ?, sort = ?, updated_at = NOW() WHERE ckey = ?',
                    [$value, $def['type'], $def['label'], self::GROUP, self::sortOf($schema, $field), $key]
                );
            }
            self::forgetCache($key);
            $saved[] = $field;
        }

        return ['ok' => true, 'errors' => [], 'saved' => $saved, 'skipped' => $skipped];
    }

    /** 运行时读取单个配置值（插件内部用）：未配置返回 schema 默认值，再退到 $default。 */
    public static function get(string $name, string $field, string $default = ''): string
    {
        $key = self::keyFor($name, $field);

        try {
            $value = HotCache::remember("plugin:config:{$key}", 300, static function () use ($key): ?string {
                $rows = Db::select('SELECT cvalue FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);

                return $rows[0]['cvalue'] ?? null;
            });
        } catch (\Throwable) {
            return $default;
        }
        if ($value !== null && $value !== '') {
            return $value;
        }

        $def = self::schemaFor($name)[$field] ?? null;

        if ($def !== null && $def['default'] !== '') {
            return $def['default'];
        }

        return $default;
    }

    /**
     * 规范化后的 schema（未声明返回 []）。
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schemaFor(string $name): array
    {
        $class = PluginMarket::discover()[$name] ?? null;
        if ($class === null) {
            return [];
        }
        /** @var PluginInterface $plugin */
        $plugin = new $class();
        if (!method_exists($plugin, 'configSchema')) {
            return [];
        }

        return self::normalizeSchema((array) $plugin->configSchema());
    }

    /** 拼接存储键。 */
    public static function keyFor(string $name, string $field): string
    {
        return self::PREFIX . $name . '.' . $field;
    }

    /** 按 schema 类型收敛值（用于 default 归一）。 */
    private static function cast(string $value, string $type, array $options): string
    {
        return match ($type) {
            'boolean' => $value !== '' && !in_array(strtolower($value), ['0', 'false', 'no', 'off'], true) ? '1' : '0',
            'number'  => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') : '0',
            'select'  => in_array($value, $options, true) ? $value : ($options[0] ?? ''),
            default   => $value,
        };
    }

    /**
     * 单字段校验（公开供测试与插件自定义校验复用），通过返回 null。
     *
     * @param array{label: string, type: string, required: bool, secret: bool, options: list<string>, max: int, min: int|null} $def
     */
    public static function validateField(string $field, array $def, string $value): ?string
    {
        $label = $def['label'] !== '' ? $def['label'] : $field;
        if ($value === '' && $def['required']) {
            return "「{$label}」为必填项";
        }
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $def['max']) {
            return "「{$label}」超长（上限 {$def['max']} 字符）";
        }
        switch ($def['type']) {
            case 'number':
                if (!is_numeric($value)) {
                    return "「{$label}」必须是数值";
                }
                if ($def['min'] !== null && (float) $value < (float) $def['min']) {
                    return "「{$label}」不能小于 {$def['min']}";
                }
                if ((float) $value > (float) $def['max']) {
                    return "「{$label}」不能大于 {$def['max']}";
                }
                break;
            case 'boolean':
                if (!in_array(strtolower($value), ['0', '1', 'true', 'false'], true)) {
                    return "「{$label}」必须是布尔值";
                }
                break;
            case 'select':
                if (!in_array($value, $def['options'], true)) {
                    return "「{$label}」取值非法（可选：" . implode(' / ', $def['options']) . '）';
                }
                break;
        }

        return null;
    }

    /** schema 内的顺序（设置页排序用）。 */
    private static function sortOf(array $schema, string $field): int
    {
        return (array_search($field, array_keys($schema), true) ?? 0) * 10 + 10;
    }

    private static function forgetCache(string $key): void
    {
        try {
            HotCache::forget("plugin:config:{$key}");
        } catch (\Throwable) {
        }
    }

    /**
     * 删除插件的全部配置行 + 相关缓存（卸载 purge 时用）。
     *
     * 只删 `plugin.<name>.` 前缀的行，不误伤其它插件或系统配置。
     *
     * @return int 实际删除的行数
     */
    public static function purge(string $name): int
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return 0;
        }
        $prefix = self::PREFIX . $name . '.';
        try {
            $rows = Db::select(
                'SELECT ckey FROM kode_sys_configs WHERE ckey LIKE ?',
                [$prefix . '%']
            );
            $count = count($rows);
            if ($count > 0) {
                Db::statement(
                    'DELETE FROM kode_sys_configs WHERE ckey LIKE ?',
                    [$prefix . '%']
                );
            }
            foreach ($rows as $row) {
                self::forgetCache((string) $row['ckey']);
            }

            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 重置配置为 schema 默认值（删除已保存的行，让运行时回落 default）。
     *
     * 与 purge() 的区别：purge() 只删行；reset() 同样删行但语义是「恢复默认」，
     * 适合用户误填配置后一键恢复，不需要重新填写。
     *
     * @return array{deleted: int, fields: int}
     */
    public static function reset(string $name): array
    {
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            return ['deleted' => 0, 'fields' => 0];
        }
        $schema = self::schemaFor($name);
        $fields = count($schema);
        $deleted = self::purge($name);

        return ['deleted' => $deleted, 'fields' => $fields];
    }
}
