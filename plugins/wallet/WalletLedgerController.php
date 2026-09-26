<?php

declare(strict_types=1);

namespace Kode\Plugins\wallet;

use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 账本查询控制器。
 *
 * 契约：
 *  - 只读接口（无写操作）；
 *  - 归属校验：admin 可查所有；普通用户只能查自己租户的账本；
 *  - 归属通过 ledger.tenant_id 匹配（比 wallet_id 反查更安全）；
 *  - 分页窗口口径见 app\concerns\PageWindow（size 夹取、page 越界 400）。
 */
final class WalletLedgerController extends Controller
{
    use ApiResponse;
    use PageWindow;

    /**
     * 出参 id 混淆：内部自增 id → 对外 HashId 不透明串（AGENTS.md §4 防枚举）。
     * 入参一律用 eid()/HashId::dec 解码，查询保持 int；值为 null 的字段保持 null
     * （counterparty_wallet_id 非转账记录为空，不得编码成 0 的 hashid）。
     */
    private static function hashOut(array $row, array $fields = ['id']): array
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
        $memberId = $this->eid('member_id');
        if ($memberId === null) {
            return $this->fail('不存在', 404);
        }

        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];

        // 定位该 member 在指定租户下的钱包；归属校验后再放行。
        $tenantId = $this->effectiveTenantId();
        $where = ['member_id = ?', 'tenant_id = ?'];
        $params = [$memberId, (int) $tenantId];
        if ($this->isAdmin() && $tenantId === null) {
            $where = ['member_id = ?'];
            $params = [$memberId];
        }
        $wallets = Db::select(
            'SELECT id FROM kode_wallets WHERE ' . implode(' AND ', $where) . ' ORDER BY id LIMIT 5',
            $params
        );
        if ($wallets === []) {
            return $this->page([], 0, $page, $size);
        }

        $type = $this->query('type', null);

        $inSql = '(' . implode(',', array_fill(0, count($wallets), '?')) . ')';
        $walletIds = array_map(static fn($w) => (int) $w['id'], $wallets);

        $whereParts = ['wallet_id IN ' . $inSql];
        $queryParams = $walletIds;
        if ($type !== null && $type !== '') {
            $whereParts[] = 'type = ?';
            $queryParams[] = $type;
        }
        $whereSql = ' WHERE ' . implode(' AND ', $whereParts);

        $total = (int) (Db::select('SELECT COUNT(*) AS c FROM kode_wallet_ledgers' . $whereSql, $queryParams)[0]['c'] ?? 0);
        $queryParams[] = $size;
        $queryParams[] = $offset;
        $rows = Db::select(
            'SELECT id, wallet_id, type, amount, before_balance, after_balance, reason, operator, counterparty_wallet_id, meta, created_at FROM kode_wallet_ledgers' . $whereSql . ' ORDER BY id DESC LIMIT ? OFFSET ?',
            $queryParams
        );
        $rows = array_map(
            static fn (array $row): array => self::hashOut($row, ['id', 'wallet_id', 'counterparty_wallet_id']),
            $rows
        );

        return $this->page($rows, $total, $page, $size);
    }

    private function effectiveTenantId(): ?int
    {
        if ($this->isAdmin()) {
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
}
