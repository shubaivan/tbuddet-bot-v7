<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\UserOrderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserOrderRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class UserOrder
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $totalAmount;

    #[ORM\Column(type: 'integer', nullable: false)]
    private string $quantityProduct;

    #[ORM\Column(type: 'string')]
    private string $liqPayStatus;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(name: 'telegram_user_id', referencedColumnName: 'id')]
    private TelegramUser $telegramUserId;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id')]
    private Product $productId;

    public function __construct() {
        $this->quantityProduct = 1;
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): UserOrder
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    public function getQuantityProduct(): string
    {
        return $this->quantityProduct;
    }

    public function setQuantityProduct(string $quantityProduct): UserOrder
    {
        $this->quantityProduct = $quantityProduct;
        $this->totalAmount = $this->productId->getPrice() * $quantityProduct;
        return $this;
    }

    public function getTelegramUserId(): TelegramUser
    {
        return $this->telegramUserId;
    }

    public function setTelegramUserId(TelegramUser $telegramUserId): UserOrder
    {
        $this->telegramUserId = $telegramUserId;

        return $this;
    }

    public function getProductId(): Product
    {
        return $this->productId;
    }

    public function setProductId(Product $productId): UserOrder
    {
        $this->productId = $productId;

        return $this;
    }

    public function getLiqPayStatus(): string
    {
        return $this->liqPayStatus;
    }

    public function setLiqPayStatus(string $liqPayStatus): UserOrder
    {
        $this->liqPayStatus = $liqPayStatus;

        return $this;
    }
}
