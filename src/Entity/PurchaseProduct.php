<?php

namespace App\Entity;

use App\Repository\PurchaseProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;

#[ORM\Entity(repositoryClass: PurchaseProductRepository::class)]
class PurchaseProduct
{
    const GROUP_VIEW = 'view_purchase_product';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups([UserOrder::PROTECTED_ORDER_VIEW_GROUP, ShoppingCart::GROUP_VIEW, self::GROUP_VIEW])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShoppingCart::class, inversedBy: 'purchaseProduct')]
    #[ORM\JoinColumn(name: 'shopping_cart_id', referencedColumnName: 'id', onDelete: "CASCADE")]
    private ShoppingCart $shoppingCart;

    #[Groups([UserOrder::PROTECTED_ORDER_VIEW_GROUP, self::GROUP_VIEW])]
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'purchaseProduct')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', onDelete: "CASCADE")]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: UserOrder::class, inversedBy: 'purchaseProduct')]
    #[ORM\JoinColumn(name: 'user_order_id', referencedColumnName: 'id', nullable: true, onDelete: "CASCADE")]
    private ?UserOrder $userOrder;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups([ShoppingCart::GROUP_VIEW, self::GROUP_VIEW])]
    private array $product_properties = [];

    #[Type('int')]
    #[NotBlank]
    #[NotNull]
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups([ShoppingCart::GROUP_VIEW, self::GROUP_VIEW])]
    protected int $quantity;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShoppingCart(): ShoppingCart
    {
        return $this->shoppingCart;
    }

    public function setShoppingCart(ShoppingCart $shoppingCart): PurchaseProduct
    {
        $this->shoppingCart = $shoppingCart;

        return $this;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): PurchaseProduct
    {
        $this->product = $product;

        return $this;
    }

    public function getProductProperties(): array
    {
        return $this->product_properties;
    }

    public function setProductProperties(array $product_properties): PurchaseProduct
    {
        $this->product_properties = $product_properties;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): PurchaseProduct
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUserOrder(): ?UserOrder
    {
        return $this->userOrder;
    }

    public function setUserOrder(?UserOrder $userOrder): PurchaseProduct
    {
        $this->userOrder = $userOrder;

        return $this;
    }
}
