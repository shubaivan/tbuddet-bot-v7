<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\ShoppingCartRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ShoppingCartRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class ShoppingCart
{
    use CreatedUpdatedAtAwareTrait;

    const GROUP_VIEW = 'view_shopping_cart';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups([self::GROUP_VIEW])]
    private ?int $id = null;

    /** One Cart has One TelegramUser. */
    #[ORM\OneToOne(targetEntity: TelegramUser::class, inversedBy: 'cart')]
    #[ORM\JoinColumn(name: 'telegram_user_id', referencedColumnName: 'id', onDelete: 'cascade')]
    private TelegramUser|null $telegramUser = null;

    /** One Cart has One User. */
    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'cart')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'cascade')]
    private User|null $user = null;

    #[ORM\OneToMany(
        targetEntity: PurchaseProduct::class,
        mappedBy: 'shoppingCart', cascade: ["persist", "remove"], orphanRemoval: true)]
    private Collection $purchaseProduct;

    public function __construct() {
        $this->purchaseProduct = new ArrayCollection();
    }

    /**
     * @return PurchaseProduct[]
     */
    #[Groups([self::GROUP_VIEW])]
    public function getPurchasedProduct(): array
    {
        return array_values($this->purchaseProduct->filter(function (PurchaseProduct $purchaseProduct) {
            return $purchaseProduct->getUserOrder() ? true : false;
        })->toArray());
    }

    /**
     * @return PurchaseProduct[]
     */
    #[Groups([self::GROUP_VIEW])]
    public function getUnpurchasedProduct(): array
    {
        return array_values($this->purchaseProduct->filter(function (PurchaseProduct $purchaseProduct) {
            return $purchaseProduct->getUserOrder() ? false : true;
        })->toArray());
    }


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

    public function addPurchaseProduct(PurchaseProduct $inputPurchaseProduct): static
    {
        if ($this->getPurchaseProduct()->contains($inputPurchaseProduct)) {
          return $this;
        }

        $inputPurchaseProduct->setShoppingCart($this);
        $this->getPurchaseProduct()->add($inputPurchaseProduct);

        return $this;
    }

    public function getPurchaseProduct(): Collection
    {
        return $this->purchaseProduct ? : new ArrayCollection();
    }

    public function setPurchaseProduct(Collection $purchaseProduct): ShoppingCart
    {
        $this->purchaseProduct = $purchaseProduct;

        return $this;
    }
}
