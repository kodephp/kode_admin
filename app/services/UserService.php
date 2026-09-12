<?php

declare(strict_types=1);

namespace app\services;

use app\models\User;
use app\repositories\UserRepository;
use app\support\HashId;
use Kode\Database\Db\Db;

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
    public function list(int $page, int $size, string $search = '', int $deptId = 0, ?int $status = null, string $start = '', string $end = '', int $id = 0, string $remark = ''): array
    {
        $users = $this->users->page($page, $size, $search, $deptId, $status, $start, $end, $id, $remark);
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
                    : substr((string) $t, 0, 19);
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
        Db::delete('DELETE FROM kode_role_user WHERE user_id = ?', [$id]);
        foreach ($roles as $slug) {
            Db::insert('INSERT INTO kode_role_user (user_id, role_slug) VALUES (?, ?)', [$id, (string) $slug]);
        }

        return array_values($roles);
    }

    public function toggleStatus(int $id): User
    {
        if ($id === 1) {
            throw new \RuntimeException('初始超管账号不可禁用', 403);
        }
        $user = $this->mustFind($id);
        $this->users->save($user->setStatus($user->getStatus() === 1 ? 0 : 1));

        return $user;
    }

    public function resetPassword(int $id, string $password): User
    {
        $user = $this->mustFind($id);
        $this->users->save($user->setPassword(password_hash($password, PASSWORD_DEFAULT)));

        return $user;
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
