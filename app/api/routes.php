<?php

/*
 * C 端子应用路由（app/api/routes.php，标签 app:api）。
 *
 * 约定：C 端接口前缀 /c-api/*，与管理后台 /api/* 隔离；
 * 需要鉴权的 C 端接口同样挂 AuthMiddleware（用户体系 JWT）。
 */

use Kode\Http\App;
use app\api\http\controllers\PingController;

return function (App $app): void {
    $app->get('/c-api/ping', fn($req) => resolve(PingController::class)->ping());

    // C 端自助注册（默认关闭：C_API_REGISTER=true 开启；需邮箱验证流程时再加）。
    if ((bool) env('C_API_REGISTER', false)) {
        $app->post('/c-api/auth/register', function () {
            $c = resolve(\app\admin\http\controllers\UserController::class);
            // 复用后台创建逻辑（校验+邮箱唯一），角色默认为空。
            return $c->store();
        });
    }
};
