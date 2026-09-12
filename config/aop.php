<?php

declare(strict_types=1);

/**
 * AOP（面向切面编程）配置（kode/aop，薄壳委托）
 *
 * 框架此前装了 kode/aop 却没有任何 Provider 接线，切面能力「静默失接」。
 * 本文件 + AopServiceProvider 把内核接进生命周期：按 paths 自动发现 #[Aspect] 切面类
 * （约定优于配置），并合并 aspects 显式登记，统一交给 Aop::bootFromConfig 织入。
 *
 * 切面写法：在 app/aspects 下建类，标 #[Aspect]，方法上标 #[Before]/#[After]/#[Around] 等，
 * 并用 Pointcut 表达式声明织入点（如 execution(* app\service\*->*(..))）。
 */

return [
    // 是否启用 AOP 织入（关闭则内核 disabled，切面不生效）。
    'enabled' => env('AOP_ENABLED', true),

    // 自动扫描这些目录下的 #[Aspect] 切面类（key=来源标签，value=相对 basePath 的路径）。
    // app/aspects 为约定目录；app/aop 兼容示例切面所在位置（可追加自定义目录）。
    'paths' => [
        'app' => 'app/aspects',
        'app_aop' => 'app/aop',
    ],

    // 显式登记切面（类名字符串），优先级高于自动发现；一般无需手写，约定优于配置即可。
    'aspects' => [
        // \app\aspects\LoggingAspect::class,
    ],

    // 代理/织入缓存目录：首次织入生成代理类后缓存，二次启动免重织入，提升性能。
    // 留空则使用 basePath('storage/aop')。
    'cache' => [
        'path' => env('AOP_CACHE_PATH', ''),
    ],

    // 严格模式：切面/切点非法时抛异常（默认关，宽松模式仅告警）。
    'strict' => env('AOP_STRICT', false),
];
