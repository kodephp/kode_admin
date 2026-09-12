<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use app\http\concerns\ApiResponse;
use Kode\Http\Request;

/**
 * 后台鉴权：真实账号登录（pgsql users 表 + password_verify）。
 */
final class AuthController extends Controller
{
    use ApiResponse;
    public function login()
    {
        $data = $this->validate(Request::all(), [
            'username' => 'required|min:2',
            'password' => 'required|min:6',
        ]);

        // 登录防爆破：同 IP/账号 10 分钟内累计失败 10 次即限流。
        $ip = (string) (Request::ip() ?? 'unknown');
        if ($this->tooManyAttempts($ip, $data['username'])) {
            return $this->fail('尝试过于频繁，请稍后再试', 429);
        }

        $rows = resolve(\app\repositories\UserRepository::class)->findByName($data['username']);
        $user = $rows;
        if ($user === null || !password_verify($data['password'], $user->getPassword())) {
            $this->hitAttempt($ip, $data['username']);
            self::logLogin($user === null ? 0 : (int) $user->getId(), $data['username'], false, '账号或密码错误');

            return $this->fail('账号或密码错误', 401);
        }
        if ($user->getStatus() !== 1) {
            self::logLogin((int) $user->getId(), $data['username'], false, '账号已禁用');

            return $this->fail('账号已禁用', 403);
        }

        $this->clearAttempts($ip, $data['username']);

        $roles = array_column(
            Db::select('SELECT role_slug FROM kode_role_user WHERE user_id = ?', [(int) $user->getId()]),
            'role_slug'
        );

        $token = jwt()->issue([
            'uid' => (int) $user->getId(),
            'username' => $user->getName(),
            'roles' => $roles,
        ]);
        self::logLogin((int) $user->getId(), $user->getName(), true, '');

        return $this->ok(['token' => $token, 'user' => $user->toArray(), 'roles' => $roles]);
    }

    private const MAX_ATTEMPTS = 10;
    private const DECAY_SECONDS = 600;

    /** 登录日志（成功/失败均记，失败绝不影响主流程）。 */
    private static function logLogin(int $uid, string $username, bool $ok, string $reason): void
    {
        try {
            $ua = substr((string) (Request::header('User-Agent') ?? ''), 0, 512);
            Db::insert(
                'INSERT INTO kode_login_logs (user_id, username, ip, user_agent, device, status, fail_reason, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [$uid, substr($username, 0, 64), substr((string) (Request::ip() ?? ''), 0, 64), $ua, self::device($ua), $ok ? 1 : 0, substr($reason, 0, 255)]
            );
        } catch (\Throwable) {
        }
    }

    private static function device(string $ua): string
    {
        $os = str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Mac') ? 'macOS' : (str_contains($ua, 'Linux') ? 'Linux' : (str_contains($ua, 'Android') ? 'Android' : (str_contains($ua, 'iPhone') ? 'iOS' : ''))));
        $browser = str_contains($ua, 'Edg') ? 'Edge' : (str_contains($ua, 'Chrome') ? 'Chrome' : (str_contains($ua, 'Firefox') ? 'Firefox' : (str_contains($ua, 'Safari') ? 'Safari' : '')));

        return trim("{$browser} {$os}");
    }

    private static function maxAttempts(): int
    {
        return \app\support\SysConfig::getInt('auth.login_max_attempts', self::MAX_ATTEMPTS, 3, 100);
    }

    private static function decaySeconds(): int
    {
        return \app\support\SysConfig::getInt('auth.login_decay_seconds', self::DECAY_SECONDS, 60, 3600);
    }

    /** @return list<string> */
    private function attemptKeys(string $ip, string $username): array
    {
        return ["login_fail_ip:{$ip}", "login_fail_user:{$username}"];
    }

    private function tooManyAttempts(string $ip, string $username): bool
    {
        try {
            foreach ($this->attemptKeys($ip, $username) as $key) {
                if ((int) cache()->get($key, 0) >= self::maxAttempts()) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private function hitAttempt(string $ip, string $username): void
    {
        try {
            foreach ($this->attemptKeys($ip, $username) as $key) {
                cache()->set($key, (int) cache()->get($key, 0) + 1, self::decaySeconds());
            }
        } catch (\Throwable) {
        }
    }

    private function clearAttempts(string $ip, string $username): void
    {
        try {
            foreach ($this->attemptKeys($ip, $username) as $key) {
                cache()->delete($key);
            }
        } catch (\Throwable) {
        }
    }

    public function me()
    {
        $payload = Request::attr('auth');
        if ($payload === null) {
            return $this->fail('未认证', 401);
        }

        $repo = resolve(\app\repositories\UserRepository::class);
        $me = $repo->find((int) $payload->uid);

        return $this->ok([
            'uid' => \app\auth\EncryptedJwtGuard::outerUid((int) $payload->uid),
            'username' => $payload->username,
            'avatar' => $me?->getAvatar() ?? '',
            'roles' => $payload->roles ?? [],
            'perms' => \app\support\RolePerms::forUser((int) $payload->uid)['perms'],
        ]);
    }

    /** 我的登录日志（普通用户可见自己）。 */
    public function loginLogs()    {
        $payload = Request::attr('auth');
        if ($payload === null) {
            return $this->fail('未认证', 401);
        }
        $page = max(1, (int) $this->query('page', 1));
        $size = min(100, max(1, (int) $this->query('size', 20)));
        $uid = (int) $payload->uid;
        $total = (int) (Db::select('SELECT COUNT(*) AS c FROM kode_login_logs WHERE user_id = ?', [$uid])[0]['c'] ?? 0);
        $rows = Db::select(
            'SELECT id, ip, user_agent, device, status, fail_reason, created_at
             FROM kode_login_logs WHERE user_id = ? ORDER BY id DESC LIMIT ? OFFSET ?',
            [$uid, $size, ($page - 1) * $size]
        );
        foreach ($rows as &$row) {
            if ($row['created_at'] instanceof \DateTimeInterface) {
                $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
            }
        }
        unset($row);

        return $this->page($rows, $total, $page, $size);
    }

    /** 清理我的登录日志（保留期，默认 90 天）。 */
    public function loginLogsClean()
    {
        $payload = Request::attr('auth');
        if ($payload === null) {
            return $this->fail('未认证', 401);
        }
        $days = min(3650, max(7, (int) $this->query('days', 90)));
        $deleted = Db::delete(
            "DELETE FROM kode_login_logs WHERE user_id = ? AND created_at < NOW() - (? * INTERVAL '1 day')",
            [(int) $payload->uid, $days]
        );

        return $this->ok(['deleted' => $deleted, 'days' => $days]);
    }

    /** 全员登录日志（超管运维视角）。 */
    public function loginLogsAll()
    {
        $page = max(1, (int) $this->query('page', 1));
        $size = min(100, max(1, (int) $this->query('size', 20)));
        $username = substr(trim((string) $this->query('username', '')), 0, 64);
        $cond = '';
        $params = [];
        if ($username !== '') {
            $cond = 'WHERE username LIKE ?';
            $params[] = '%' . $username . '%';
        }
        $total = (int) (Db::select("SELECT COUNT(*) AS c FROM kode_login_logs {$cond}", $params)[0]['c'] ?? 0);
        $rows = Db::select(
            "SELECT id, user_id, username, ip, device, status, fail_reason, created_at
             FROM kode_login_logs {$cond} ORDER BY id DESC LIMIT ? OFFSET ?",
            [...$params, $size, ($page - 1) * $size]
        );
        foreach ($rows as &$row) {
            if ($row['created_at'] instanceof \DateTimeInterface) {
                $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
            }
        }
        unset($row);

        return $this->page($rows, $total, $page, $size);
    }

    public function password()
    {
        $payload = Request::attr('auth');
        if ($payload === null) {
            return $this->fail('未认证', 401);
        }
        $data = $this->validate(Request::all(), [
            'old_password' => 'required|min:6',
            'new_password' => 'required|min:' . \app\support\SysConfig::getInt('auth.password_min_length', 6, 4, 64) . '|max:64',
        ]);

        $repo = resolve(\app\repositories\UserRepository::class);
        $user = $repo->find((int) $payload->uid);
        if ($user === null) {
            return $this->fail('用户不存在', 404);
        }
        if (!password_verify($data['old_password'], $user->getPassword())) {
            return $this->fail('原密码错误', 400);
        }
        $repo->save($user->setPassword(password_hash($data['new_password'], PASSWORD_DEFAULT)));

        return $this->ok(['updated' => $user->getId()]);
    }

    public function profile()
    {
        $payload = Request::attr('auth');
        if ($payload === null) {
            return $this->fail('未认证', 401);
        }
        $data = $this->validate(Request::all(), [
            'name' => 'required|min:2|max:64',
            'email' => 'required|email',
            'avatar' => 'nullable|max:512',
        ]);

        $repo = resolve(\app\repositories\UserRepository::class);
        $user = $repo->find((int) $payload->uid);
        if ($user === null) {
            return $this->fail('用户不存在', 404);
        }
        if ($data['email'] !== $user->getEmail() && $repo->findByEmail($data['email']) !== null) {
            return $this->fail('邮箱已存在', 409);
        }
        $user->setName($data['name'])->setEmail($data['email']);
        if (array_key_exists('avatar', $data) && $data['avatar'] !== null && $data['avatar'] !== '') {
            $user->setAvatar((string) $data['avatar']);
        }
        $repo->save($user);

        return $this->ok($user->toArray());
    }

    /**
     * 令牌续期（滑动续期：旧 token 必须仍有效；返回新 token 并作废旧 token）。
     */
    public function refresh()
    {
        $payload = Request::attr('auth');
        if ($payload === null) {
            return $this->fail('未认证', 401);
        }
        $token = Request::bearerToken();
        if ($token === null || $token === '') {
            return $this->fail('缺少访问令牌', 401);
        }

        try {
            $new = jwt()->refresh($token);
        } catch (\Throwable) {
            return $this->fail('续期失败，请重新登录', 401);
        }

        return $this->ok($new);
    }
}
