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

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $productName;

    #[ORM\Column(type: 'string', length: 255)]
    private string $productInternalName;

    #[ORM\Column(type: 'float')]
    private float $diameter;

    #[ORM\Column(type: 'string', length: 255)]
    private string $price;

    #[ORM\OneToMany(targetEntity: UserOrder::class, mappedBy: 'productId', cascade: ["persist"])]
    private Collection $orders;

    public function __construct(
        string $name,
        string $productInternalName,
        int $price,
        float $diameter
    ) {
        $this->orders = new ArrayCollection();
        $this->productName = $name;
        $this->price = $price;
        $this->productInternalName = $productInternalName;
        $this->diameter = $diameter;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function setProductName(string $productName): Product
    {
        $this->productName = $productName;

        return $this;
    }

    public function getProductInternalName(): string
    {
        return $this->productInternalName;
    }

    public function setProductInternalName(string $productInternalName): Product
    {
        $this->productInternalName = $productInternalName;

        return $this;
    }

    public function getDiameter(): float
    {
        return $this->diameter;
    }

    public function setDiameter(float $diameter): Product
    {
        $this->diameter = $diameter;

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
}
