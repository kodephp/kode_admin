<?php

declare(strict_types=1);

namespace Kode\Plugins\wallet;

use app\support\PluginConfig;
use Kode\Database\Db\Db;
use Kode\Framework\Facades\Event;

/**
 * 钱包业务服务：所有余额变动的唯一入口。
 *
 * 契约：
 *  - **账本先行**：每次余额变动都在同一事务内写 ledger，含 before/after 快照；
 *  - **类型白名单**：type 限定在 TYPES，防自由字符串污染账本；
 *  - **符号一致**：credit/adjust+ 为正、debit/adjust- 为负，避免"金额符号漂移"；
 *  - **多租户隔离**：所有查询/写入都带 tenant_id 过滤，超管可跨租户查看（tenant_id=null）。
 *
 * 不做：
 *  - 不做支付渠道对接（属于支付插件范畴）；
 *  - 不做跨租户转账（业务上应通过退款/清算流程）；
 *  - 不做余额缓存（DB 为准，前端展示时按需聚合）。
 */
final class WalletService
{
    public const TYPE_CREATED      = 'created';
    public const TYPE_CREDIT       = 'credit';
    public const TYPE_DEBIT        = 'debit';
    public const TYPE_FREEZE       = 'freeze';
    public const TYPE_UNFREEZE     = 'unfreeze';
    public const TYPE_ADJUST       = 'adjust';
    public const TYPE_TRANSFER_IN  = 'transfer_in';
    public const TYPE_TRANSFER_OUT = 'transfer_out';
    public const TYPE_REFUND       = 'refund';

    /** 事件名（与 PluginContract::events() 声明一致；监听方在 PluginManager 上注册）。 */
    public const EVENT_CREATED  = 'wallet.created';
    public const EVENT_BALANCE  = 'wallet.balance';
    public const EVENT_ADJUSTED = 'wallet.adjusted';
    public const EVENT_TRANSFERRED = 'wallet.transferred';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_CREATED, self::TYPE_CREDIT, self::TYPE_DEBIT,
        self::TYPE_FREEZE, self::TYPE_UNFREEZE, self::TYPE_ADJUST,
        self::TYPE_TRANSFER_IN, self::TYPE_TRANSFER_OUT, self::TYPE_REFUND,
    ];

    /** 惰性开户：首次访问自动建钱包。 */
    public function ensure(int $memberId, int $tenantId = 0): array
    {
        $row = Db::select(
            'SELECT * FROM kode_wallets WHERE member_id = ? AND tenant_id = ? LIMIT 1',
            [$memberId, $tenantId]
        );
        if ($row !== []) {
            return $row[0];
        }

        $cfg = self::pluginConfig();
        $currency = (string) ($cfg['currency'] ?? 'CNY');

        // 唯一索引 (member_id, tenant_id) 兜底并发开户；DuplicateKey 时直接回读。
        try {
            Db::insert(
                'INSERT INTO kode_wallets (member_id, tenant_id, currency, status) VALUES (?, ?, ?, 1)',
                [$memberId, $tenantId, $currency]
            );
        } catch (\Throwable) {
            // 已有（并发或重试），走下方 SELECT 分支。
        }
        $row = Db::select(
            'SELECT * FROM kode_wallets WHERE member_id = ? AND tenant_id = ? LIMIT 1',
            [$memberId, $tenantId]
        );
        $wallet = $row[0] ?? null;
        if ($wallet === null) {
            throw new \RuntimeException('钱包创建失败');
        }
        // 只有真正的"首次"账户才写 created 账本：以 wallet 是否已有账本判断。
        $existsLedger = Db::select('SELECT COUNT(*) AS c FROM kode_wallet_ledgers WHERE wallet_id = ?', [(int) $wallet['id']])[0];
        if ((int) ($existsLedger['c'] ?? 0) === 0) {
            $this->writeLedger($wallet, self::TYPE_CREATED, '0', '0', '系统开户', '', null, [
                'auto_open' => (bool) ($cfg['auto_open'] ?? true),
            ]);
            self::emit(self::EVENT_CREATED, [
                'member_id' => (int) $wallet['member_id'],
                'wallet_id' => (int) $wallet['id'],
                'tenant_id' => (int) $wallet['tenant_id'],
                'currency'  => (string) $wallet['currency'],
                'auto_open' => (bool) ($cfg['auto_open'] ?? true),
            ]);
        }

        return $wallet;
    }

    /**
     * 充值：仅 balance 增加。
     * @param string|int $amount 金额（正数）
     */
    public function credit(int $memberId, string|int|float $amount, string $reason = '', string $operator = '', array $meta = []): array
    {
        $amount = $this->normalizeAmount((float) $amount);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('充值金额必须大于 0');
        }
        return $this->mutate($memberId, self::TYPE_CREDIT, +$amount, $reason, $operator, $meta, 0);
    }

    /**
     * 扣款：仅 balance 减少；余额不足按 allow_negative 决定是否允许透支。
     */
    public function debit(int $memberId, string|int|float $amount, string $reason = '', string $operator = '', array $meta = []): array
    {
        $amount = $this->normalizeAmount((float) $amount);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('扣款金额必须大于 0');
        }
        return $this->mutate($memberId, self::TYPE_DEBIT, -$amount, $reason, $operator, $meta, 0);
    }

    /**
     * 人工调整：正数加、负数减；金额绝对值需 <= daily_limit（0 不限）。
     */
    public function adjust(int $memberId, string|int|float $amount, string $reason = '', string $operator = '', array $meta = []): array
    {
        $amount = $this->normalizeAmount((float) $amount);
        if ($amount == 0) {
            throw new \InvalidArgumentException('调整金额不能为 0');
        }
        $cfg = self::pluginConfig();
        $limit = (float) ($cfg['daily_limit'] ?? 0);
        if ($limit > 0 && abs($amount) > $limit) {
            throw new \InvalidArgumentException(sprintf('单日调整上限 %s，超出', $limit));
        }
        return $this->mutate($memberId, self::TYPE_ADJUST, $amount, $reason, $operator, $meta, 0);
    }

    /**
     * 冻结/解冻：只在 frozen 与 balance 之间转移，不动 ledger 之外的余额。
     */
    public function freeze(int $memberId, string|int|float $amount, string $reason = '', string $operator = '', array $meta = []): array
    {
        $amount = $this->normalizeAmount((float) $amount);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('冻结金额必须大于 0');
        }
        return $this->mutate($memberId, self::TYPE_FREEZE, -$amount, $reason, $operator, $meta, +$amount);
    }

    public function unfreeze(int $memberId, string|int|float $amount, string $reason = '', string $operator = '', array $meta = []): array
    {
        $amount = $this->normalizeAmount((float) $amount);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('解冻金额必须大于 0');
        }
        return $this->mutate($memberId, self::TYPE_UNFREEZE, +$amount, $reason, $operator, $meta, -$amount);
    }

    /**
     * 钱包间转账：同租户内；跨租户禁止（业务应走退款/清算）。
     */
    public function transfer(int $fromMemberId, int $toMemberId, string|int|float $amount, string $reason = '', string $operator = '', array $meta = []): array
    {
        $amount = $this->normalizeAmount((float) $amount);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('转账金额必须大于 0');
        }
        if ($fromMemberId === $toMemberId) {
            throw new \InvalidArgumentException('不能转账给自己');
        }

        $from = $this->ensure($fromMemberId);
        $to = $this->ensure($toMemberId);
        if ((int) $from['tenant_id'] !== (int) $to['tenant_id']) {
            throw new \InvalidArgumentException('不支持跨租户转账');
        }

        Db::transaction(function () use ($from, $to, $amount, $reason, $operator, $meta) {
            // 悲观锁：先锁 from，再锁 to，避免并发下余额读-改-写错乱。
            Db::statement(
                'SELECT * FROM kode_wallets WHERE id = ? FOR UPDATE',
                [(int) $from['id']]
            );
            Db::statement(
                'SELECT * FROM kode_wallets WHERE id = ? FOR UPDATE',
                [(int) $to['id']]
            );
            $fromFresh = Db::select('SELECT * FROM kode_wallets WHERE id = ?', [(int) $from['id']])[0];
            $toFresh = Db::select('SELECT * FROM kode_wallets WHERE id = ?', [(int) $to['id']])[0];

            $fromBefore = (float) $fromFresh['balance'];
            $toBefore = (float) $toFresh['balance'];
            $fromAfter = $this->normalizeAmount($fromBefore - $amount);
            $toAfter = $this->normalizeAmount($toBefore + $amount);
            $cfg = self::pluginConfig();
            if (!$cfg['allow_negative'] && $fromAfter < 0) {
                throw new \RuntimeException('转出方余额不足且不允许透支');
            }

            Db::update(
                'UPDATE kode_wallets SET balance = ?, updated_at = NOW() WHERE id = ?',
                [$fromAfter, (int) $fromFresh['id']]
            );
            Db::update(
                'UPDATE kode_wallets SET balance = ?, updated_at = NOW() WHERE id = ?',
                [$toAfter, (int) $toFresh['id']]
            );

            $this->writeLedger($fromFresh, self::TYPE_TRANSFER_OUT, -$amount, $fromBefore, $fromAfter, $reason, $operator, (int) $toFresh['id'], $meta);
            $this->writeLedger($toFresh, self::TYPE_TRANSFER_IN, +$amount, $toBefore, $toAfter, $reason, $operator, (int) $fromFresh['id'], $meta);
        });

        $fromAfterRow = Db::select('SELECT * FROM kode_wallets WHERE id = ?', [(int) $from['id']])[0];
        $toAfterRow = Db::select('SELECT * FROM kode_wallets WHERE id = ?', [(int) $to['id']])[0];

        // 事件：转账双方各发一次 balance；另发一次 transferred 表示"这笔转账"整体事件
        self::emit(self::EVENT_BALANCE, [
            'wallet_id' => (int) $fromAfterRow['id'], 'member_id' => (int) $fromAfterRow['member_id'],
            'tenant_id' => (int) $fromAfterRow['tenant_id'], 'type' => self::TYPE_TRANSFER_OUT,
            'amount'    => -$amount, 'before' => $fromBefore, 'after' => $fromAfter,
            'reason'    => $reason, 'operator' => $operator,
            'counterparty' => (int) $toAfterRow['id'],
        ]);
        self::emit(self::EVENT_BALANCE, [
            'wallet_id' => (int) $toAfterRow['id'], 'member_id' => (int) $toAfterRow['member_id'],
            'tenant_id' => (int) $toAfterRow['tenant_id'], 'type' => self::TYPE_TRANSFER_IN,
            'amount'    => +$amount, 'before' => $toBefore, 'after' => $toAfter,
            'reason'    => $reason, 'operator' => $operator,
            'counterparty' => (int) $fromAfterRow['id'],
        ]);
        self::emit(self::EVENT_TRANSFERRED, [
            'from_wallet_id' => (int) $fromAfterRow['id'],
            'from_member_id' => (int) $fromAfterRow['member_id'],
            'to_wallet_id'   => (int) $toAfterRow['id'],
            'to_member_id'   => (int) $toAfterRow['member_id'],
            'tenant_id'      => (int) $fromAfterRow['tenant_id'],
            'amount'         => $amount,
            'reason'         => $reason,
            'operator'       => $operator,
        ]);

        return [
            'from' => $fromAfterRow,
            'to'   => $toAfterRow,
            'amount' => $amount,
        ];
    }

    /**
     * 列表：分页 + 关键字（member_id 匹配）+ 租户过滤。
     *
     * 入参是 `offset` 而不是 `page`：窗口判定只在调用方（控制器）发生一次，
     * 口径见 `app\concerns\PageWindow`。仓储层不再自己夹取、也不再乘 `($page-1)*$size`，
     * 否则「谁决定翻多远」就有了第二份答案。
     *
     * @param int|null $tenantId null=超管跨租户
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function list(?int $tenantId, int $size, ?string $keyword, int $offset): array
    {
        $where = [];
        $params = [];
        if ($tenantId !== null) {
            $where[] = 'tenant_id = ?';
            $params[] = $tenantId;
        }
        if ($keyword !== null && $keyword !== '') {
            $where[] = '(member_id = ? OR currency = ?)';
            $params[] = (int) $keyword;
            $params[] = $keyword;
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) (Db::select('SELECT COUNT(*) AS c FROM kode_wallets' . $whereSql, $params)[0]['c'] ?? 0);

        $params[] = $size;
        $params[] = $offset;
        $rows = Db::select(
            'SELECT id, member_id, tenant_id, balance, frozen, currency, status, note, created_at, updated_at FROM kode_wallets' . $whereSql . ' ORDER BY id DESC LIMIT ? OFFSET ?',
            $params
        );
        return ['data' => $rows, 'total' => $total];
    }

    /** 汇总统计：按租户（或跨租户）聚合。 */
    public function stats(?int $tenantId): array
    {
        $where = $tenantId === null ? '' : ' WHERE tenant_id = ?';
        $params = $tenantId === null ? [] : [$tenantId];
        $agg = Db::select(
            'SELECT COUNT(*) AS members, COALESCE(SUM(balance), 0) AS total_balance, COALESCE(SUM(frozen), 0) AS total_frozen FROM kode_wallets' . $where,
            $params
        )[0];

        $ledgerParams = $params;
        $ledgerWhere = $where;
        $ledgerRows = Db::select(
            'SELECT type, COUNT(*) AS c, COALESCE(SUM(amount), 0) AS total_amount FROM kode_wallet_ledgers' . $ledgerWhere . ' GROUP BY type',
            $ledgerParams
        );
        $byType = [];
        foreach ($ledgerRows as $r) {
            $byType[(string) $r['type']] = [
                'count' => (int) $r['c'],
                'total_amount' => (float) $r['total_amount'],
            ];
        }

        return [
            'members'       => (int) $agg['members'],
            'total_balance' => (float) $agg['total_balance'],
            'total_frozen'  => (float) $agg['total_frozen'],
            'by_type'       => $byType,
        ];
    }

    // --- 内部工具 ---

    /** 执行单笔余额变动（内部）：事务 + 快照 + ledger。 */
    private function mutate(int $memberId, string $type, float $amount, string $reason, string $operator, array $meta, float $frozenDelta, int $tenantId = 0): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("非法变动类型: {$type}");
        }
        $tenantId = (int) ($meta['tenant_id'] ?? $tenantId);
        $wallet = $this->ensure($memberId, $tenantId);

        Db::transaction(function () use ($wallet, $type, $amount, $reason, $operator, $meta, $frozenDelta) {
            $locked = Db::select(
                'SELECT * FROM kode_wallets WHERE id = ? FOR UPDATE',
                [(int) $wallet['id']]
            );
            if ($locked === []) {
                throw new \RuntimeException('钱包不存在或已删除');
            }
            $w = $locked[0];
            $before = (float) $w['balance'];
            $after = $this->normalizeAmount($before + $amount);
            $frozenBefore = (float) $w['frozen'];
            $frozenAfter = $frozenDelta === 0.0 ? $frozenBefore : $this->normalizeAmount($frozenBefore + $frozenDelta);
            $cfg = self::pluginConfig();
            if (!$cfg['allow_negative'] && $after < 0) {
                throw new \RuntimeException('余额不足且不允许透支');
            }

            Db::update(
                'UPDATE kode_wallets SET balance = ?, frozen = ?, updated_at = NOW() WHERE id = ?',
                [$after, $frozenAfter, (int) $w['id']]
            );

            $this->writeLedger($w, $type, $amount, $before, $after, $reason, $operator, null, $meta);
        });

        $fresh = Db::select('SELECT * FROM kode_wallets WHERE id = ?', [(int) $wallet['id']])[0];

        // 事件：balance（所有非 created 类型），adjusted（adjust），transferred（transfer_in/out）
        if ($type !== self::TYPE_CREATED) {
            self::emit(self::EVENT_BALANCE, [
                'wallet_id'    => (int) $fresh['id'],
                'member_id'    => (int) $fresh['member_id'],
                'tenant_id'    => (int) $fresh['tenant_id'],
                'type'         => $type,
                'amount'       => $amount,
                'before'       => $before,
                'after'        => $after,
                'reason'       => $reason,
                'operator'     => $operator,
                'counterparty' => null,
                'meta'         => $meta,
            ]);
            if ($type === self::TYPE_ADJUST) {
                self::emit(self::EVENT_ADJUSTED, [
                    'wallet_id' => (int) $fresh['id'],
                    'member_id' => (int) $fresh['member_id'],
                    'tenant_id' => (int) $fresh['tenant_id'],
                    'amount'    => $amount,
                    'reason'    => $reason,
                    'operator'  => $operator,
                ]);
            }
            if ($type === self::TYPE_TRANSFER_IN || $type === self::TYPE_TRANSFER_OUT) {
                self::emit(self::EVENT_TRANSFERRED, [
                    'wallet_id'    => (int) $fresh['id'],
                    'member_id'    => (int) $fresh['member_id'],
                    'tenant_id'    => (int) $fresh['tenant_id'],
                    'direction'    => $type === self::TYPE_TRANSFER_IN ? 'in' : 'out',
                    'amount'       => $amount,
                    'counterparty' => null,
                    'reason'       => $reason,
                    'operator'     => $operator,
                ]);
            }
        }

        return $fresh;
    }

    /** 写账本；amount 带符号（正=入，负=出）。 */
    private function writeLedger(array $wallet, string $type, string|int|float $amount, string|int|float $before, string|int|float $after, string $reason, string $operator, ?int $counterparty, array $meta = []): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("非法账本类型: {$type}");
        }
        $amountF = $this->normalizeAmount((float) $amount);
        Db::insert(
            'INSERT INTO kode_wallet_ledgers (wallet_id, member_id, tenant_id, type, amount, before_balance, after_balance, reason, operator, counterparty_wallet_id, meta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $wallet['id'],
                (int) $wallet['member_id'],
                (int) $wallet['tenant_id'],
                $type,
                $amountF,
                $this->normalizeAmount((float) $before),
                $this->normalizeAmount((float) $after),
                mb_substr($reason, 0, 255),
                mb_substr($operator, 0, 64),
                $counterparty,
                $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /** 按插件 decimal_places 截断金额，避免浮点漂移。 */
    private function normalizeAmount(float $amount): float
    {
        $places = (int) (self::pluginConfig()['decimal_places'] ?? 2);
        return round($amount, $places);
    }

    /** 读插件配置；插件未启用/未配置时返回安全默认。 */
    private static function pluginConfig(): array
    {
        try {
            $cfg = PluginConfig::all('wallet');
        } catch (\Throwable) {
            $cfg = [];
        }
        $defaults = [
            'currency' => 'CNY', 'decimal_places' => 2, 'daily_limit' => 0,
            'allow_negative' => false, 'auto_open' => true,
        ];
        return array_merge($defaults, $cfg);
    }

    /**
     * 派发事件（容错）：事件系统未就绪时静默降级（不影响主业务）。
     * 与事件契约对齐：wallet.created / wallet.balance / wallet.adjusted / wallet.transferred。
     */
    private static function emit(string $name, array $payload): void
    {
        try {
            Event::dispatch($name, $payload);
        } catch (\Throwable) {
            // 事件系统异常不影响余额主流程。
        }
    }
}
