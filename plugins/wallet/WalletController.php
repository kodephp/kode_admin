<?php

declare(strict_types=1);

namespace Kode\Plugins\wallet;

use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 钱包 HTTP 层：仅做参数解析 + 归属鉴权，业务全部委托 WalletService。
 *
 * 归属约定：
 *  - admin 角色 或 uid=1：跨租户视图（tenant_id 忽略）；
 *  - 普通用户：仅可访问自己租户下的钱包（通过 auth payload 中的 tenant_id）。
 *
 * 分页窗口只在这里判一次（`PageWindow`，单页上限 200 与本入口原口径一致）：
 * 服务层收的是 `offset`，不再自己夹取，也就没有第二份「翻到多远算多远」。
 */
final class WalletController extends Controller
{
    use ApiResponse;
    use PageWindow;

    /** 导出游标的单页长度（不经 `pageWindow`：这是内部游标，不是用户入参）。 */
    private const EXPORT_PAGE_SIZE = 500;

    /** 导出行数天花板：撞上了必须在 CSV 里写明截断，而不是悄悄少给。 */
    private const EXPORT_MAX_ROWS = 50000;

    /**
     * 出参 id 混淆：内部自增 id → 对外 HashId 不透明串（AGENTS.md §4 防枚举）。
     * 入参一律用 eid()/HashId::dec 解码，服务层保持 int；值为 null 的字段保持 null
     * （如 counterparty_wallet_id 非转账记录为空，不得编码成 0 的 hashid）。
     */
    private static function hashOut(array $row, array $fields = ['id', 'member_id']): array
    {
        foreach ($fields as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) {
                $row[$f] = \app\support\HashId::enc((int) $row[$f]);
            }
        }

        return $row;
    }

    public function list()
    {
        $win = $this->pageWindow($this->query('page'), $this->query('size'), 200);
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];

        $service = resolve(WalletService::class);
        $keyword = $this->query('keyword', null);
        // 列表展示的 member_id 已混淆，检索词按 HashId 还原为内部 int 再进服务层
        if (is_string($keyword) && $keyword !== '' && !ctype_digit($keyword)) {
            $decoded = \app\support\HashId::dec($keyword);
            if ($decoded !== null) {
                $keyword = (string) $decoded;
            }
        }

        $tenantId = $this->effectiveTenantId();
        $r = $service->list($tenantId, size: $size, keyword: $keyword, offset: $offset);
        $r['data'] = array_map(static fn (array $row): array => self::hashOut($row), $r['data']);

        return $this->page($r['data'], $r['total'], $page, $size);
    }

    public function show()
    {
        $memberId = $this->eid('member_id');
        if ($memberId === null) {
            return $this->fail('不存在', 404);
        }
        $service = resolve(WalletService::class);
        $tenantId = $this->effectiveTenantId();
        $wallet = $service->ensure($memberId, (int) $tenantId);

        // 归属鉴权：非 admin 时校验 tenant 匹配
        if (!$this->isAdmin() && (int) $wallet['tenant_id'] !== (int) $tenantId) {
            return $this->fail('无权访问', 403);
        }

        return $this->ok(self::hashOut($wallet));
    }

    public function stats()
    {
        $service = resolve(WalletService::class);
        $tenantId = $this->effectiveTenantId();
        $stats = $service->stats($tenantId);
        return $this->ok($stats);
    }

    public function batchOpen()
    {
        $body = (array) $this->input([]);
        // 入参为对外 HashId 串（管理页从列表复制）；兼容直接传内部 int
        $members = [];
        foreach ((array) ($body['member_ids'] ?? []) as $raw) {
            $mid = is_string($raw) && !ctype_digit($raw) ? \app\support\HashId::dec($raw) : (int) $raw;
            if ($mid !== null && $mid > 0) {
                $members[] = $mid;
            }
        }
        $members = array_values(array_unique($members));
        if ($members === []) {
            return $this->fail('member_ids 不能为空', 400);
        }
        if (count($members) > 500) {
            return $this->fail('批量开户一次最多 500 个', 400);
        }

        $service = resolve(WalletService::class);
        $tenantId = (int) $this->effectiveTenantId();
        $opened = 0;
        $skipped = 0;
        foreach ($members as $mid) {
            try {
                $wallet = $service->ensure($mid, $tenantId);
                if ((int) ($wallet['status'] ?? 1) === 1) {
                    $opened++;
                }
            } catch (\Throwable) {
                $skipped++;
            }
        }

        return $this->ok(['opened' => $opened, 'skipped' => $skipped, 'total' => count($members)]);
    }

    public function adjust()
    {
        $body = (array) $this->input([]);
        // 入参 member_id 为对外 HashId 串，解码后才进服务层
        $memberId = \app\support\HashId::dec((string) ($body['member_id'] ?? ''));
        $amount = $body['amount'] ?? null;
        $reason = (string) ($body['reason'] ?? '');
        $meta = (array) ($body['meta'] ?? []);

        if ($memberId === null) {
            return $this->fail('member_id 非法', 400);
        }
        if ($amount === null || !is_numeric($amount)) {
            return $this->fail('amount 非法', 400);
        }
        if (trim($reason) === '') {
            return $this->fail('reason 必填（审计需要）', 400);
        }

        $service = resolve(WalletService::class);
        try {
            $wallet = $service->adjust($memberId, (float) $amount, $reason, $this->operator(), $meta);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->fail($e->getMessage(), 400);
        }

        return $this->ok(['wallet' => self::hashOut($wallet)]);
    }

    public function transfer()
    {
        $body = (array) $this->input([]);
        // 入参双方 member_id 均为对外 HashId 串，解码后才进服务层
        $fromMemberId = \app\support\HashId::dec((string) ($body['from_member_id'] ?? ''));
        $toMemberId = \app\support\HashId::dec((string) ($body['to_member_id'] ?? ''));
        $amount = $body['amount'] ?? null;
        $reason = (string) ($body['reason'] ?? '');
        $meta = (array) ($body['meta'] ?? []);

        if ($fromMemberId === null || $toMemberId === null) {
            return $this->fail('from_member_id / to_member_id 非法', 400);
        }
        if ($amount === null || !is_numeric($amount)) {
            return $this->fail('amount 非法', 400);
        }
        if (trim($reason) === '') {
            return $this->fail('reason 必填', 400);
        }

        $service = resolve(WalletService::class);
        try {
            $r = $service->transfer($fromMemberId, $toMemberId, (float) $amount, $reason, $this->operator(), $meta);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->fail($e->getMessage(), 400);
        }
        // 出参：from / to 两行钱包的 id、member_id 混淆
        foreach (['from', 'to'] as $k) {
            if (isset($r[$k]) && is_array($r[$k])) {
                $r[$k] = self::hashOut($r[$k]);
            }
        }

        return $this->ok($r);
    }

    public function export()
    {
        // CSV 流式导出：按 offset 游标翻页，避免一次性加载内存爆。
        //
        // 这里原来是 `do { ... } while (count($r['data']) === 500)`，而服务层把 size 夹成 200：
        // 第一页永远只回 200 行，判据恒 false，于是「导出」只给了前 200 条却回 200 OK，
        // 差额在页面上完全看不出来。夹取收进 `PageWindow` 一处之后，`count === size`
        // 才重新是「还有下一页」的真判据；撞到行数天花板时把截断写进 CSV 正文——
        // BOM 和表头已经发出去了，状态码这时候改不了。
        $service = resolve(WalletService::class);
        $tenantId = $this->effectiveTenantId();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wallets_' . date('Ymd_His') . '.csv"');
        // BOM 保证 Excel 中文不乱码。
        echo "\xEF\xBB\xBF";
        echo "member_id,tenant_id,balance,frozen,currency,status,created_at\n";

        $size = self::EXPORT_PAGE_SIZE;
        $out = fopen('php://output', 'w');
        $offset = 0;
        $written = 0;
        while (true) {
            $r = $service->list($tenantId, $size, null, $offset);
            $batch = count($r['data']);
            $written += $batch;
            foreach ($r['data'] as $row) {
                $vals = [
                    // 对外不暴露内部自增 member_id，导出同样走 HashId 混淆
                    \app\support\HashId::enc((int) $row['member_id']),
                    $row['tenant_id'], $row['balance'], $row['frozen'],
                    $row['currency'], $row['status'], $row['created_at'],
                ];
                // 用 fputcsv 处理逗号/换行/引号转义。
                fputcsv($out, $vals);
            }
            if ($batch < $size) {
                break;
            }
            if ($written >= self::EXPORT_MAX_ROWS) {
                fwrite($out, sprintf("# truncated: 只导出前 %d 行，请按 tenant_id 过滤后分批导出\n", $written));
                break;
            }
            $offset += $size;
        }
        fclose($out);

        return $this->ok(null);
    }

    /**
     * GET /api/wallet/recent — 最近交易流水（跨钱包）。
     *
     * 返回按时间倒序的最近 N 笔账本记录（含 member_id 以便前端聚合展示），
     * 支持按 type 过滤。用于钱包仪表盘"最近活动"区。
     *
     * Query: ?limit=<1-100> & type=<type> & days=<1-30>
     */
    public function recent()
    {
        $limit = min(100, max(1, (int) $this->query('limit', 20)));
        $type = $this->query('type', null);
        $days = min(30, max(1, (int) $this->query('days', 7)));

        $tenantId = $this->effectiveTenantId();
        $where = ['created_at >= NOW() - ?::INTERVAL'];
        $params = [$days . ' days'];
        if ($tenantId !== null) {
            $where[] = 'tenant_id = ?';
            $params[] = $tenantId;
        }
        if ($type !== null && $type !== '') {
            $where[] = 'type = ?';
            $params[] = $type;
        }
        $params[] = $limit;

        $rows = Db::select(
            'SELECT l.id, l.wallet_id, w.member_id, w.tenant_id, w.currency, l.type, l.amount,
                    l.before_balance, l.after_balance, l.reason, l.operator,
                    l.counterparty_wallet_id, l.created_at
             FROM kode_wallet_ledgers l
             JOIN kode_wallets w ON w.id = l.wallet_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY l.id DESC LIMIT ?',
            $params
        );
        $rows = array_map(
            static fn (array $row): array => self::hashOut($row, ['id', 'wallet_id', 'member_id', 'counterparty_wallet_id']),
            $rows
        );

        return $this->ok($rows);
    }

    // --- 内部工具 ---

    /** 归属租户：admin 可跨租户（null）；普通用户强制自己租户。 */
    private function effectiveTenantId(): ?int
    {
        if ($this->isAdmin()) {
            // 允许通过 query tenant_id 过滤超管视图。
            $tid = $this->query('tenant_id', null);
            return $tid === null || $tid === '' ? null : (int) $tid;
        }
        $payload = Request::attr('auth');
        return (int) ($payload->tenant_id ?? 0);
    }

    private function isAdmin(): bool
    {
        $payload = Request::attr('auth');
        if ($payload === null) {
            return false;
        }
        if ((int) ($payload->uid ?? 0) === 1) {
            return true;
        }
        return in_array('admin', (array) ($payload->roles ?? []), true);
    }

    private function operator(): string
    {
        $payload = Request::attr('auth');
        if ($payload === null) {
            return 'system';
        }
        return (string) ($payload->username ?? ('uid:' . ($payload->uid ?? 0)));
    }
}
