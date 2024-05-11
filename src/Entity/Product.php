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

    #[ORM\Column(type: 'json', nullable: true)]
    private array $product_properties = [];

    #[ORM\Column(type: 'string', length: 255)]
    private string $price;

    #[ORM\OneToMany(targetEntity: UserOrder::class, mappedBy: 'productId', cascade: ["persist"])]
    private Collection $orders;

    public function __construct() {
        $this->orders = new ArrayCollection();
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

    public function getProductPropertiesMessage(): string
    {
        $prop = [];
        foreach ($this->product_properties as $property) {
            $prop[] = implode('; ', array_reverse($property));
        }

        return implode(PHP_EOL, $prop);
    }

    public function setProductProperties(array $product_properties): Product
    {
        $this->product_properties = $product_properties;

        return $this;
    }
}
