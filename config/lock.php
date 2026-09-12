<?php

/*
 * 分布式锁配置（薄壳层）
 *
 * 框架内置「契约 + 内置静态后端」，不重新发明分布式协调算法：
 *
 *  - driver = memory：进程内静态表，覆盖单实例与同进程内并发（Fiber / 协程 / 同请求多次调用）；
 *  - driver = file：文件落盘（默认 storage_path('framework/locks')），覆盖同主机多进程
 *    （多 worker / queue:work 多进程 / 同机多副本 cron）互斥。
 *
 * 跨主机分布式锁（Redis / etcd / DB）不在此实现——在应用层实现
 * Kode\Framework\Lock\LockManager 并经 config/app.php 的 providers 绑定即可零改动替换。
 *
 * 用法：
 *   lock()->acquire('cron:daily', 60);          // 返回 bool
 *   lock()->run('report:gen', fn () => build(), 120);  // 获取→执行→释放，失败抛 LockAcquireException
 *   $r = lock()?->release('cron:daily');         // 仅 owner 可释放
 *
 * TTL 到期自动释放（惰性过期，无需后台 reaper）。owner 令牌由管理器实例持有，
 * 释放 / 强制释放仅当 owner 匹配（或强制）时生效，避免多副本误释放。
 *
 * 看门狗（watchdog）：长任务防锁过期被抢
 *   watchdog('report:daily', fn () => build(), 120);   // 持有锁期间自动续期
 *   续期间隔 = ttl * renew_ratio（默认 0.34，即每 ~1/3 TTL 续期一次）；
 *   driver = auto（优先 fiber 协程续期，失败回退 PHP tick）/ fiber（需调度器上下文）/ tick（纯 PHP）。
 */

return [
    'driver' => (string) env('LOCK_DRIVER', 'memory'),

    // file 模式的自定义目录（留空则默认 storage_path('framework/locks')）
    'path' => env('LOCK_PATH', ''),

    // 看门狗自动续期配置（长任务防止锁 TTL 过期被其他副本抢占）
    'watchdog' => [
        // 是否启用看门狗（关闭后 watchdog() 仍可用，仅续期被禁用——一般不关闭）
        'enabled' => (bool) env('LOCK_WATCHDOG_ENABLED', true),
        // 续期调度驱动：auto | fiber | tick
        'driver' => env('LOCK_WATCHDOG_DRIVER', 'auto'),
        // 续期间隔占 TTL 的比例（0~1）；实际间隔 = max(1, ceil(ttl * renew_ratio))
        'renew_ratio' => (float) env('LOCK_WATCHDOG_RENEW_RATIO', 0.34),
    ],
];
