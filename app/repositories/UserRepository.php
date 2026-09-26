<?php

declare(strict_types=1);

namespace app\repositories;

use app\models\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * 用户仓储（Doctrine 默认 ORM 用法示范）。
 */
final class UserRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(int $id): ?User
    {
        return $this->em->find(User::class, $id);
    }

    public function findByName(string $name): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['name' => $name]);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    /**
     * 一页用户。窗口由入口的 `PageWindow` 判定，这里只收 `offset`：
     * 仓储层不知道「第几页」，也就没有第二处 `($page-1)*$size` 会溢出。
     *
     * @return list<User>
     */
    public function page(int $offset, int $size, string $search = '', int $deptId = 0, ?int $status = null, string $start = '', string $end = '', int $id = 0, string $remark = ''): array
    {
        if (trim($search) === '' && $deptId <= 0 && $status === null && $start === '' && $end === '' && $id <= 0 && trim($remark) === '') {
            /** @var list<User> */
            return $this->em->getRepository(User::class)->findBy([], ['id' => 'ASC'], $size, $offset);
        }
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->orderBy('u.id', 'ASC')
            ->setMaxResults($size)
            ->setFirstResult($offset);
        $this->applyFilters($qb, $search, $deptId, $status, $start, $end, $id, $remark);

        /** @var list<User> */
        return $qb->getQuery()->getResult();
    }

    public function count(string $search = '', int $deptId = 0, ?int $status = null, string $start = '', string $end = '', int $id = 0, string $remark = ''): int
    {
        if (trim($search) === '' && $deptId <= 0 && $status === null && $start === '' && $end === '' && $id <= 0 && trim($remark) === '') {
            return (int) $this->em->createQuery('SELECT COUNT(u.id) FROM ' . User::class . ' u')
                ->getSingleScalarResult();
        }
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u');
        $this->applyFilters($qb, $search, $deptId, $status, $start, $end, $id, $remark);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @param object $qb */
    private function applyFilters($qb, string $search, int $deptId, ?int $status, string $start, string $end, int $id = 0, string $remark = ''): void
    {
        $and = false;
        if (trim($search) !== '') {
            $qb->where('u.name LIKE :q OR u.email LIKE :q')
                ->setParameter('q', '%' . trim($search) . '%');
            $and = true;
        }
        if ($deptId > 0) {
            $and ? $qb->andWhere('u.deptId = :dept') : $qb->where('u.deptId = :dept');
            $qb->setParameter('dept', $deptId);
            $and = true;
        }
        if ($status !== null) {
            $and ? $qb->andWhere('u.status = :st') : $qb->where('u.status = :st');
            $qb->setParameter('st', $status ? 1 : 0);
            $and = true;
        }
        if ($start !== '') {
            $and ? $qb->andWhere('u.createdAt >= :start') : $qb->where('u.createdAt >= :start');
            $qb->setParameter('start', new \DateTimeImmutable($start));
            $and = true;
        }
        if ($end !== '') {
            $and ? $qb->andWhere('u.createdAt <= :end') : $qb->where('u.createdAt <= :end');
            $qb->setParameter('end', new \DateTimeImmutable($end));
            $and = true;
        }
        if ($id > 0) {
            $and ? $qb->andWhere('u.id = :uid') : $qb->where('u.id = :uid');
            $qb->setParameter('uid', $id);
            $and = true;
        }
        if (trim($remark) !== '') {
            $and ? $qb->andWhere('u.remark LIKE :rm') : $qb->where('u.remark LIKE :rm');
            $qb->setParameter('rm', '%' . trim($remark) . '%');
        }
    }

    public function save(User $user): User
    {
        $user->touch();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function remove(User $user): void
    {
        $this->em->remove($user);
        $this->em->flush();
    }
}
