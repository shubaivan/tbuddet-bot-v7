<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints\NotBlank;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class Product implements AttachmentFilesInterface
{
    use CreatedUpdatedAtAwareTrait;

    const ADMIN_PRODUCT_VIEW_GROUP = 'admin_product_view_group';

    public static array $dataTableFields = [
        'id',
        'filePath',
        'product_name',
        'price',
        'product_properties',
        'created_at',
        'updated_at'
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups([self::ADMIN_PRODUCT_VIEW_GROUP])]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Groups([self::ADMIN_PRODUCT_VIEW_GROUP])]
    #[NotBlank(message: 'Вкажіть назву')]
    private string $product_name;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups([self::ADMIN_PRODUCT_VIEW_GROUP])]
    #[NotBlank(message: 'Вкажіть властивості')]
    private array $product_properties = [];

    #[ORM\Column(type: 'string', length: 255)]
    #[Groups([self::ADMIN_PRODUCT_VIEW_GROUP])]
    #[NotBlank(message: 'Вкажіть ціну')]
    private string $price;

    #[ORM\OneToMany(
        targetEntity: UserOrder::class,
        mappedBy: 'product_id', cascade: ["persist", "remove"], orphanRemoval: true)]
    private Collection $orders;

    #[ORM\OneToMany(targetEntity: Files::class, mappedBy: 'product', orphanRemoval: true, cascade: ["persist"])]
    #[Groups([self::ADMIN_PRODUCT_VIEW_GROUP])]
    private Collection $files;

    #[ORM\OneToMany(targetEntity: ProductCategory::class, mappedBy: 'product', orphanRemoval: true, cascade: ["persist"])]
    private Collection $productCategory;

    public function __construct() {
        $this->orders = new ArrayCollection();
        $this->files = new ArrayCollection();
        $this->productCategory = new ArrayCollection();
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
            $i = 1;
            $map = array_map(function ($p) use (&$i) {
                $str = $i == 1 ? '<b>' . $p . '</b>' : $p;
                $i++;
                return $str;
            }, $property);
            $prop[] = implode(': ', $map);
        }

        return implode(PHP_EOL, $prop);
    }

    public function setProductProperties(array $product_properties): Product
    {
        $this->product_properties = $product_properties;

        return $this;
    }


    public function checkFileExist($name)
    {
        $isCheck = false;
        $files = $this->getFiles()->getValues();
        foreach ($files as $file) {
            /** @var Files $file */
            $isCheck = ($file->getOriginalName() === $name);
            if ($isCheck) {
                break;
            }
        }

        return $isCheck;
    }

    /**
     * @return Collection|Files[]
     */
    public function getFiles(): Collection
    {
        if (!$this->files) {
            $this->files = new ArrayCollection();
        }

        return $this->files;
    }

    public function addFile(Files $file): self
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
        }

        return $this;
    }

    public function removeFile(Files $file): self
    {
        if ($this->files->contains($file)) {
            $this->files->removeElement($file);
        }

        return $this;
    }

    /**
     * @return Collection|ProductCategory[]
     */
    public function getProductCategory(): Collection
    {
        return $this->productCategory;
    }

    public function setProductCategory(Collection $productCategory): Product
    {
        $this->productCategory = $productCategory;

        return $this;
    }

    public function addProductCategory(ProductCategory $productCategory): self
    {
        if (!$this->productCategory->contains($productCategory)) {
            $this->productCategory->add($productCategory);
        }

        return $this;
    }

    public function removeProductCategory(ProductCategory $productCategory): self
    {
        if ($this->productCategory->contains($productCategory)) {
            $this->productCategory->removeElement($productCategory);
        }

        return $this;
    }
}
