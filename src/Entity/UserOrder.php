<?php

namespace App\Entity;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\UserOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserOrderRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class UserOrder
{
    use CreatedUpdatedAtAwareTrait;

    const PROTECTED_ORDER_VIEW_GROUP = 'protected_order_view_group';

    public static array $dataTableFields = [
        'id',
        'total_amount',
        'description',
        'quantity_product',
        'liq_pay_status',
        'liq_pay_order_id',
        'product_info',
        't_user_info',
        'c_user_info',
        'created_at',
        'updated_at'
    ];

    #[Groups([self::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Groups([self::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private int $total_amount;

    #[Groups([self::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[Groups([self::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?string $quantity_product;

    #[Groups([self::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $liq_pay_status = null;

    #[Groups([self::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $liq_pay_response = null;

    #[Groups([self::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $liq_pay_order_id = null;

    #[Groups([self::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\ManyToOne(targetEntity: TelegramUser::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(name: 'telegram_user_id', referencedColumnName: 'id', onDelete: 'cascade')]
    private ?TelegramUser $telegram_user_id;

    #[Groups([self::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'client_orders')]
    #[ORM\JoinColumn(name: 'client_user_id', referencedColumnName: 'id', onDelete: 'cascade')]
    private ?User $client_user_id;

    #[Groups([self::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true, onDelete: "CASCADE")]
    private ?Product $product_id;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups([self::PROTECTED_ORDER_VIEW_GROUP])]
    #[Assert\NotBlank(message: 'Вкажіть властивості')]
    private ?array $product_properties = [];

    #[Assert\Type('string')]
    #[Assert\Length(min: 12, max: 12, minMessage: 'Phone cannot be less than {{ limit }} characters',
        maxMessage: 'Phone cannot be longer than {{ limit }} characters')]
    #[Assert\Regex(pattern: "/^[0-9]*$/", message: "Please use number only")]
    #[ORM\Column(type: 'string', nullable: true)]
    #[Groups([self::PROTECTED_ORDER_VIEW_GROUP])]
    private ?string $phone;

    #[Groups([self::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\OneToMany(
        targetEntity: PurchaseProduct::class,
        mappedBy: 'userOrder', cascade: ["persist", "remove"], orphanRemoval: true)]
    private Collection $purchaseProduct;

    public function __construct() {
        $this->quantity_product = 1;
        $this->liq_pay_status = null;
        $this->telegram_user_id = null;
        $this->client_user_id = null;
        $this->phone = null;
        $this->purchaseProduct = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): UserOrder
    {
        $this->id = $id;

        return $this;
    }

    public function getTotalAmount(): int
    {
        return $this->total_amount;
    }

    public function setTotalAmount(int $total_amount): UserOrder
    {
        $this->total_amount = $total_amount;

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

    public function getQuantityProduct(): ?string
    {
        return $this->quantity_product;
    }

    public function setQuantityProduct(UserLanguageEnum $language, ?string $quantity_product): UserOrder
    {
        $this->quantity_product = $quantity_product;
        $this->total_amount = $this->product_id->getPrice($language) * $quantity_product;

        return $this;
    }

    public function getLiqPayStatus(): ?string
    {
        return $this->liq_pay_status;
    }

    public function setLiqPayStatus(?string $liq_pay_status): UserOrder
    {
        $this->liq_pay_status = $liq_pay_status;

        return $this;
    }

    public function getLiqPayResponse(): ?string
    {
        return $this->liq_pay_response;
    }

    public function setLiqPayResponse(?string $liq_pay_response): UserOrder
    {
        $this->liq_pay_response = $liq_pay_response;

        return $this;
    }

    public function getLiqPayOrderId(): ?string
    {
        return $this->liq_pay_order_id;
    }

    public function setLiqPayOrderId(?string $liq_pay_order_id): UserOrder
    {
        $this->liq_pay_order_id = $liq_pay_order_id;

        return $this;
    }

    public function getTelegramUserId(): ?TelegramUser
    {
        return $this->telegram_user_id;
    }

    public function setTelegramUserId(?TelegramUser $telegram_user_id): UserOrder
    {
        $this->telegram_user_id = $telegram_user_id;

        return $this;
    }

    public function getClientUserId(): ?User
    {
        return $this->client_user_id;
    }

    public function setClientUserId(?User $client_user_id): UserOrder
    {
        $this->client_user_id = $client_user_id;

        return $this;
    }

    public function getProductId(): ?Product
    {
        return $this->product_id;
    }

    public function setProductId(?Product $product_id): UserOrder
    {
        $this->product_id = $product_id;

        return $this;
    }

    public function getProductProperties(): ?array
    {
        return $this->product_properties;
    }

    public function setProductProperties(?array $product_properties): UserOrder
    {
        $this->product_properties = $product_properties;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): UserOrder
    {
        $this->phone = $phone;

        return $this;
    }

    public function getPurchaseProduct(): Collection
    {
        return $this->purchaseProduct;
    }

    public function setPurchaseProduct(Collection $purchaseProduct): UserOrder
    {
        $this->purchaseProduct = $purchaseProduct;

        return $this;
    }
}
