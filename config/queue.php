<?php

/*
 * 队列配置（kode/queue，PHP 8.3+，不可变消息、至少一次投递、内建 Worker）
 *
 * driver 支持 redis / database / beanstalkd / amqp / kafka（需对应扩展/连接）。
 * 留空时 QueueManager::make() 走 fromEnv()/auto() 自动探测。
 */

return [
    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [
        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD'),
            'database' => (int) env('REDIS_DB', 0),
            'queue' => env('QUEUE_NAME', 'default'),
        ],

        'database' => [
            'driver' => 'database',
            'table' => env('QUEUE_TABLE', 'jobs'),
        ],

        'memory' => [
            'driver' => 'memory',
            'queue' => env('QUEUE_NAME', 'default'),
        ],
    ],

    // 失败任务（死信）存储
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database'),
        'table' => 'failed_jobs',
        // CLI 消费进程（queue:work）默认用文件死信存储，无需数据库依赖；
        // 不配置则落到 basePath('storage/queue/failed')。
        'path' => env('QUEUE_FAILED_PATH', ''),
    ],

    // ------------------------------------------------------------------
    // 消费端（queue:work 命令使用，修复此前「只能投递、无法消费」的问题）
    // ------------------------------------------------------------------

    // 自动扫描这些目录下的 #[AsJob] 任务类，注册进处理器解析器。
    // 目录约定位于 app/ 下（app\ 命名空间），可追加自定义目录（绝对路径）。
    'jobs_dir' => [
        'app/jobs',
        'app/tasks',
    ],

    // 显式映射「任务名 => 处理器」（闭包无法写在配置里，这里填类名字符串）。
    // 与 jobs_dir 自动发现合并，显式项优先级更高。
    'workers' => [
        // 'mail.send' => \app\jobs\SendMail::class,
    ],

    // queue:work 的默认运行参数（可被命令行 --xxx 覆盖）。
    'worker' => [
        'name' => env('QUEUE_WORKER_NAME', 'kode-worker'),
        'queues' => ['default'],
        'sleep' => 1.0,
        'block_timeout' => 5.0,
        'max_jobs' => 1000,
        'max_time' => 3600,
        'memory_limit' => 256,
        'max_attempts' => 0,
        'reclaim_every' => 60.0,
    ],
];
