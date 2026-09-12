<?php

/*
 * HTTP 多进程服务配置（kode/process master-worker 运行时）
 *
 * 由 `kode start` 读取（入口薄壳 `kode`，`bin/kode` 不存在）；
 * CLI 参数（--host/--port/--workers）优先级高于本文件。
 * host/port 决定监听地址；workers 为 worker 进程数（默认取 CPU 核心数）；
 * max_request 为单 worker 累计处理多少请求后自动回收（防内存泄漏，0=不回收）；
 * reuse_port 在多 Worker 高并发场景下可提升端口绑定吞吐（依赖 OS 支持）。
 */

return [
    'host'        => env('SERVER_HOST', '127.0.0.1'),
    'port'        => (int) env('SERVER_PORT', 9527),
    'workers'     => (int) env('SERVER_WORKERS', 0), // 0 = 自动取 CPU 核心数
    'max_request' => (int) env('SERVER_MAX_REQUEST', 0),
    'reuse_port'  => (bool) env('SERVER_REUSE_PORT', false),
    'name'        => env('SERVER_NAME', 'kode-http'),

    /*
     * 优雅停机宽限（秒）。
     *
     * kode/process 收到 SIGTERM/SIGINT 后：停止接收新连接 → 让在途连接自然关闭（或超时强制退出）。
     * 此值即「在途请求排空」的最长等待时间，应小于 k8s 的 terminationGracePeriodSeconds
     * （默认 30s），给 LB 摘流 + 进程最终退出的余量。设为 0 则退回到 kode/process 内置默认（3s）。
     *
     * 生产建议：长事务/大文件上传的 P99 耗时 < 此值 << terminationGracePeriodSeconds。
     */
    'graceful_shutdown_timeout' => (int) env('SERVER_GRACE_PERIOD', 30),

    // 开发期热重载（serve --watch）：监听以下目录的 .php 变化，自动重启 serve 子进程。
    // dirs 用相对项目根的路径；不填则默认监听 app/config/src/public/bin（存在的才收）。
    'watch' => [
        'dirs'    => [], // 例如 ['app', 'config', 'src']
        'exclude' => ['vendor', '.git', 'storage', 'runtime', 'node_modules', '.workbuddy'],
    ],
];
