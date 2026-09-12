<?php

declare(strict_types=1);

namespace app\http\controllers;

use Kode\Http\Request;
use Kode\Framework\Http\Controller;

/**
 * 鉴权控制器示例（签发 JWT）
 */
final class AuthController extends Controller
{
    public function login($req)
    {
        $data = $this->validate(Request::all(), [
            'username' => 'required|min:2',
            'password' => 'required|min:6',
        ]);

        // 示例：不做真实密码校验，直接签发令牌。
        $token = jwt()->issue([
            'uid' => 1,
            'username' => $data['username'],
            'role' => 'user',
        ]);

        return $this->json(['token' => $token]);
    }
}
