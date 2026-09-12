<?php

/*
 * 分库分表配置（亿级架构开关总闸）。
 *
 *  - enabled：关闭 = 单库单表传统模式（零回归）；开启 = 按分片键路由库表。
 *  - db_count：库分片数（kode_app_00…，2 的幂次扩）；table_shards：库内表分片数。
 *  - key：默认分片键来源（业务可覆盖）；owner 列名约定 created_by。
 *  生产拓扑：各分片库独立实例/端口（DB_SHARD_HOSTS 逗号列表，一一对应），
 *  本地演示共用单实例多库。
 */

return [
    'enabled' => (bool) env('SHARD_ENABLED', false),

    'db_count' => (int) env('SHARD_DB_COUNT', 2),
    'table_shards' => (int) env('SHARD_TABLE_COUNT', 2),

    // 分片库名模板（%02d 为序号），与主库同主机端口，独立 database。
    'db_name' => env('SHARD_DB_NAME', 'kode_app_%02d'),

    // 分片库主机列表（逗号分隔，数量须 >= db_count；留空则全部走主库主机）。
    'db_hosts' => env('SHARD_DB_HOSTS', ''),
];
