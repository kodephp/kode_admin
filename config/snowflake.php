<?php

/*
 * 分布式 ID 生成器（Snowflake）配置
 *
 * 算法由 kode/process 的 Cluster/Snowflake 提供（fork 安全、含时钟回拨保护、
 * ID 解析与机器 ID 重绑）。此处仅配置机器编号与纪元。
 *
 * 多实例部署时务必让每个实例拥有唯一的 worker_id（0 ~ 1023），否则可能生成碰撞 ID。
 * 集群规模大时可用 Cluster/Snowflake::allocateWorkerId() 从协调存储自动领取。
 */

return [
    /*
     * 机器编号（0 ~ 1023）。同一集群内不同实例需不同。
     */
    'worker_id' => (int) env('SNOWFLAKE_WORKER_ID', 0),

    /*
     * 自定义纪元（毫秒时间戳）。默认 2024-01-01T00:00:00Z。
     * 可用更接近当前的纪元以延长算法可用年限（41 位约可用 69 年，距今越近越省）。
     * 上线后不可再改。
     */
    'epoch' => (int) env('SNOWFLAKE_EPOCH', 1704067200000),
];
