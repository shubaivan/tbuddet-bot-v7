<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\UserMergeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserMergeRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class UserMerge
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** One Cart has One TelegramUser. */
    #[ORM\OneToOne(targetEntity: TelegramUser::class, inversedBy: 'merge')]
    #[ORM\JoinColumn(name: 'telegram_user_id', referencedColumnName: 'id', onDelete: 'cascade')]
    private TelegramUser|null $telegramUser = null;

    /** One Cart has One User. */
    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'merge')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'cascade')]
    private User|null $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelegramUser(): ?TelegramUser
    {
        return $this->telegramUser;
    }

    public function setTelegramUser(?TelegramUser $telegramUser): UserMerge
    {
        $this->telegramUser = $telegramUser;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): UserMerge
    {
        $this->user = $user;

        return $this;
    }
}
