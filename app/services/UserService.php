<?php

declare(strict_types=1);

namespace app\services;

use app\models\User;
use app\repositories\UserRepository;
use app\support\HashId;
use Kode\Database\Db\Db;
use Kode\Event\Dispatcher;

/**
 * 用户域服务（四层架构的 Service 层范例）。
 *
 * 分层职责：Controller 只做收参/校验/信封；Service 承载业务规则 + 编排仓储/Db；
 * Repository 只做 persistence；Model 只做映射 + 脱敏。跨聚合用事件，不直接调其它 Service。
 */
final class UserService
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $offset, int $size, string $search = '', int $deptId = 0, ?int $status = null, string $start = '', string $end = '', int $id = 0, string $remark = ''): array
    {
        $users = $this->users->page(
            offset: $offset,
            size: $size,
            search: $search,
            deptId: $deptId,
            status: $status,
            start: $start,
            end: $end,
            id: $id,
            remark: $remark
        );
        $ids = array_map(static fn(User $u): int => (int) $u->getId(), $users);
        $rolesByUser = [];
        $deptNames = [];
        $lastLogin = [];
        if ($ids !== []) {
            $holders = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::select(
                "SELECT user_id, role_slug FROM kode_role_user WHERE user_id IN ({$holders})",
                $ids
            ) as $r) {
                $rolesByUser[(int) $r['user_id']][] = (string) $r['role_slug'];
            }
            foreach (Db::select(
                "SELECT user_id, MAX(created_at) AS t FROM kode_login_logs WHERE user_id IN ({$holders}) AND status = 1 GROUP BY user_id",
                $ids
            ) as $r) {
                $t = $r['t'];
                $lastLogin[(int) $r['user_id']] = $t instanceof \DateTimeInterface
                    ? $t->format('Y-m-d H:i:s')
                    : \app\support\Clip::chars((string) $t, 19);
            }
            $deptIds = array_values(array_unique(array_filter(
                array_map(static fn(User $u): int => $u->getDeptId(), $users),
                static fn(int $id): bool => $id > 0
            )));
            if ($deptIds !== []) {
                $holders = implode(',', array_fill(0, count($deptIds), '?'));
                foreach (Db::select(
                    "SELECT id, name FROM kode_depts WHERE id IN ({$holders})",
                    $deptIds
                ) as $d) {
                    $deptNames[(int) $d['id']] = (string) $d['name'];
                }
            }
        }

        return array_map(static function (User $u) use ($rolesByUser, $deptNames, $lastLogin): array {
            $row = $u->toArray();
            $row['email'] = \app\support\Mask::email($row['email']);
            $row['roles'] = $rolesByUser[(int) $u->getId()] ?? [];
            $row['dept_name'] = $deptNames[$u->getDeptId()] ?? '';
            $row['last_login'] = $lastLogin[(int) $u->getId()] ?? '';

            return $row;
        }, $users);
    }

    public function count(string $search = '', int $deptId = 0, ?int $status = null, string $start = '', string $end = '', int $id = 0, string $remark = ''): int
    {
        return $this->users->count($search, $deptId, $status, $start, $end, $id, $remark);
    }

    public function create(string $name, string $email, string $password, int $deptId = 0, string $remark = ''): User
    {
        if ($this->users->findByEmail($email) !== null) {
            throw new \RuntimeException('邮箱已存在', 409);
        }

        $user = (new User())
            ->setName($name)
            ->setEmail($email)
            ->setPassword(password_hash($password, PASSWORD_DEFAULT))
            ->setDeptId($deptId)
            ->setRemark($remark);
        $this->users->save($user);

        // 派发用户创建事件（钱包/通知等插件可监听此事件自动开户/发通知）
        try {
            resolve(Dispatcher::class)->dispatch('user.created', [
                'user_id' => (int) $user->getId(),
                'name' => $name,
                'email' => $email,
                'dept_id' => $deptId,
            ]);
        } catch (\Throwable) {
            // 事件派发失败不阻断用户创建
        }

        return $user;
    }

    public function rename(int $id, string $name, int $deptId = -1, ?string $remark = null): User
    {
        $user = $this->mustFind($id);
        $user->setName($name);
        if ($deptId >= 0) {
            $user->setDeptId($deptId);
        }
        if ($remark !== null) {
            $user->setRemark($remark);
        }
        $this->users->save($user);

        return $user;
    }

    public function remove(int $id): void
    {
        if ($id === 1) {
            throw new \RuntimeException('初始超管账号不可删除', 403);
        }
        $user = $this->mustFind($id);
        $this->invalidateSessions($id);
        Db::delete('DELETE FROM kode_role_user WHERE user_id = ?', [$id]);
        $this->users->remove($user);
    }

    /** 批量删除（跳过超管与不存在，返回成功数；单个失败不中断）。 */
    public function batchRemove(array $ids): int
    {
        $done = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id <= 1) {
                continue;
            }
            try {
                $this->remove($id);
                $done++;
            } catch (\RuntimeException) {
            }
        }

        return $done;
    }

    /** 批量分配角色（覆盖式）。 */
    public function batchAssignRoles(array $ids, array $roles): int
    {
        // 整批先校验：放在循环里的话每人抛一次错、被下面的 catch 吞掉，done=0 照样回 200
        $roles = $this->assertRolesExist($roles);
        $done = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id <= 0) {
                continue;
            }
            try {
                $this->assignRoles($id, $roles);
                $done++;
            } catch (\RuntimeException) {
            }
        }

        return $done;
    }

    public function assignRoles(int $id, array $roles): array
    {
        $this->mustFind($id);
        $roles = $this->assertRolesExist($roles);
        Db::delete('DELETE FROM kode_role_user WHERE user_id = ?', [$id]);
        foreach ($roles as $slug) {
            Db::insert('INSERT INTO kode_role_user (user_id, role_slug) VALUES (?, ?)', [$id, (string) $slug]);
        }
        // 角色写在 JWT 载荷里、RequireRole 只读载荷不查库：不撤销的话，「降权」要等到令牌自然过期才生效
        $this->invalidateSessions($id);

        return array_values($roles);
    }

    /**
     * 撤销「当前挂着某角色」的全部账号会话，返回撤销人数。
     *
     * 角色被禁用/删除时必调：slug 铸在 JWT 载荷里，`RequireRole` 只读载荷不查库，
     * 不撤销的话这些成员要等到令牌自然过期才掉权限。
     * 挂着孤儿授权（用户已删）的行跳过即可，不该让角色页因此 500。
     */
    public function invalidateSessionsByRole(string $slug): int
    {
        $done = 0;
        foreach (Db::select('SELECT user_id FROM kode_role_user WHERE role_slug = ?', [$slug]) as $row) {
            try {
                $this->invalidateSessions((int) $row['user_id']);
                $done++;
            } catch (\Throwable) {
            }
        }

        return $done;
    }

    public function toggleStatus(int $id): User
    {
        if ($id === 1) {
            throw new \RuntimeException('初始超管账号不可禁用', 403);
        }
        $user = $this->mustFind($id);
        $wasDisabled = $user->getStatus() !== 1;
        $this->users->save($user->setStatus($wasDisabled ? 1 : 0));
        // 禁用只在登录口拦（403），在发令牌照跑：撤销后「禁用即下线」才是真的
        if (!$wasDisabled) {
            $this->invalidateSessions($id);
        }

        return $user;
    }

    public function resetPassword(int $id, string $password): User
    {
        $user = $this->mustFind($id);
        // 重置密码的动机通常是「这个账号可能被人用了」。只换密码不作废令牌，
        // 被接管的那把会话照跑不误，处置动作等于被静默吞掉（撤销放在落库前，失败可重试）。
        $this->invalidateSessions($id);
        $this->users->save($user->setPassword(password_hash($password, PASSWORD_DEFAULT)));

        return $user;
    }

    /**
     * 角色必须真实存在：`kode_role_user.role_slug` 没有外键，拼错的名字照样写得进去、
     * 既不报错也不授权，界面回显「已分配」而实际权限为空 —— 授权类操作里最坏的静默失败。
     * 空数组是合法语义（清空角色），原样返回。
     *
     * @param array<array-key, mixed> $roles
     * @return list<string>
     */
    private function assertRolesExist(array $roles): array
    {
        $slugs = array_values(array_unique(array_map(
            static fn($role): string => trim((string) $role),
            $roles
        )));
        if ($slugs === []) {
            return [];
        }

        $holders = implode(',', array_fill(0, count($slugs), '?'));
        $known = array_column(
            Db::select("SELECT slug FROM kode_roles WHERE slug IN ({$holders})", $slugs),
            'slug'
        );
        $missing = array_values(array_diff($slugs, $known));
        if ($missing !== []) {
            throw new \RuntimeException('角色不存在：' . implode(', ', $missing), 400);
        }

        return $slugs;
    }

    /**
     * 撤销某用户全部在发令牌：登录态安全字段（角色/状态/口令/账号本身）改动后必须即时失效，
     * 因为鉴权只验签、不回查库。调用方按 401 重登（前端已有该处理）。
     */
    private function invalidateSessions(int $id): void
    {
        resolve(\app\auth\EncryptedJwtGuard::class)->revokeUserTokens($id);
    }

    public function eid(User $user): string
    {
        return HashId::enc((int) $user->getId());
    }

    private function mustFind(int $id): User
    {
        $user = $this->users->find($id);
        if ($user === null) {
            throw new \RuntimeException('用户不存在', 404);
        }

        return $user;
    }
}
