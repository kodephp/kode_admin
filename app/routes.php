<?php

/*
 * 顶层路由（既不属于 /api/* 后台子应用，也不属于 /c-api/* C 端子应用的那几条）。
 *
 * 这里原本装着 kode/skeleton 的示例端点：`POST /auth/login`、`GET /me`、`POST /users`、
 * `GET /users/{id}`、`GET /demo/*`。它们已全部删除，原因不是「整洁」而是「能被打穿」：
 *
 *   示例 `POST /auth/login` 不做任何凭证比对就 `jwt()->issue(['uid' => 1, ...])`，
 *   而 `AccountScope::isAdmin()` 认「uid=1 即超管」（`app/admin/middleware/RequirePerm.php`
 *   的旁路注释写得很明白）。于是任何人一枚空 POST 换到一枚签名合法的令牌，
 *   `/api/users`（全站名单）、`/api/configs`、`/api/menus`、`/api/dashboard/summary`
 *   全部按超管口径放行 —— 实测 200，`scope:"all"`。
 *   真实登录只有 `POST /api/auth/login` 一条：它走 `AttemptGuard` 计数、验 `password_verify`、
 *   签发后轮换会话。任何第二枚「发令牌」的口都必须先回答「凭什么给这个人发」。
 *
 * 另两个连带问题一并收掉：
 *  - `config/routes.php` 的 `attributes.controllers` 曾指向 `app/controllers`，
 *    示例 `ProductsController` 的 `#[Get('')]` 因此在生产路由表里自动登记了 `/products`
 *    两条匿名路由 —— 属性路由「零开关即生效」和「顶层没人管辖」叠在一起，
 *    写个示例类就等于公开一个端点。口径与回归见 `tests/RootRouteGateTest.php`。
 *  - 这些顶层动态路由还排在 FrontendPlugin 的 SPA 兜底之前，
 *    等于让 `/users/123` 这种前端深链接返回 JSON 而不是 index.html。
 */

use Kode\Framework\Application;
use Kode\Framework\Health\HealthChecker;
use Kode\Framework\Http\Resp;
use Kode\Http\App;
use Kode\Http\Kode;

return function (App $app): void {
    // 健康检查 / 元信息（根路径 `/` 已由 FrontendPlugin 的 admin.index 接管，服务 Admin Web index.html）
    //
    // 本路由**覆盖**框架内置的同名 `/health`，所以两件事必须自己补齐，别覆盖成一份假报告：
    //  1) 版本口径：`version` = 框架内核版本（Application::VERSION），`app_version` = 骨架版本
    //     （与 composer.json 的 version 同源）—— 运维要靠这两个值区分「内核」与「项目模板」；
    //  2) 状态码口径：框架那条（≥ v1.10.2）是**报表**——`status` 随探针但 HTTP 恒 200，
    //     理由是「拿 /health 当 livenessProbe 的应用会在外部依赖抖动时无意义重启」。
    //     本应用刻意相反：degraded 时回 **503**，让负载均衡把它当就绪信号摘掉流量
    //     （断言见 tests/HealthDegradedReportTest.php）。代价是运维侧必须分清三条口：
    //     摘流看 /health 与 /health/ready，**重启判定只看 /health/live** —— 把 /health 配成
    //     livenessProbe 会正好踩中框架那条理由。改这条口径要先想清楚谁在读状态码。
    // 明细脱敏沿用 §4「/health 失败明细仅 debug 可见」：探针报错文本常含连接串与 SQLSTATE，
    // 未鉴权端点上只出 `ok` / `error` 两种状态，永不外发原文。
    $app->get('/health', static function (): \Psr\Http\Message\ResponseInterface {
        $result = (array) resolve(HealthChecker::class)->check();
        $healthy = (bool) ($result['healthy'] ?? true);

        $checks = [];
        foreach ((array) ($result['checks'] ?? []) as $name => $status) {
            $text = (string) $status;
            $checks[(string) $name] = str_starts_with($text, 'error')
                ? (config('app.debug', false) ? $text : 'error')
                : $text;
        }

        return Resp::json([
            'framework' => 'kode',
            'version' => Application::VERSION,
            'app_version' => config('app.version'),
            'http' => Kode::VERSION,
            'time' => date('c'),
            'status' => $healthy ? 'ok' : 'degraded',
            'components' => $checks,
        ], $healthy ? 200 : 503);
    });
};
