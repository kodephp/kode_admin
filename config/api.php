<?php

/*
 * API 版本化配置
 *
 * 启用后，框架要求 /vN 版本前缀并对不支持的版本返回 404，实现「多版本共存 + 强制升级」。
 * 路由约定：版本化接口统一放到 app/routes/api.php，并用分组前缀声明：
 *
 *   use Kode\Http\App;
 *   return static function (App $app): void {
 *       $app->group('v1', static function (App $app): void {
 *           $app->get('/users', [UserController::class, 'index']);
 *       });
 *   };
 *
 * 版本前缀在 versioning 中间件中被识别并写入请求属性 api_version，便于审计 / 日志标注。
 */

return [
    // 是否启用版本化强制校验
    'enabled' => (bool) env('API_VERSIONING_ENABLED', false),

    // 是否强制所有 API 路径带版本前缀（缺省返回 400 提示）
    'prefix_required' => (bool) env('API_VERSIONING_REQUIRED', true),

    // 当前版本（用于文档 / 默认路由组标注）
    'current_version' => env('API_VERSION', 'v1'),

    // 受支持的版本列表（不在列表的版本前缀返回 404）
    'supported_versions' => array_filter(array_map(
        'trim',
        explode(',', env('API_SUPPORTED_VERSIONS', 'v1'))
    ), static fn (string $v): bool => $v !== ''),
];
