<?php

declare(strict_types=1);

namespace Kode\Plugins;

use app\admin\middleware\RequirePerm;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Middleware\AuthMiddleware;
use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;
use Kode\Plugins\wallet\WalletController;
use Kode\Plugins\wallet\WalletLedgerController;
use Kode\Plugins\wallet\WalletService;

/**
 * 会员钱包插件（免费内置）。
 *
 * 设计要点：
 *  - **每会员一钱包**：钱包实体是"账户"，非"资金池"。惰性开户（首次访问自动建）+ 支持批量预开户。
 *  - **多租户**：钱包带 tenant_id；租户侧只看自己的数据；超管统一视图。
 *  - **账本先行**：所有余额变动都必须写 ledger（含 before/after 快照），审计可回溯。
 *  - **权限码门禁**：`wallet:view` / `wallet:create` / `wallet:adjust` / `wallet:transfer` / `wallet:export`。
 *  - **契约齐备**：tables / menus / permissions / configSchema / events 全部声明，插件契约审计无漂移。
 *
 * 路由前缀：`/api/wallet/*`（读挂 auth + 权限码，写再套 admin 门禁）。
 */
final class WalletPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'wallet';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return '会员钱包：每会员一账户，多租户隔离，账本先行，支持惰性开户/批量开户/调整/转账/统计。';
    }

    public function license(): string
    {
        return 'MIT';
    }

    /** @return list<string> */
    public function tables(): array
    {
        return ['kode_wallets', 'kode_wallet_ledgers'];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['wallet:view', 'wallet:create', 'wallet:adjust', 'wallet:transfer', 'wallet:export'];
    }

    /**
     * @return list<array{title: string, path: string, icon: string, auth_code: string}>
     */
    public function menus(): array
    {
        return [
            [
                'title'     => '会员钱包',
                'path'      => '/wallet',
                'icon'      => 'wallet',
                'auth_code' => 'wallet:view',
                'parent'    => '/users',
            ],
        ];
    }

    /**
     * 插件配置契约（PluginController::saveConfig 走同一入口）。
     * 类型: string | number | boolean；secret=true 的字段回显为 true（不泄漏明文）。
     */
    public function configSchema(): array
    {
        return [
            'currency'       => ['type' => 'string', 'default' => 'CNY', 'label' => '货币符号', 'options' => ['CNY', 'USD', 'EUR', 'HKD']],
            'decimal_places' => ['type' => 'number', 'default' => 2, 'label' => '金额小数位', 'min' => 0, 'max' => 6],
            'daily_limit'    => ['type' => 'number', 'default' => 0, 'label' => '单日调整上限（0=不限）'],
            'allow_negative' => ['type' => 'boolean', 'default' => false, 'label' => '允许透支'],
            'auto_open'      => ['type' => 'boolean', 'default' => true, 'label' => '惰性开户'],
        ];
    }

    /**
     * 事件声明（契约语义：本插件**监听**哪些事件）。
     *
     * PluginContract::auditEvents() 的口径是「声明即须兑现」——每条声明都必须在
     * register() 里用 PluginManager::addListener() 注册进 Dispatcher，否则事件
     * 派发时本插件收不到（声明⟺实现漂移）。
     *
     * 别把两个方向混为一谈：
     *  - **监听**（本方法声明）：user.created / user.login.success，由本插件消费，
     *    用于自动开户；
     *  - **派发**：wallet.created / wallet.balance / wallet.adjusted /
     *    wallet.transferred 是本插件对外 emit 的业务事件（emit 点在
     *    WalletService::emit()，常量见 WalletService::EVENT_*）。契约目前没有
     *    「派发声明」通道，故不在此列——混进来会被审计判成 4 条漂移。
     *
     * @return array<string, array{0: class-string, 1: string}> 事件名 => 监听器
     */
    public function events(): array
    {
        return [
            'user.created'       => [self::class, 'onUserCreated'],
            'user.login.success' => [self::class, 'onUserLogin'],
        ];
    }

    /** 安装：建表 + 索引（幂等）。 */
    public function install(): void
    {
        Db::statement('
            CREATE TABLE IF NOT EXISTS kode_wallets (
                id BIGSERIAL PRIMARY KEY,
                member_id BIGINT NOT NULL,
                tenant_id BIGINT NOT NULL DEFAULT 0,
                balance NUMERIC(18, 4) NOT NULL DEFAULT 0,
                frozen  NUMERIC(18, 4) NOT NULL DEFAULT 0,
                currency VARCHAR(8)  NOT NULL DEFAULT \'CNY\',
                status SMALLINT NOT NULL DEFAULT 1,
                note TEXT NOT NULL DEFAULT \'\',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');
        Db::statement('CREATE INDEX IF NOT EXISTS idx_wallets_member ON kode_wallets (member_id)');
        Db::statement('CREATE INDEX IF NOT EXISTS idx_wallets_tenant ON kode_wallets (tenant_id)');
        Db::statement('CREATE UNIQUE INDEX IF NOT EXISTS uk_wallets_member_tenant ON kode_wallets (member_id, tenant_id)');

        Db::statement('
            CREATE TABLE IF NOT EXISTS kode_wallet_ledgers (
                id BIGSERIAL PRIMARY KEY,
                wallet_id BIGINT NOT NULL,
                member_id BIGINT NOT NULL,
                tenant_id BIGINT NOT NULL DEFAULT 0,
                type VARCHAR(24) NOT NULL,
                amount NUMERIC(18, 4) NOT NULL,
                before_balance NUMERIC(18, 4) NOT NULL DEFAULT 0,
                after_balance  NUMERIC(18, 4) NOT NULL DEFAULT 0,
                reason VARCHAR(255) NOT NULL DEFAULT \'\',
                operator VARCHAR(64) NOT NULL DEFAULT \'\',
                counterparty_wallet_id BIGINT NULL,
                meta JSONB NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');
        Db::statement('CREATE INDEX IF NOT EXISTS idx_ledgers_wallet ON kode_wallet_ledgers (wallet_id, created_at DESC)');
        Db::statement('CREATE INDEX IF NOT EXISTS idx_ledgers_member ON kode_wallet_ledgers (member_id, created_at DESC)');
        Db::statement('CREATE INDEX IF NOT EXISTS idx_ledgers_tenant ON kode_wallet_ledgers (tenant_id, created_at DESC)');
        Db::statement('CREATE INDEX IF NOT EXISTS idx_ledgers_type ON kode_wallet_ledgers (type)');
    }

    /** 卸载：默认保留数据；$purge=true 时删表（调用方必须二次确认 + 备份）。 */
    public function uninstall(bool $purge = false): void
    {
        if (!$purge) {
            return;
        }
        foreach ($this->tables() as $table) {
            if (!preg_match('/^[a-z0-9_]{2,64}$/', $table)) {
                continue;
            }
            Db::statement("DROP TABLE IF EXISTS {$table}");
        }
    }

    public function upgrade(string $from, string $to): void
    {
        // 幂等：install() 已用 IF NOT EXISTS。
    }

    public function register(PluginManager $manager): void
    {
        $auth   = new AuthMiddleware();
        $view   = new RequirePerm('wallet:view');
        $create = new RequirePerm('wallet:create');
        $adjust = new RequirePerm('wallet:adjust');
        $transfer = new RequirePerm('wallet:transfer');
        $export = new RequirePerm('wallet:export');

        // 读
        $manager->addRoute('wallet.list', 'GET', '/api/wallet/members', [WalletController::class, 'list'])
            ->middleware($auth, $view);
        // member_id 对外为 HashId 不透明串，约束放行字母数字（AGENTS.md §4，勿用 \d+）
        $manager->addRoute('wallet.get', 'GET', '/api/wallet/members/{member_id:[A-Za-z0-9]+}', [WalletController::class, 'show'])
            ->middleware($auth, $view);
        $manager->addRoute('wallet.ledger', 'GET', '/api/wallet/members/{member_id:[A-Za-z0-9]+}/ledger', [WalletLedgerController::class, 'list'])
            ->middleware($auth, $view);
        $manager->addRoute('wallet.stats', 'GET', '/api/wallet/stats', [WalletController::class, 'stats'])
            ->middleware($auth, $view);
        $manager->addRoute('wallet.recent', 'GET', '/api/wallet/recent', [WalletController::class, 'recent'])
            ->middleware($auth, $view);
        $manager->addRoute('wallet.export', 'GET', '/api/wallet/export', [WalletController::class, 'export'])
            ->middleware($auth, $export);

        // 写
        $manager->addRoute('wallet.open', 'POST', '/api/wallet/batch-open', [WalletController::class, 'batchOpen'])
            ->middleware($auth, $create);
        $manager->addRoute('wallet.adjust', 'POST', '/api/wallet/adjust', [WalletController::class, 'adjust'])
            ->middleware($auth, $adjust);
        $manager->addRoute('wallet.transfer', 'POST', '/api/wallet/transfer', [WalletController::class, 'transfer'])
            ->middleware($auth, $transfer);

        // ─── 生命周期钩子：用户创建/登录时自动开户 ───
        // 声明与实现在此严格对齐（见 events()）：具名静态方法，审计可逐条对账。
        $manager->addListener('user.created', [self::class, 'onUserCreated']);
        $manager->addListener('user.login.success', [self::class, 'onUserLogin']);
    }

    /**
     * user.created 监听器：新用户在管理后台创建时立即开户。
     *
     * 惰性开户（config `auto_open`）关闭时本钩子仍生效——否则用户建了却没有账户。
     */
    public static function onUserCreated(\Kode\Event\Event $event): void
    {
        self::ensureWalletFor((int) ($event->get('user_id') ?? 0), 'user.created');
    }

    /**
     * user.login.success 监听器：登录时确保钱包存在。
     *
     * 覆盖「历史用户先于本插件上线」的场景——他们永远不会被 user.created 触达。
     */
    public static function onUserLogin(\Kode\Event\Event $event): void
    {
        self::ensureWalletFor((int) ($event->get('user_id') ?? 0), 'user.login.success');
    }

    /**
     * 容错开户：事件系统或钱包服务异常一律降级为告警。
     *
     * 开户是边车逻辑，失败绝不能阻断用户的创建/登录主流程。
     */
    private static function ensureWalletFor(int $userId, string $source): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            resolve(WalletService::class)->ensure($userId);
        } catch (\Throwable $e) {
            logger()->warning("钱包自动开户失败（{$source} #{$userId}）：" . $e->getMessage());
        }
    }

    public function boot(PluginManager $manager): void
    {
        // 预注册服务，供容器解析（惰性构造）。
        $manager->bind('wallet.service', static fn() => new WalletService());
    }
}
