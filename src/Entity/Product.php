<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class Product
{
    use CreatedUpdatedAtAwareTrait;

    public static array $dataTableFields = [
        'id',
        'product_name',
        'product_internal_name',
        'price',
        'product_properties',
        'created_at',
        'updated_at'
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $product_name;

    #[ORM\Column(type: 'string', length: 255)]
    private string $product_internal_name;

    #[ORM\Column(type: 'json', nullable: true)]
    private array $product_properties = [];

    #[ORM\Column(type: 'string', length: 255)]
    private string $price;

    #[ORM\OneToMany(targetEntity: UserOrder::class, mappedBy: 'productId', cascade: ["persist"])]
    private Collection $orders;

    public function __construct(
        string $name,
        string $productInternalName,
        int $price
    ) {
        $this->orders = new ArrayCollection();
        $this->product_name = $name;
        $this->price = $price;
        $this->product_internal_name = $productInternalName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductName(): string
    {
        return $this->product_name;
    }

    public function setProductName(string $product_name): Product
    {
        $this->product_name = $product_name;

        return $this;
    }

    public function getProductInternalname(): string
    {
        return $this->product_internal_name;
    }

    public function setProductInternalname(string $product_internal_name): Product
    {
        $this->product_internal_name = $product_internal_name;

        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): Product
    {
        $this->price = $price;

        return $this;
    }

    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function setOrders(Collection $orders): Product
    {
        $this->orders = $orders;

        return $this;
    }

    public function getProductProperties(): array
    {
        return $this->product_properties;
    }

    public function setProductProperties(array $product_properties): Product
    {
        $this->product_properties = $product_properties;

        return $this;
    }
}
