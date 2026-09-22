<?php

/*
 * 数据库配置（kode/database，轻量级适配器，兼容 Laravel/ThinkPHP/Hyperf ORM）
 *
 * kode/database 是「静态代理」用法：框架在启动期调用 Db::setConfig($config)，
 * 业务侧用 Db::table('users')->where(...)->get() 或 db()->table(...) 编写查询。
 * 这里只放配置，不建立真实连接（连接懒加载到首次查询）。
 */

return [
    // 默认 pgsql 单连接（统一 DB_* 命名；历史 mysql 已移除，sqlite 仅本地零配置备用）。
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            // 连接池（生产级，H1）：kode/database 的 PoolManager 仅当存在 pool 段时建池。
            // Native 下退化为 ProcessPool（per-worker 复用），Swoole/Fiber 下为对应协程池。
            // 默认关闭（兼容单连接）；生产建议开启并按压测调优 max/min。
            'pool' => env('DB_POOL_ENABLED', false) ? [
                'max' => (int) env('DB_POOL_MAX', 10),
                'min' => (int) env('DB_POOL_MIN', 2),
                'max_wait_time' => (int) env('DB_POOL_MAX_WAIT', 30),
                // 仅 Swoole 协程池（kode/database ConnectionPool）会回收空闲连接；
                // Native 运行时的进程池不做空闲回收，本键届时不生效。
                'max_idle_time' => (int) env('DB_POOL_MAX_IDLE', 60),
            ] : null,
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            // 绝对路径：配置加载期 app() 未就绪，storage_path() 会退化成相对 CWD（见 cache.php 同约定）
            'database' => env('DB_SQLITE_PATH', dirname(__DIR__) . '/storage/database.sqlite'),
        ],

        // 读库（读写分离）：生产指向从库地址，演示环境默认与主库同实例（只验证路由）。
        'pgsql_read' => [
            'driver' => 'pgsql',
            'host' => env('DB_READ_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => (int) env('DB_READ_PORT', env('DB_PORT', 5432)),
            'database' => env('DB_READ_DATABASE', env('DB_DATABASE', 'kode_app')),
            'username' => env('DB_READ_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_READ_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
        ],
    ],

    // 读写分离总开关（开启后 SELECT 走 pgsql_read，写走主库；事务内读强制走主库，保证读己之写）。
    // 注意：从库故障不会自动回主（会直接报错），生产需配合探针告警 + 手动切回（关本开关即全走主库）。
    'read_write_split' => (bool) env('DB_READ_WRITE_SPLIT', false),

    /*
     * 请求级数据库事务（原子化写请求）。
     * 开启后，框架对 POST/PUT/PATCH/DELETE 自动开事务，成功提交、异常回滚，
     * 保证「一次 HTTP 请求 = 一个原子工作单元」。默认关闭，按需开启。
     * transaction_skip_paths 中的路径（如探针）不开启事务。
     */
    'auto_transaction' => (bool) env('DB_AUTO_TRANSACTION', false),
    'transaction_skip_paths' => ['/health', '/metrics', '/ping'],

    /*
     * 连接生命周期收口（ConnectionCleanupMiddleware）。
     * kode/database 1.15.5+ 缓存连接池后，常驻进程需在请求结束后做连接级收口，避免：
     *  - 泄漏事务跨请求延续（手动 begin 后抛异常未回滚）；
     *  - 单测 / CLI 之间连接互相污染。
     * leak_rollback：检测到请求结束时仍有未提交事务则强制回滚（默认开，绝不跨请求）。
     * release_per_request：响应后调用 Db::disconnect() 释放缓存连接（默认关，保留连接池
     *   性能；单测 / CLI / 连接易失效场景可设为 true）。
     */
    'leak_rollback' => (bool) env('DB_LEAK_ROLLBACK', true),
    'release_per_request' => (bool) env('DB_RELEASE_PER_REQUEST', false),
];
