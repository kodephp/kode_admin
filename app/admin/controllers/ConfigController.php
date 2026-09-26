<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\concerns\ApiResponse;
use app\support\HotCache;
use app\support\PluginConfig;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 系统配置中心：KV 读写（读走 redis 热缓存，写必失效）。
 *
 * 读接口的掩码有**两条**规则，缺一条就漏：
 *  1. 键名字样（secret / passwd / token / private / salt / credential）——管的是手工建的键；
 *     词表里是 `passwd` 而不是 `password`，否则 `auth.password_min_length` 这种纯策略键
 *     会被掩成不可读，管理员再也看不到密码长度要求；
 *  2. 插件 schema 的 `secret: true`（PluginConfig::secretKeys()）——管的是
 *     `plugin.<name>.<field>`：字段名由插件自己定，`ak`/`sk` 一个都不碰规则 1 的词，
 *     只按键名猜等于把对象存储凭证整表发给 `configs:view`。
 * 掩码划出一条边界，写侧就必须认它，否则边界自身变成破坏源：读侧恒出 `******` 的那些行，
 * 客户端拿不到原值，把表单原样存回去就是拿掩码字面量（或列表页为渲染方便映射出的空串）
 * 覆盖真凭证。所以 `plugin.*` 一律拒写拒删（ownershipFailure），掩码字面量一律 400，
 * 其余整行 upsert 走「缺键保留原值」。
 */
final class ConfigController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $rows = Db::select('SELECT ckey, cvalue, remark, ctype, ctitle, cgroup, sort, updated_at FROM kode_sys_configs ORDER BY cgroup, sort, ckey');
        // 一次算好整张 secret 键表：discover() 要扫插件目录，放进 foreach 就是每行扫一遍。
        $secretKeys = PluginConfig::secretKeys();
        foreach ($rows as &$row) {
            $row['cvalue'] = $this->maskIfSensitive((string) $row['ckey'], (string) $row['cvalue'], $secretKeys);
        }
        unset($row);

        return $this->ok($rows);
    }

    public function get()
    {
        $key = (string) $this->param('key');
        if (!preg_match('/^[a-z0-9_.]{2,128}$/', $key)) {
            return $this->fail('不存在', 404);
        }
        $value = HotCache::remember("config:{$key}", 300, static function () use ($key): ?string {
            $rows = Db::select('SELECT cvalue FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);

            return $rows[0]['cvalue'] ?? null;
        });
        if ($value === null) {
            return $this->fail('不存在', 404);
        }

        return $this->ok(['key' => $key, 'value' => $this->maskIfSensitive($key, $value)]);
    }

    public function set()
    {
        $data = $this->validate(Request::all(), [
            'key' => 'required|min:2|length:128',
            'value' => 'nullable|length:2000',
            'remark' => 'nullable|length:255',
            'type' => 'nullable|length:16',
            'title' => 'nullable|length:64',
            'group' => 'nullable|length:32',
            'sort' => 'nullable|integer',
        ]);
        $key = (string) $data['key'];
        if (!preg_match('/^[a-z0-9_.]{2,128}$/', $key)) {
            return $this->fail('键名非法', 400);
        }
        $owner = $this->ownershipFailure($key);
        if ($owner !== null) {
            return $this->fail($owner, 400);
        }
        // 局部更新口径（AGENTS §4「缺键保留原值」）：这张表是整行 upsert，
        // 而读侧对 secret 键恒出掩码——「这一行的当前值客户端看不见」是常态。
        // 把「没发」当成「要清空」，页面上点一次保存就把凭证抹成空串，
        // 而掩码回显让它看起来什么都没改。显式传空串仍是清空。
        $row = Db::select('SELECT cvalue, remark, ctype, ctitle, cgroup, sort FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key])[0] ?? [];
        if (($data['value'] ?? null) === PluginConfig::MASK) {
            return $this->fail('掩码值不是真值：不修改就别发 value，要改请填新值', 400);
        }
        $data += [
            'value' => (string) ($row['cvalue'] ?? ''),
            'remark' => (string) ($row['remark'] ?? ''),
            'type' => (string) ($row['ctype'] ?? 'text'),
            'title' => (string) ($row['ctitle'] ?? ''),
            'group' => (string) ($row['cgroup'] ?? 'custom'),
            'sort' => (string) ($row['sort'] ?? '0'),
        ];
        $value = (string) $data['value'];
        $type = in_array((string) $data['type'], ['text', 'number', 'textarea', 'boolean', 'image', 'json'], true)
            ? (string) $data['type'] : 'text';
        // 列宽由上面的 `length:` 挡（超了 422），这里不再截：
        // 按字节截会把中文标题切成非法 UTF-8（22021，整次保存 500），
        // 按字符截则是「静默吃掉用户写的后半截」。
        $title = (string) $data['title'];
        $group = preg_match('/^[a-z]{2,32}$/', (string) $data['group']) === 1
            ? (string) $data['group'] : 'custom';
        $remark = (string) $data['remark'];
        $sort = (int) $data['sort'];
        if ($row === []) {
            Db::insert(
                'INSERT INTO kode_sys_configs (ckey, cvalue, remark, ctype, ctitle, cgroup, sort, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$key, $value, $remark, $type, $title, $group, $sort]
            );
        } else {
            Db::update(
                'UPDATE kode_sys_configs SET cvalue = ?, remark = ?, ctype = ?, ctitle = ?, cgroup = ?, sort = ?, updated_at = NOW() WHERE ckey = ?',
                [$value, $remark, $type, $title, $group, $sort, $key]
            );
        }
        HotCache::forget("config:{$key}");

        return $this->ok(['key' => $key]);
    }

    public function destroy()
    {
        $key = (string) $this->param('key');
        if (!preg_match('/^[a-z0-9_.]{2,128}$/', $key)) {
            return $this->fail('不存在', 404);
        }
        $owner = $this->ownershipFailure($key);
        if ($owner !== null) {
            return $this->fail($owner, 400);
        }
        Db::delete('DELETE FROM kode_sys_configs WHERE ckey = ?', [$key]);
        HotCache::forget("config:{$key}");

        return $this->ok(['deleted' => $key]);
    }

    /** 恢复出厂默认值（只补缺失键，不覆盖已有修改）。 */
    public function reset()
    {
        $added = 0;
        foreach (\app\support\SysConfig::defaults() as [$key, $value, $remark, $type, $title, $group, $sort]) {
            $exists = Db::select('SELECT id FROM kode_sys_configs WHERE ckey = ? LIMIT 1', [$key]);
            if ($exists !== []) {
                continue;
            }
            Db::insert(
                'INSERT INTO kode_sys_configs (ckey, cvalue, remark, ctype, ctitle, cgroup, sort, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$key, $value, $remark, $type, $title, $group, $sort]
            );
            $added++;
        }

        return $this->ok(['added' => $added]);
    }

    /** 站点品牌键白名单：登录页/页脚要在拿到权限码之前就能显示，故逐键列死而不是按前缀放行。 */
    private const SITE_KEYS = ['site.title', 'site.logo', 'site.icp', 'site.description'];

    /**
     * 站点品牌子集（登录即读，不需 configs:view）。
     *
     * 整张配置表收进 configs:view 之后，登录页取站点标题不能再打 /api/configs（那是全站配置，
     * 含上传策略与市场地址）；这里只出白名单里的键，值仍过一遍掩码兜底。
     */
    public function site()
    {
        $holders = implode(',', array_fill(0, count(self::SITE_KEYS), '?'));
        $rows = Db::select(
            "SELECT ckey, cvalue FROM kode_sys_configs WHERE ckey IN ({$holders})",
            self::SITE_KEYS
        );
        $out = [];
        foreach (self::SITE_KEYS as $key) {
            $out[$key] = '';
        }
        foreach ($rows as $row) {
            $key = (string) $row['ckey'];
            $out[$key] = $this->maskIfSensitive($key, (string) ($row['cvalue'] ?? ''));
        }

        return $this->ok($out);
    }

    /**
     * 这个口不 owns 的键 → 拒写理由（null = 放行）。
     *
     * `plugin.<name>.<field>` 归 PluginConfig 托管，从通用 KV 口写/删它是双份错：
     *  1. 读侧对这些键恒出掩码（`secretKeys()`），所以表单里那一格要么是 `******`
     *     要么是空——把这一格原样存回去就等于用掩码字面量/空串覆盖对象存储凭证，
     *     而下次读仍是掩码，页面上看不出任何异常；PluginConfig::save() 才是认
     *     schema、按字段类型校验、并把掩码/空值解释成「保持不变」的那个口；
     *  2. 缓存键不共用：插件读 `plugin:config:{key}`，这里只失效 `config:{key}`，
     *     写「成功」了插件却照旧拿 5 分钟内的旧值。
     */
    private function ownershipFailure(string $key): ?string
    {
        if (str_starts_with($key, PluginConfig::PREFIX)) {
            return '插件配置项（plugin.*）请在插件详情页的配置区修改，那里会按 schema 校验并保留未改动的密钥';
        }

        return null;
    }

    /**
     * 掩码判定：键名字样命中，或该键是某插件 schema 里声明的 secret 字段。
     *
     * `$secretKeys` 传 null 时（单键读取）现查一次；整表读取必须把预先算好的集合传进来
     * ——`discover()` 要扫插件目录，放进逐行循环就是每行扫一遍。
     *
     * @param array<string, true>|null $secretKeys
     */
    private function maskIfSensitive(string $key, string $value, ?array $secretKeys = null): string
    {
        $lower = strtolower($key);
        // password 这种纯策略键（如 password_min_length）不掩码，只有明确的密钥类才掩。
        foreach (['secret', 'passwd', 'token', 'private', 'salt', 'credential'] as $word) {
            if (str_contains($lower, $word)) {
                return PluginConfig::MASK;
            }
        }

        $isSecret = $secretKeys === null
            ? PluginConfig::isSecretKey($key)
            : isset($secretKeys[$key]);
        if ($isSecret) {
            return PluginConfig::MASK;
        }

        return $value;
    }
}
