<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\concerns\ApiResponse;
use app\support\Clip;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 特性开关管理：运行时开关 + 灰度比例调整。
 *
 * 数据源：kode_feature_flags 表，无需重启即可切换特性。
 * 与 config/feature.php 静态配置互补：静态配置用于编译期门控，
 * 本控制器用于运行时动态开关和 A/B 灰度发布。
 */
final class FeatureFlagController extends Controller
{
    use ApiResponse;

    private const TABLE = 'kode_feature_flags';

    /**
     * GET /api/feature-flags — 列出所有特性开关。
     */
    public function index()
    {
        $rows = Db::table(self::TABLE)
            ->orderBy('key', 'asc')
            ->get();

        $flags = [];
        foreach ($rows as $row) {
            $flags[] = $this->formatFlag($row);
        }

        return $this->ok($flags);
    }

    /**
     * POST /api/feature-flags — 创建或更新特性开关。
     *
     * 请求体：{ key, description?, enabled?, rollout?, environment? }
     */
    public function store()
    {
        $key = (string) $this->input('key', '');
        if (!preg_match('/^[a-z][a-z0-9._-]{0,127}$/', $key)) {
            return $this->fail('特性 key 非法（小写字母开头，仅允许 a-z 0-9 . _ -）', 400);
        }

        $percent = self::percentOf($this->input('rollout', 100));
        if ($percent['error'] !== null) {
            return $this->fail($percent['error'], 400);
        }

        $payload = [
            'description' => (string) $this->input('description', ''),
            'enabled' => (bool) $this->input('enabled', true),
            'rollout' => $percent['value'],
            'environment' => (string) $this->input('environment', ''),
        ];

        $existing = Db::table(self::TABLE)->where('key', $key)->first();
        if ($existing) {
            Db::table(self::TABLE)->where('key', $key)->update($payload);
            $this->logChange('update', $key, $payload, $existing);
            $result = ['key' => $key, 'action' => 'updated'];
        } else {
            $payload['key'] = $key;
            Db::table(self::TABLE)->insert($payload);
            $this->logChange('create', $key, $payload, null);
            $result = ['key' => $key, 'action' => 'created'];
        }

        return $this->ok($result);
    }

    /**
     * PUT /api/feature-flags/{key} — 更新特性开关。
     *
     * 请求体：{ description?, enabled?, rollout?, environment? }
     *
     * 注意：必须显式按 !== null 判断，不能依赖 array_filter 的默认 falsy 过滤，
     * 否则 enabled=false（关闭开关）等合法值会被错误剔除。
     */
    public function update()
    {
        $key = (string) $this->param('key');
        $existing = Db::table(self::TABLE)->where('key', $key)->first();
        if (!$existing) {
            return $this->fail('不存在', 404);
        }

        $payload = [];
        if ($this->input('description') !== null) {
            $payload['description'] = (string) $this->input('description');
        }
        if ($this->input('enabled') !== null) {
            $payload['enabled'] = (bool) $this->input('enabled');
        }
        if ($this->input('rollout') !== null) {
            $percent = self::percentOf($this->input('rollout'));
            if ($percent['error'] !== null) {
                return $this->fail($percent['error'], 400);
            }
            $payload['rollout'] = $percent['value'];
        }
        if ($this->input('environment') !== null) {
            $payload['environment'] = (string) $this->input('environment');
        }

        if ($payload === []) {
            return $this->fail('无可更新字段', 400);
        }

        Db::table(self::TABLE)->where('key', $key)->update($payload);

        $this->logChange('update', $key, $payload, $existing);

        return $this->ok(['key' => $key, 'action' => 'updated']);
    }

    /**
     * DELETE /api/feature-flags/{key} — 删除特性开关。
     */
    public function destroy()
    {
        $key = (string) $this->param('key');
        $existing = Db::table(self::TABLE)->where('key', $key)->first();
        if (!$existing) {
            return $this->fail('不存在', 404);
        }

        Db::table(self::TABLE)->where('key', $key)->delete();
        $this->logChange('delete', $key, [], $existing);

        return $this->ok(['key' => $key, 'action' => 'deleted']);
    }

    /**
     * POST /api/feature-flags/{key}/toggle — 快速切换启用状态。
     */
    public function toggle()
    {
        $key = (string) $this->param('key');
        $existing = Db::table(self::TABLE)->where('key', $key)->first();
        if (!$existing) {
            return $this->fail('不存在', 404);
        }

        $newEnabled = !(bool) ($existing['enabled'] ?? false);
        Db::table(self::TABLE)->where('key', $key)->update(['enabled' => $newEnabled]);
        $this->logChange('toggle', $key, ['enabled' => $newEnabled], $existing);

        return $this->ok(['key' => $key, 'enabled' => $newEnabled]);
    }

    /**
     * GET /api/feature-flags/check — 检查特性是否启用（含灰度判定）。
     *
     * Query: ?key=<flag_key>&user_id=<部分灰度时必填>
     */
    public function check()
    {
        $key = (string) $this->query('key', '');
        $userId = trim((string) $this->query('user_id', ''));

        if ($key === '') {
            return $this->fail('key 不能为空', 400);
        }

        $flag = Db::table(self::TABLE)->where('key', $key)->first();
        if (!$flag) {
            return $this->ok(['key' => $key, 'enabled' => false, 'reason' => 'flag_not_found']);
        }

        if (!(bool) ($flag['enabled'] ?? false)) {
            return $this->ok(['key' => $key, 'enabled' => false, 'reason' => 'disabled']);
        }

        $rollout = (int) ($flag['rollout'] ?? 0);
        if ($rollout >= 100) {
            return $this->ok(['key' => $key, 'enabled' => true, 'reason' => 'full_rollout']);
        }

        if ($rollout <= 0) {
            return $this->ok(['key' => $key, 'enabled' => false, 'reason' => 'zero_rollout']);
        }

        // 部分灰度的前提是「有一个可以分桶的主体」。缺主体时 bucket 恒为 0，
        // 于是这一支对每一个匿名调用都回 in_rollout —— 只能拒答，不能替所有人回答「在」。
        if ($userId === '') {
            return $this->fail(
                '该开关处于部分灰度（rollout=' . $rollout . '%），判定必须带 user_id',
                400
            );
        }

        $bucket = $this->bucket($key, $userId);
        $enabled = $bucket < $rollout;
        return $this->ok([
            'key' => $key,
            'enabled' => $enabled,
            'reason' => $enabled ? 'in_rollout' : 'not_in_rollout',
            'rollout' => $rollout,
            'bucket' => $bucket,
        ]);
    }

    // ─── 私有辅助 ───────────────────────────────────────────

    /**
     * `Db::table()` 交回的是**关联数组**（`QueryBuilder::get(): array`、`first(): ?array`），不是实体。
     * 按 `$row->key` 读数组属性在 PHP 8 只是一条 warning，值静默变 null —— 于是这个类的每一个
     * 读入口都「正常」输出空白/false/0，而库里数据是好的。见 `tests/QueryBuilderRowShapeTest.php`。
     *
     * @param array<string, mixed> $row
     */
    private function formatFlag(array $row): array
    {
        return [
            'key' => (string) ($row['key'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'enabled' => (bool) ($row['enabled'] ?? false),
            'rollout' => (int) ($row['rollout'] ?? 0),
            'environment' => (string) ($row['environment'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * 灰度比例（0-100 的整数）的入参判据 —— **只此一份**，两个写入口共用。
     *
     * 旧写法 `max(0, min(100, (int) $this->input('rollout')))` 是替操作者决定灰度开多大，
     * 而这一列直接喂给 `check()` 的分桶比较（`bucket < rollout`），所以每次静默夹取都是
     * 一次真实的放量动作（实测）：
     *  - `1000` → 100 → 对**全量**用户放开（多打一个 0 就把 10% 灰度变成全量）；
     *  - `-1` → 0 → 对全量关闭；
     *  - `'50人'` → 50（尾部垃圾被吃掉，操作者以为设的是别的数）；
     *  - `'abc'` → 0、`true` → 1 → 一个「什么都没开」一个「开了 1%」，两者都回 200，
     *    页面和审计账本都不会说「你给的数我没看懂」。
     *
     * 口径与 `MigrationController::stepsFor()`、`RetentionGuard::retentionDays()` 同族：
     * 越界与畸形一律 400，且**先拒再写**。
     *
     * @return array{value: int|null, error: string|null}
     */
    public static function percentOf(mixed $raw): array
    {
        $error = 'rollout 必须是 0-100 之间的整数（灰度百分比）';

        if (is_int($raw)) {
            return ($raw >= 0 && $raw <= 100)
                ? ['value' => $raw, 'error' => null]
                : ['value' => null, 'error' => $error];
        }

        if (is_float($raw)) {
            // 只认整数值（JSON 里 `37.0` 与 `37` 都会真实出现），12.5 这种半个人不许猜意图。
            if (is_finite($raw) && $raw === floor($raw) && $raw >= 0.0 && $raw <= 100.0) {
                return ['value' => (int) $raw, 'error' => null];
            }

            return ['value' => null, 'error' => $error];
        }

        if (is_string($raw) && preg_match('/^\d{1,3}$/', $raw) === 1) {
            // 三位数封顶：`^\d{1,3}$` 之后 (int) 是精确的，'99999999999999999999' 不会饱和成 100。
            $value = (int) $raw;

            return ($value <= 100)
                ? ['value' => $value, 'error' => null]
                : ['value' => null, 'error' => $error];
        }

        return ['value' => null, 'error' => $error];
    }

    /**
     * 灰度分桶：基于 flag_key + user_id 的确定性哈希，
     * 确保同一用户对同一特性始终命中相同结果。
     *
     * 调用方（`check()`）已挡掉空主体 —— 空串会让 crc32 落成一个固定桶号，
     * 那等于给「没有这个人」算出一个答案。
     */
    private function bucket(string $key, string $userId): int
    {
        return crc32($key . ':' . $userId) % 100;
    }

    /**
     * 将变更写入审计日志（path=/api/feature-flags，method=PUT/POST/DELETE）。
     * 复用审计表，前端「审计 → 我的操作」或全局审计页可直接查询。
     * 失败静默，不阻断主流程。
     *
     * 注意 `try` 罩不住**调用边界**的类型错误：`?object` 收到数组行时抛的是
     * `\TypeError`，它在进入本方法的那一刻就冒出，于是库里已经改完、客户端看到 500。
     *
     * @param ?array<string, mixed> $before
     */
    private function logChange(string $action, string $key, array $changes, ?array $before): void
    {
        try {
            $payload = Request::attr('auth');
            $username = (string) ($payload->username ?? 'system');
            $method = match ($action) {
                'create' => 'POST',
                'update' => 'PUT',
                'toggle' => 'POST',
                'delete' => 'DELETE',
                default  => 'PUT',
            };
            $path = match ($action) {
                'create'   => '/api/feature-flags',
                'delete'   => '/api/feature-flags/' . rawurlencode($key),
                'toggle'   => '/api/feature-flags/' . rawurlencode($key) . '/toggle',
                default    => '/api/feature-flags/' . rawurlencode($key),
            };
            $msg = sprintf(
                '%s feature flag %s: %s (before: enabled=%s rollout=%s)',
                $action,
                $key,
                json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $before ? (int) $before['enabled'] : '-',
                $before ? (int) $before['rollout'] : '-',
            );
            \app\support\AuditTrail::record(
                (string) ($payload->uid ?? ''),
                $username,
                $method,
                $path,
                200,
                0,
                \app\support\ClientIp::resolve()
            );
            // 额外写入 syslog，便于运维侧追踪（可选）
            try {
                \app\support\SysLog::info('feature-flags', $msg);
            } catch (\Throwable) {
                // 忽略
            }
        } catch (\Throwable) {
            // 审计失败不阻断
        }
    }

    /**
     * GET /api/feature-flags/stats — 特性开关概览统计。
     * 返回总数、启用/禁用数、灰度中（0<rollout<100）、按环境分布、最近变更。
     */
    public function stats()
    {
        $rows = Db::table(self::TABLE)->get();
        $total = count($rows);
        $enabled = 0;
        $disabled = 0;
        $fullRollout = 0;
        $partialRollout = 0;
        $zeroRollout = 0;
        $byEnv = [];
        $recentChanged = [];
        foreach ($rows as $row) {
            $e = (bool) ($row['enabled'] ?? false);
            $r = (int) ($row['rollout'] ?? 0);
            $env = (string) ($row['environment'] ?? '') ?: 'default';
            if ($e) $enabled++;
            else $disabled++;
            if ($r >= 100) $fullRollout++;
            elseif ($r <= 0) $zeroRollout++;
            else $partialRollout++;
            $byEnv[$env] = ($byEnv[$env] ?? 0) + 1;
            $recentChanged[] = [
                'key' => (string) ($row['key'] ?? ''),
                'enabled' => $e,
                'rollout' => $r,
                'environment' => $env,
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }
        usort($recentChanged, static fn($a, $b) => strcmp((string) $b['updated_at'], (string) $a['updated_at']));
        $recentChanged = array_slice($recentChanged, 0, 10);

        return $this->ok([
            'total' => $total,
            'enabled' => $enabled,
            'disabled' => $disabled,
            'full_rollout' => $fullRollout,
            'partial_rollout' => $partialRollout,
            'zero_rollout' => $zeroRollout,
            'by_environment' => $byEnv,
            'recent_changed' => $recentChanged,
        ]);
    }

    /**
     * GET /api/feature-flags/history — 变更历史（从审计日志筛出 /api/feature-flags 相关请求）。
     * Query: ?key=<flag_key>&limit=<1-200>
     */
    public function history()
    {
        $key = Clip::chars(trim((string) $this->query('key', '')), 128);
        $limit = min(200, max(1, (int) $this->query('limit', 50)));
        $where = ["path LIKE '%/api/feature-flags%'"];
        $params = [];
        if ($key !== '') {
            $where[] = 'path LIKE ?';
            $params[] = '%/' . rawurlencode($key) . '%';
        }
        $params[] = $limit;
        $cond = ' WHERE ' . implode(' AND ', $where);
        // 与 /api/audits 同一条读侧派发口：这里筛的是同一批片表，逐片 try/catch continue
        // 会把「读不动的一片」变成「这个开关从没改过」。
        $scan = \app\support\Shard::scan(
            \app\support\Shard::readUnits(\app\support\AuditTrail::BASE, false, false),
            static function (?string $conn, string $table) use ($cond, $params): array {
                return \app\support\Shard::db($conn)->select(
                    "SELECT id, user_id, username, method, path, status, duration_ms, ip, created_at
                     FROM {$table}{$cond} ORDER BY id DESC LIMIT ?",
                    $params
                );
            }
        );
        $merged = [];
        foreach ($scan['results'] as $rows) {
            foreach ($rows as $row) {
                $merged[] = $row;
            }
        }
        usort($merged, static fn($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);
        // 对外 id 混淆（AGENTS.md §4）
        $merged = array_map(static function (array $row): array {
            $row['id'] = \app\support\HashId::enc((int) $row['id']);
            $row['user_id'] = isset($row['user_id'])
                ? \app\support\HashId::enc((int) $row['user_id'])
                : null;

            return $row;
        }, array_slice($merged, 0, $limit));

        return $this->ok($merged, 200, ['unreadable_shards' => \app\support\Shard::unreadableKey($scan['unreadable'])]);
    }
}