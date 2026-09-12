<?php

/*
 * 任务调度配置
 *
 * 框架支持「约定优于配置」：在约定目录下用 #[Cron] 属性声明定时任务，
 * 启动 `kode schedule:work` 即自动发现并常驻调度（单次轮走 `kode schedule:run`）——无需逐条登记。
 *
 * schedule:list 默认只列主应用（无插件）。开发者开启 discover_plugins 后，
 * plugins/<name>/src/Tasks 中的 #[Cron] 任务才被纳入，并标记来源 plugin:<name>。
 *
 * 集群「至多一次」：任务上 #[Cron(cluster: true)] 即可，无需在此全局开关；
 * 但前提是已在下方 cluster.store 配置了协调存储（委托 kode/process 分布式锁）。
 * 未配置时 #[Cron(cluster: true)] 退化为 LocalCoordinator（单进程/单机安全，多机重复）。
 */

return [
    // 任务目录（属性扫描）。key=来源标签，value=相对项目根的目录。
    // 配置期 app() 可能尚未就绪，base_path() 会退化成相对 CWD 的路径；
    // kode 在引导后会用真实的 basePath 拼成绝对路径。多应用/模块化：
    // 追加更多 key 即可（如 'admin' => 'modules/admin/Tasks'）。
    'paths' => [
        'app' => 'app/tasks',
    ],

    // 插件自动发现（默认关闭；开启后扫描 plugins/<name>/src/Tasks 作为独立模块来源）。
    'discover_plugins' => (bool) env('SCHEDULE_DISCOVER_PLUGINS', false),

    // 集群协调（可选）。配置 store 后，#[Cron(cluster: true)] 任务走分布式锁，
    // 保证集群内同一调度时刻至多执行一次；store 为空（默认）则本地恒派发。
    // store 取值同 Kode\Process\Cluster::make()（如 'redis' / 'file'）。
    'cluster' => [
        'store' => env('SCHEDULE_CLUSTER_STORE', ''),
        'ttl'   => (float) env('SCHEDULE_CLUSTER_TTL', 30),
    ],
];
