<?php

/*
 * Doctrine ORM 配置（项目默认 ORM，pgsql 主连接）。
 *
 * 连接复用 DB_* 环境变量（与 kode/database 同源），模型目录 app/models。
 * 长驻进程注意：每请求结束由 DoctrineClearMiddleware 调用 EntityManager::clear()，
 * 防止 UnitOfWork 无限增长（见 app/http/middleware/DoctrineClearMiddleware.php）。
 */

return [
    // 实体目录（相对项目根，Attribute 注解映射）。
    'paths' => ['app/models'],

    // 是否开发模式（生产 false：元数据/代理缓存；开发 true：每次解析）。
    'dev' => (bool) env('DOCTRINE_DEV', env('APP_DEBUG', false)),

    // 代理目录（dev=false 时必填可写；null 由 Provider 解析为 storage/doctrine/proxies，
    // config 加载期不可调 storage_path()，见 session.php 同约定）。
    'proxy_dir' => env('DOCTRINE_PROXY_DIR'),

    // 连接（默认复用 DB_*，与主库一致）。
    'connection' => [
        'driver' => 'pdo_pgsql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', 5432),
        'dbname' => env('DB_DATABASE', 'kode_app'),
        'user' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8',
    ],
];
