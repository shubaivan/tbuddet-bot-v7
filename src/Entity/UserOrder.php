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

    public static array $dataTableFields = [
        'id',
        'total_amount',
        'description',
        'quantity_product',
        'liq_pay_status',
        'liq_pay_order_id',
        'product_info',
        'user_info',
        'created_at',
        'updated_at'
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private int $total_amount;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $description;

    #[ORM\Column(type: 'integer', nullable: false)]
    private string $quantity_product;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $liq_pay_status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $liq_pay_response;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $liq_pay_order_id;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(name: 'telegram_user_id', referencedColumnName: 'id')]
    private TelegramUser $telegram_user_id;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id')]
    private Product $product_id;

    public function __construct() {
        $this->quantity_product = 1;
        $this->liq_pay_status = null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTotalAmount(): string
    {
        return $this->total_amount;
    }

    public function setTotalAmount(string $total_amount): UserOrder
    {
        $this->total_amount = $total_amount;

        return $this;
    }

    public function getQuantityProduct(): string
    {
        return $this->quantity_product;
    }

    public function setQuantityProduct(string $quantity_product): UserOrder
    {
        $this->quantity_product = $quantity_product;
        $this->total_amount = $this->product_id->getPrice() * $quantity_product;
        return $this;
    }

    public function getTelegramUserid(): TelegramUser
    {
        return $this->telegram_user_id;
    }

    public function setTelegramUserid(TelegramUser $telegram_user_id): UserOrder
    {
        $this->telegram_user_id = $telegram_user_id;

        return $this;
    }

    public function getProductId(): Product
    {
        return $this->product_id;
    }

    public function setProductId(Product $product_id): UserOrder
    {
        $this->product_id = $product_id;

        return $this;
    }

    public function getLiqPaystatus(): ?string
    {
        return $this->liq_pay_status;
    }

    public function setLiqPaystatus(?string $liq_pay_status): UserOrder
    {
        $this->liq_pay_status = $liq_pay_status;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): UserOrder
    {
        $this->description = $description;

        return $this;
    }

    public function getLiqPayresponse(): ?string
    {
        return $this->liq_pay_response;
    }

    public function setLiqPayresponse(?string $liq_pay_response): UserOrder
    {
        $this->liq_pay_response = $liq_pay_response;

        return $this;
    }

    public function getLiqPayorderid(): ?string
    {
        return $this->liq_pay_order_id;
    }

    public function setLiqPayorderid(?string $liq_pay_order_id): UserOrder
    {
        $this->liq_pay_order_id = $liq_pay_order_id;

        return $this;
    }
}
