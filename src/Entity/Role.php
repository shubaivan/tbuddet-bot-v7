<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Entity\Enum\RoleEnum;
use App\Repository\RoleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Table(name: 'roles')]
#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class Role
{
    use CreatedUpdatedAtAwareTrait;

    public const ROLE_FULL = 'role_full';
    public const ROLE_DEFAULT = 'role_default';

    #[Groups([self::ROLE_FULL,])]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[Groups([self::ROLE_DEFAULT, self::ROLE_FULL,])]
    #[ORM\Column(name: 'name', type: 'string', length: 255, unique: true, nullable: false, enumType: RoleEnum::class)]
    private RoleEnum $name;

    #[Groups([self::ROLE_FULL])]
    #[ORM\Column(name: 'description', type: 'string', length: 255, nullable: true)]
    private ?string $description;

    public function __construct()
    {
        $this->name = RoleEnum::USER;
    }

    public function getName(): RoleEnum
    {
        return $this->name;
    }

    public function setName(RoleEnum $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
