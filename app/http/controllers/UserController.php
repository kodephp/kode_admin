<?php

declare(strict_types=1);

namespace app\http\controllers;

use Kode\Http\Request;
use Kode\Framework\Http\Controller;

/**
 * 用户控制器示例
 */
final class UserController extends Controller
{
    public function show($req)
    {
        $id = Request::param('id');

        return [
            'id' => $id,
            'name' => 'User ' . $id,
        ];
    }

    public function store($req)
    {
        $data = $this->validate(Request::all(), [
            'name' => 'required|min:2|max:50',
            'email' => 'required|email',
            'age' => 'nullable|integer|min:0|max:150',
        ]);

        // 这里通常写入数据库，示例仅回显。
        return $this->json($data);
    }

    public function me($req)
    {
        // AuthMiddleware 已把解析后的 JWT 载荷（Kode\Jwt\Token\Payload 对象）放入请求属性 auth。
        $payload = Request::attr('auth');

        if ($payload === null) {
            return $this->error('未认证', 401);
        }

        // Payload 提供公共属性 uid/username/platform 以及 toArray()。
        return [
            'uid' => $payload->uid,
            'username' => $payload->username,
            'platform' => $payload->platform,
            'claims' => $payload->toArray(),
        ];
    }
}
