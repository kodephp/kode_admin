<?php

/*
 * C 端子应用路由（app/api/routes.php，标签 app:api）。
 *
 * 约定：C 端接口前缀 /c-api/*，与管理后台 /api/* 隔离；
 * 需要鉴权的 C 端接口同样挂 AuthMiddleware（用户体系 JWT）。
 */

use Kode\Http\App;
use app\api\controllers\PingController;
use app\api\controllers\RegisterController;

return function (App $app): void {
    $app->get('/c-api/ping', fn($req) => resolve(PingController::class)->ping());

    // C 端自助注册（默认关闭：C_API_REGISTER=true 开启；需邮箱验证流程时再加）。
    if ((bool) env('C_API_REGISTER', false)) {
        // 不复用后台 UserController::store()：那条线收的字段跟着后台需求长，
        // 复用等于把 dept_id/roles 一类的口子发给匿名请求，且没有任何测试会因此变红
        // （写侧门禁只枚举 app:admin 标签的路由）。回归见 tests/ApiWriteGateTest.php。
        $app->post('/c-api/auth/register', fn($req) => resolve(RegisterController::class)->register());
    }
};
