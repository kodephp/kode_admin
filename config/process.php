<?php

declare(strict_types=1);

/*
 * 常驻进程配置
 *
 * 在此声明要常驻运行的 worker 列表；框架启动时只**注册**到
 * Kode\Framework\Process\ProcessManager（单例，门面 Process / 助手 process()），**不会自动运行**——
 * 真正 fork 常驻进程由 `php kode process:start` 完成（常驻 worker 见 `kode process:list`）。
 *
 * workers 支持三种写法（相互兼容）：
 *   1) 无参 worker：直接写类名
 *      'workers' => [ app\process\HeartbeatWorker::class ]
 *   2) 带构造参数的 worker：写 class + config
 *      'workers' => [ ['class' => app\process\CleanupWorker::class, 'config' => ['ttl' => 3600]] ]
 *   3) 声明式增强：在 class + config 之上叠加声明键，覆盖 worker 默认行为
 *      'workers' => [
 *          [
 *              'class'    => app\process\RecurringScanWorker::class,
 *              'config'   => ['root' => storage_path('scan')], // 可选：构造参数
 *              'count'    => 3,       // 可选：并行实例数（默认取 instances()）
 *              'interval' => 5.0,     // 可选：轮询间隔秒（默认取 interval()）
 *              'once'     => false,   // 可选：true 时启动同步执行一遍即退出，不常驻
 *              'slots'    => [0],     // 可选：仅这些实例执行；[0] = 仅主进程槽位（其余实例存活占位）
 *          ],
 *      ]
 *
 * worker 类可自行决定（声明键未给时生效）：
 *   - name()      唯一名称
 *   - handle()    单次工作量（按 interval() 周期执行）
 *   - handle(int $slot = 0)  可选新签名：感知当前执行槽位（仅实例 0 干活等场景）
 *   - interval()  轮询间隔（秒）
 *   - instances() 并行实例数
 *   - slots()     执行槽位列表（[] = 全部实例，[0] = 仅主进程槽位）
 *   - once()      是否一次性任务（启动执行一遍即退出）
 *
 * 启动：kode process:start
 * 验证（不 fork）：kode process:check
 */
return [
    // 示例：启用一个演示 worker（心跳写入 storage/heartbeat.log）。
    // 验证：php bin/console process:check
    // 常驻：php bin/console process:start
    'workers' => [
        app\process\HeartbeatWorker::class,
    ],
];
