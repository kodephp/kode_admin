<?php

declare(strict_types=1);

/**
 * 多租户配置（kode/context 薄壳委托）。
 *
 * 框架只负责两层：
 *  1) 租户上下文原语：在请求入口解析租户 → 写入每请求隔离 scope → 暴露 tenant() 助手；
 *  2) 存储隔离脚手架（见 storage 段）：把「当前租户」映射到具体 DB 连接，并在请求级
 *     自动切换 / 恢复，天然支持 kode/process 单请求单 worker 模型。
 *
 * 「租户对应哪个库 / schema / 记录」的具体策略可插拔：内置 shared（不隔离）/ database
 * （每租户独立库）/ schema / map（显式映射）；真实 SaaS 常用「从中心租户表动态查凭证」
 * —— 实现 Kode\Framework\Tenant\Storage\TenantConnectionResolver 注入即零改动复用。
 *
 * resolver 支持三种形态：
 *  - 'header'    从请求头解析（默认 X-Tenant-Id）
 *  - 'subdomain' 从子域名第一段解析（适合每租户独立子域的 SaaS）
 *  - <FQCN>      应用自定义的 Kode\Framework\Tenant\TenantResolver 实现
 */
return [
    // 是否启用租户中间件（关闭则 tenant() 恒返回 null，存储隔离随之无效）。
    'enabled' => env('TENANT_ENABLED', false),

    // 解析策略：'header' | 'subdomain' | 自定义 TenantResolver 类名；null = 不解析（仅用 default）。
    'resolver' => env('TENANT_RESOLVER', null),

    // 解析失败 / 无解析器时的回退租户（null = 无默认，tenant() 返回 null）。
    'default' => env('TENANT_DEFAULT', null),

    // 各内置解析器参数。
    'header' => [
        'name' => env('TENANT_HEADER', 'X-Tenant-Id'),
    ],
    'subdomain' => [
        'base_domain' => env('TENANT_BASE_DOMAIN', ''), // 形如 'example.com'，为空则不剔除
    ],

    // ------------------------------------------------------------------
    // 存储隔离脚手架
    // ------------------------------------------------------------------
    // 仅当本段 enabled = true 且租户已解析（tenant() 非 null）时生效。
    'storage' => [
        // 是否启用「按租户切换 DB 连接」（请求级自动切换 / 恢复）。
        'enabled' => env('TENANT_STORAGE_ENABLED', false),

        // 隔离策略（租户标识 = 请求带来的 name，见 app/admin/controllers/TenantController 类注释）：
        //   'shared'  不隔离，始终用默认连接（null 安全，租户仅作为上下文标签）；
        //   'database'每租户独立数据库，database 名 = prefix + sanitize(租户标识)；
        //   'schema'  同上（命名空间隔离），thin-shell 同样落到 database 命名，
        //             应用可在此基础上叠加 schema/search_path 策略；
        //   'map'     显式映射：tenant id => 已注册连接名(string) 或 连接配置覆盖(array)；
        //   <FQCN>   自定义 TenantConnectionResolver 实现（动态从中心租户表查凭证等）。
        'strategy' => env('TENANT_STORAGE_STRATEGY', 'shared'),

        // database/schema 策略的模板连接（取自 config/database.php 的 connections 键）。
        // 留空 = 跟随 database.default（框架 >= 1.7.6）；填了不存在的连接名会在启动期直接报错，
        // 不再静默借用默认连接的凭证——租户库跑错连接属于「隔离失效于无声」，宁可装不上。
        'template' => env('TENANT_STORAGE_TEMPLATE', ''),

        // database/schema 策略的库名前缀。拼接对象是 sanitize 后的租户标识：
        // sanitize 把非 [字母数字下划线] 一律换成 '_'（故 'acme-co' 与 'acme_co' 会归并成同一个库名，
        // 租户命名请避开这一点，或改用 map 策略显式登记）。
        'prefix' => env('TENANT_DB_PREFIX', 'tnt_'),

        // map 策略的显式映射：tenant id => 连接名 | 部分连接配置覆盖。
        'map' => [],

        // 缺失映射时的行为（仅 map / 自定义解析器用到；database/schema 恒能派生，不受本键影响）：
        //   'fallback' 回退到默认连接（不报错）。注意这是「隔离失败于无声」：未登记的租户
        //              会直接读到共享默认库，安全敏感的部署请用 'abort'；
        //   'abort'    抛出 404（KodeException::notFound），适合「未登记租户一律拒绝」。
        'on_missing' => env('TENANT_STORAGE_ON_MISSING', 'fallback'),
    ],
];
