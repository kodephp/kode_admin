<?php

/*
 * 应用引导（在 ServiceProvider 全部启动后加载）
 *
 * 适合在此注册：全局事件监听器、定时任务、第三方 SDK 初始化等。
 *
 * AOP 切面不再需要在此手动注册——AopServiceProvider 已按 config/aop.php 的 paths
 * 自动发现 app/Aop（及约定目录 app/Aspects）下标注 #[Aspect] 的类并织入。
 */

use Kode\Framework\Facades\Event;

// 插件运行时门禁挂最外层：暂停/卸载插件的路由直接 404，不消耗鉴权与业务资源。
$app->http()->getDispatcher()->prepend(new \app\http\middleware\PluginGateMiddleware());

// Doctrine UnitOfWork 收口中间件（全局最内层，不干扰既有顺序）。
$app->http()->use(new \app\http\middleware\DoctrineClearMiddleware());

// 计时中间件挂内层（异常中间件之后）：打 _t0 + 校验错误信封化。
// 必须在 ExceptionMiddleware 内侧，否则校验异常先被它接走，信封化够不着。
$app->http()->use(new \app\http\middleware\TimingMiddleware());

// 读写分离：SELECT 走读库，写走主库（配置 database.read_write_split）。
try {
    if (config('database.read_write_split', false)) {
        \Kode\Database\Db\Db::enableReadWriteSplit('pgsql_read');
    }
} catch (\Throwable) {
}

// 分片库连接注册（shard_00…，配置 sharding.*；关闭时不注册零开销）。
try {
    $shardCfg = (array) config('sharding', []);
    if (!empty($shardCfg['enabled'])) {
        $dbCount = max(1, (int) ($shardCfg['db_count'] ?? 2));
        $hosts = array_values(array_filter(array_map('trim', explode(',', (string) ($shardCfg['db_hosts'] ?? '')))));
        $base = [
            'driver' => 'pgsql',
            'port' => (int) env('DB_PORT', 5432),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
        ];
        for ($i = 0; $i < $dbCount; $i++) {
            $name = sprintf('shard_%02d', $i);
            $cfg = $base + [
                'host' => $hosts[$i] ?? $hosts[0] ?? env('DB_HOST', '127.0.0.1'),
                'database' => sprintf((string) ($shardCfg['db_name'] ?? 'kode_app_%02d'), $i),
            ];
            \Kode\Database\Db\Db::addConnection($name, $cfg);
        }
    }
} catch (\Throwable) {
}

// 操作审计（只记元数据，见 AuditMiddleware）。
$app->http()->use(new \app\http\middleware\AuditMiddleware());

// 安全自检：admin 仍是初始密码则告警（启动期一次，不阻断）。
try {
    $rows = \Kode\Database\Db\Db::select('SELECT password FROM kode_users WHERE name = ? LIMIT 1', ['admin']);
    if ($rows !== [] && password_verify('ChangeMe123!', (string) ($rows[0]['password'] ?? ''))) {
        logger()->warning('安全提醒：admin 仍在使用初始密码 ChangeMe123!，请立即登录修改');
    }
} catch (\Throwable) {
    // 表不存在等情况静默跳过（migrate 前）。
}

// 事件监听：收到 PingEvent 时记录日志
Event::listen(\app\events\PingEvent::class, function (\app\events\PingEvent $e): void {
    logger()->info('收到 PingEvent: ' . $e->message);
});
