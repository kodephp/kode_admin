<?php

declare(strict_types=1);

/**
 * 并行计算配置（kode/parallel，薄壳委托）
 *
 * 框架此前装了 kode/parallel 却未启用（仅注释），并行能力「静默失接」。
 * 本文件 + ParallelServiceProvider 把能力接进生命周期：探测可用后端
 * （ZTS + ext-parallel 真线程 → parallel 引擎，否则 sync 同步回退），配置线程引导文件，
 * 业务侧用 parallel() 助手提交任务（见 src/Support/helpers.php）。
 *
 * 注意：parallel 引擎需要 PHP ZTS 构建 + ext-parallel 扩展；非 ZTS 环境自动回退 sync 引擎
 * （单线程顺序执行，API 一致、不报错），保证代码在任意环境都能跑。
 */

return [
    // 是否启用并行能力（关闭则 parallel() 一律走 sync 回退，不尝试真线程）。
    'enabled' => env('PARALLEL_ENABLED', true),

    // 线程引导文件：任务闭包内使用业务类所需的自动加载器，通常为 vendor/autoload.php。
    // 留空则 Provider 自动补为 basePath('vendor/autoload.php')。
    'bootstrap' => env('PARALLEL_BOOTSTRAP', ''),

    // 引擎：null=自动探测（parallel / sync），也可显式指定 'parallel' 或 'sync'。
    'engine' => env('PARALLEL_ENGINE', null),

    // WorkerPool / 并发上限，<=0 按 CPU 核心数推荐。
    'concurrency' => (int) env('PARALLEL_CONCURRENCY', 0),
];
