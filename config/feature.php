<?php

declare(strict_types=1);

/*
 * Feature Flags 配置
 *
 * 框架原生薄实现（无 kode 包对应），用于「灰度发布 / 功能开关 / A-B 实验」：
 *  - 在路由上用 #[Feature('flag-name')] 声明式 gating（见 src/Feature/Attributes/Feature.php）；
 *  - 业务里用 feature('flag-name') 即时判定（见 src/Support/helpers.php）。
 *
 * 判定模型（见 FeatureManager）：
 *  - 未配置该 flag  → 回落 feature.default（默认 false，即「默认关闭、显式开启」）；
 *  - enabled=false  → 直接关闭（无视 rollout）；
 *  - enabled=true   → 结合 rollout 灰度：
 *      rollout >= 100 → 全量；rollout <= 0 → 无；0<rollout<100 → 按 key 稳定分桶。
 *
 * 分桶键（bucket key）：FeatureMiddleware 自动取 X-User-Id → X-Tenant-Id → 客户端 IP，
 * 保证同一用户/租户在灰度窗口内命中稳定，不会抖动。
 *
 * 进阶：用 FeatureManager::registerResolver() 接入 DB/Redis/配置中心的动态开关（见 docs）。
 */

return [
    // 总开关：关闭后所有 #[Feature] 路由均放行（视为全开），feature() 仍按 flags 判定。
    'enabled' => (bool) env('FEATURE_ENABLED', false),

    // 未声明 flag 的默认判定（建议 false）。
    'default' => (bool) env('FEATURE_DEFAULT', false),

    // 声明式 flag 定义。
    'flags' => [
        // 'new-checkout' => ['enabled' => true, 'rollout' => 100, 'description' => '新版结算流程'],
        // 'beta-search'  => ['enabled' => true, 'rollout' => 10,  'description' => '搜索 beta 10% 灰度'],
    ],
];
