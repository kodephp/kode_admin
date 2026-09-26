<?php

declare(strict_types=1);

namespace app\api\controllers;

use app\concerns\ApiResponse;
use app\concerns\AttemptGuard;
use app\services\UserService;
use app\support\HashId;
use app\support\SysConfig;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * C 端自助注册（路由默认不挂：`C_API_REGISTER=true` 才开）。
 *
 * 为什么不复用后台的 `UserController::store()`（旧写法）：那个口是「超管建账号」，
 * 收哪些字段跟着后台需求长——今天多一个 `dept_id`、明天多一个 `roles` 或 `status`，
 * 而复用等于把这些字段一起发给匿名请求，且**没有任何测试会因此变红**
 * （写侧门禁 `CoreWriteGateTest` 只枚举 `app:admin` 标签的路由）。
 * 这里把入参钉死成 name/email/password 三样：落库固定 dept_id=0、不写任何角色，
 * `User::$status` 默认 1（新建即可登录，禁用只能由后台做）。
 *
 * 匿名建号必须有预算：`user.created` 会被钱包插件消费（每注册一个账号开一行钱包），
 * 而限流中间件默认关（`LIMITING_ENABLED=false`），不自己计数就等于给外部留一个
 * 无成本的写库入口。计的是**尝试**而不是失败：成功建号也要 +1，否则预算形同不存在。
 *
 * 已知取舍：邮箱重复回 409「邮箱已存在」，这让匿名调用方能枚举「哪些邮箱已注册」。
 * 注册表单必须告诉当事人这个邮箱被占用，去掉它就变成静默失败；
 * 真正的解法是先验邮箱所有权再建号（本路由的注释一直写着「需邮箱验证流程时再加」）。
 * 在引入验证流程之前，这条线由「默认关闭 + IP 预算」两样兜着。
 */
final class RegisterController extends Controller
{
    use ApiResponse;
    use AttemptGuard;

    public function register()
    {
        $data = $this->validate(Request::all(), [
            'name' => 'required|min:2|length:64',
            'email' => 'required|email|length:128',
            'password' => 'required|min:' . SysConfig::getInt('auth.password_min_length', 6, 4, 64) . '|length:64',
        ]);

        // 大小写不同的同一个邮箱不该建出两个账号（后台建号没有这一层归一，
        // 所以 C 端归一后撞 409 正是我们要的结果）。
        $email = mb_strtolower(trim((string) $data['email']));
        $ip = $this->clientIp();
        if ($this->tooManyAttempts(self::PREFIX_REGISTER, $ip, $email)) {
            return $this->fail('注册过于频繁，请稍后再试', 429);
        }

        try {
            $user = resolve(UserService::class)->create(
                (string) $data['name'],
                $email,
                (string) $data['password']
            );
        } catch (\RuntimeException $e) {
            // 预算在结果之后计：邮箱重复同样算一次尝试，重试撞同一个邮箱也会触顶。
            $this->hitAttempt(self::PREFIX_REGISTER, $ip, $email);

            return $this->fail($e->getMessage(), $e->getCode() ?: 400);
        }
        $this->hitAttempt(self::PREFIX_REGISTER, $ip, $email);

        return $this->ok(['id' => HashId::enc((int) $user->getId())], 201);
    }
}
