<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\ProductCategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: ProductCategoryRepository::class)]
#[ORM\HasLifecycleCallbacks()]
#[UniqueEntity(['category', 'product'])]
#[ORM\UniqueConstraint(
    name: 'unique_product_category',
    columns: ['category_id', 'product_id']
)]
class ProductCategory
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'productCategory')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    private Category $category;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'productCategory')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    private Product $product;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): ProductCategory
    {
        $this->category = $category;

        return $this;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): ProductCategory
    {
        $this->product = $product;
        $product->addProductCategory($this);

        return $this;
    }
}
