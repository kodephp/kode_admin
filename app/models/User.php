<?php

declare(strict_types=1);

namespace app\models;

use Doctrine\ORM\Mapping as ORM;

/**
 * 后台用户实体（映射 users 表，pgsql）。
 */
#[ORM\Entity]
#[ORM\Table(name: 'kode_users')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $name = '';

    #[ORM\Column(length: 191, unique: true)]
    private string $email = '';

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(type: 'smallint')]
    private int $status = 1;

    #[ORM\Column(name: 'dept_id', type: 'bigint')]
    private int $deptId = 0;

    #[ORM\Column(length: 512)]
    private string $avatar = '';

    #[ORM\Column(length: 255)]
    private string $remark = '';

    #[ORM\Column(name: 'created_at', type: 'timestamptz_var', nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'timestamptz_var', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hash): static
    {
        $this->password = $hash;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status ? 1 : 0;

        return $this;
    }

    public function getDeptId(): int
    {
        return $this->deptId;
    }

    public function setDeptId(int $deptId): static
    {
        $this->deptId = $deptId;

        return $this;
    }

    public function getAvatar(): string
    {
        return $this->avatar;
    }

    public function setAvatar(string $avatar): static
    {
        $this->avatar = substr($avatar, 0, 512);

        return $this;
    }

    public function getRemark(): string
    {
        return $this->remark;
    }

    public function setRemark(string $remark): static
    {
        $this->remark = substr($remark, 0, 255);

        return $this;
    }

    public function touch(): static
    {
        $now = new \DateTimeImmutable();
        $this->updatedAt ??= $now;
        $this->createdAt ??= $now;

        return $this;
    }

    /** @return array<string, mixed> 脱敏数组（永不含 password；id 对外为 HashId） */
    public function toArray(): array
    {
        return [
            'id' => $this->id === null ? null : \app\support\HashId::enc($this->id),
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'dept_id' => $this->deptId === 0 ? null : \app\support\HashId::enc($this->deptId),
            'avatar' => $this->avatar,
            'remark' => $this->remark,
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
        ];
    }
}
