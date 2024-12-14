<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\ShoppingCartRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShoppingCartRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class ShoppingCart
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** One Cart has One TelegramUser. */
    #[ORM\OneToOne(targetEntity: TelegramUser::class, inversedBy: 'cart')]
    #[ORM\JoinColumn(name: 'telegram_user_id', referencedColumnName: 'id', onDelete: 'cascade')]
    private TelegramUser|null $telegramUser = null;

    /** One Cart has One User. */
    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'cart')]
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

    public function setTelegramUser(?TelegramUser $telegramUser): ShoppingCart
    {
        $this->telegramUser = $telegramUser;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): ShoppingCart
    {
        $this->user = $user;

        return $this;
    }
}
