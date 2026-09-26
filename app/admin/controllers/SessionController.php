<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use app\support\Clip;
use app\support\SysLog;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 会话管理：活跃用户列表 + 强制下线 + 在线统计。
 *
 * 基于 JWT 黑名单机制实现：
 * - 活跃会话从 kode_login_logs 表查询（24h 内成功登录记录）
 * - 强制下线通过 EncryptedJwtGuard::revokeUserTokens() 撤销用户所有令牌
 *   （内部自增 id 由守卫换成令牌里落地的 `u_` 不透明串，撤销才打得中列表键）
 */
final class SessionController extends Controller
{
    use ApiResponse;
    use PageWindow;

    /**
     * GET /api/sessions — 活跃会话列表（24h 内成功登录记录）。
     *
     * Query: ?page=<n> & size=<n> & keyword=<user/ip> & status=<active|inactive|all>
     * 分页窗口口径见 app\concerns\PageWindow（size 夹取、page 越界 400）。
     */
    public function index()
    {
        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];
        $keyword = $this->query('keyword', null);
        $status = $this->query('status', 'all');

        // 用户名取日志行自带的 username（kode_login_logs.username）：用户被删后仍可读。
        // kode_users 只有 name/email，没有 username/realname 列，按那两个名字查必然 500。
        $where = ["ll.status = 1", "ll.created_at >= NOW() - INTERVAL '1 day'"];
        $params = [];
        if ($keyword !== null && $keyword !== '') {
            $where[] = '(ll.ip LIKE ? OR ll.username LIKE ? OR u.name LIKE ?)';
            $kw = '%' . $keyword . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }

        // 活跃状态过滤：active = 1h 内有活动
        if ($status === 'active') {
            $where[] = "ll.created_at >= NOW() - INTERVAL '1 hour'";
        } elseif ($status === 'inactive') {
            $where[] = "ll.created_at < NOW() - INTERVAL '1 hour'";
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countParams = $params;
        $total = (int) (Db::select(
            "SELECT COUNT(*) AS c FROM kode_login_logs ll
             LEFT JOIN kode_users u ON ll.user_id = u.id {$whereSql}",
            $countParams
        )[0]['c'] ?? 0);

        $params[] = $size;
        $params[] = $offset;

        $rows = Db::select(
            'SELECT ll.id, ll.user_id, ll.ip, ll.user_agent, ll.created_at,
                    ll.username, u.name, u.status AS user_status
             FROM kode_login_logs ll
             LEFT JOIN kode_users u ON ll.user_id = u.id
             ' . $whereSql . '
             ORDER BY ll.created_at DESC
             LIMIT ? OFFSET ?',
            $params
        );

        // 获取当前管理员 user_id 以标记"当前会话"
        $payload = Request::attr('auth');
        $currentUserId = (int) ($payload->uid ?? 0);

        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                // 对外 id 一律 HashId 混淆（AGENTS.md §4 防枚举）
                'id' => \app\support\HashId::enc((int) $row['id']),
                'user_id' => \app\support\HashId::enc((int) $row['user_id']),
                'username' => (string) ($row['username'] !== '' && $row['username'] !== null
                    ? $row['username']
                    : ($row['name'] ?? '—')),
                'user_status' => (string) ($row['user_status'] ?? ''),
                'ip' => (string) ($row['ip'] ?? ''),
                'user_agent' => $this->parseUA((string) ($row['user_agent'] ?? '')),
                'login_at' => $this->formatTime($row['created_at']),
                'active' => $this->isActive($row['created_at']),
                'is_current' => ((int) $row['user_id'] === $currentUserId),
            ];
        }

        return $this->page($list, $total, $page, $size);
    }

    /**
     * GET /api/sessions/online-count — 当前在线人数（24h 内去重成功登录用户数）。
     */
    public function onlineCount()
    {
        // 与列表同口径：只看成功登录（status = 1），且排除匿名/失败行的 user_id = 0
        $total = (int) (Db::select(
            "SELECT COUNT(DISTINCT user_id) AS c FROM kode_login_logs
             WHERE status = 1 AND user_id > 0 AND created_at >= NOW() - INTERVAL '1 day'"
        )[0]['c'] ?? 0);

        $active1h = (int) (Db::select(
            "SELECT COUNT(DISTINCT user_id) AS c FROM kode_login_logs
             WHERE status = 1 AND user_id > 0 AND created_at >= NOW() - INTERVAL '1 hour'"
        )[0]['c'] ?? 0);

        return $this->ok([
            'total_24h' => $total,
            'active_1h' => $active1h,
        ]);
    }

    /**
     * DELETE /api/sessions/{id} — 踢出指定会话（撤销该登录用户的全部令牌）。
     *
     * 令牌按「用户 + 平台」维护，登录日志不携带 jti，所以这里撤销的是该用户所有平台的
     * 全部令牌（同一用户多端登录会一起下线），返回实际撤销条数。
     */
    public function revoke()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('会话不存在', 404);
        }
        $row = Db::select(
            'SELECT id, user_id, ip FROM kode_login_logs WHERE id = ? LIMIT 1',
            [$id]
        );

        if ($row === []) {
            return $this->fail('会话不存在', 404);
        }

        $row = $row[0];
        $userId = (int) $row['user_id'];

        try {
            $revoked = resolve(\app\auth\EncryptedJwtGuard::class)->revokeUserTokens($userId);
        } catch (\Throwable $e) {
            // 明细只进日志：响应里回显异常消息会把存储地址/连接串等内部信息带给前端
            SysLog::error('session', '强制下线失败：' . $e->getMessage());
            return $this->fail('强制下线失败，请稍后重试', 500);
        }

        SysLog::info('session', "强制下线：uid={$userId} 撤销 {$revoked} 个令牌（IP: {$row['ip']}）");

        return $this->ok([
            'user_id' => \app\support\HashId::enc($userId),
            'revoked' => $revoked,
            'action' => 'revoked',
        ]);
    }

    /**
     * POST /api/sessions/clear-all — 清理全部过期会话（撤销超过 24h 的登录用户令牌）。
     */
    public function clearAll()
    {
        $rows = Db::select(
            "SELECT DISTINCT user_id FROM kode_login_logs
             WHERE created_at < NOW() - INTERVAL '1 day' AND status = 1 AND user_id > 0"
        );

        $guard = resolve(\app\auth\EncryptedJwtGuard::class);
        $users = 0;
        $revoked = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $revoked += $guard->revokeUserTokens((int) $row['user_id']);
                $users++;
            } catch (\Throwable $e) {
                $failed++;
                SysLog::error('session', '清理过期会话失败：uid=' . (int) $row['user_id'] . ' ' . $e->getMessage());
            }
        }

        SysLog::info('session', "清理过期会话：{$users} 个用户撤销 {$revoked} 个令牌" . ($failed > 0 ? "，失败 {$failed} 个" : ''));

        return $this->ok(['users' => $users, 'revoked' => $revoked, 'failed' => $failed]);
    }

    // ─── 私有辅助 ───────────────────────────────────────────

    private function isActive(mixed $createdAt): bool
    {
        if ($createdAt instanceof \DateTimeInterface) {
            $ts = $createdAt->getTimestamp();
        } elseif (is_string($createdAt)) {
            $ts = strtotime($createdAt);
        } else {
            return false;
        }
        return $ts !== false && (time() - $ts) < 3600;
    }

    private function parseUA(string $ua): array
    {
        $os = 'Unknown';
        $browser = 'Unknown';

        if (preg_match('/Windows NT/', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X/', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/', $ua)) {
            $os = 'Linux';
        } elseif (preg_match('/iPhone|iPad/', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Android/', $ua)) {
            $os = 'Android';
        }

        if (preg_match('/Firefox/', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Edg|Edge/', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome/', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari/', $ua)) {
            $browser = 'Safari';
        }

        return ['os' => $os, 'browser' => $browser, 'raw' => Clip::chars($ua, 120)];
    }

    private function formatTime(mixed $val): string
    {
        if ($val instanceof \DateTimeInterface) {
            return $val->format('Y-m-d H:i:s');
        }
        return (string) $val;
    }
}