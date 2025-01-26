<?php

namespace App\Entity;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Controller\API\Request\Purchase\ProductProperties;
use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class Product implements AttachmentFilesInterface
{
    use CreatedUpdatedAtAwareTrait;

    const ADMIN_PRODUCT_VIEW_GROUP = 'admin_product_view_group';
    const PUBLIC_PRODUCT_VIEW_GROUP = 'public_product_view_group';

    private static array $propertyKeyMap = [
        'property_price_impact' => 'Збільшення ціни',
        'property_name' => 'Назва',
        'property_value' => 'Значення',
    ];

    public static array $dataTableFields = [
        'id',
        'categories',
        'filePath',
        'product_name',
        'price',
        'description',
        'product_properties',
        'created_at',
        'updated_at'
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups([
        self::ADMIN_PRODUCT_VIEW_GROUP,
        self::PUBLIC_PRODUCT_VIEW_GROUP,
        UserOrder::PROTECTED_ORDER_VIEW_GROUP,
        ShoppingCart::GROUP_VIEW,
        PurchaseProduct::GROUP_VIEW
    ])]
    private ?int $id = null;

    #[ORM\Column(type: 'jsonb')]
    #[Groups([
        self::ADMIN_PRODUCT_VIEW_GROUP,
        self::PUBLIC_PRODUCT_VIEW_GROUP,
        UserOrder::PROTECTED_ORDER_VIEW_GROUP,
        ShoppingCart::GROUP_VIEW,
        PurchaseProduct::GROUP_VIEW
    ])]
    #[NotBlank(message: 'Вкажіть назву')]
    private mixed $product_name;

    #[ORM\Column(type: 'jsonb', nullable: true)]
    #[Groups([
        self::ADMIN_PRODUCT_VIEW_GROUP,
        self::PUBLIC_PRODUCT_VIEW_GROUP,
        ShoppingCart::GROUP_VIEW
    ])]
    #[NotBlank(message: 'Вкажіть властивості')]
    private array $product_properties = [];

    #[ORM\Column(type: 'integer')]
    #[Groups([
        self::ADMIN_PRODUCT_VIEW_GROUP,
        self::PUBLIC_PRODUCT_VIEW_GROUP,
        PurchaseProduct::GROUP_VIEW,
        ShoppingCart::GROUP_VIEW
    ])]
    #[NotBlank(message: 'Вкажіть ціну')]
    private mixed $price;

    #[ORM\Column(type: 'jsonb', nullable: true)]
    #[Groups([
        self::ADMIN_PRODUCT_VIEW_GROUP,
        self::PUBLIC_PRODUCT_VIEW_GROUP,
        PurchaseProduct::GROUP_VIEW,
        ShoppingCart::GROUP_VIEW
    ])]
    #[NotBlank(message: 'Вкажіть опис')]
    private mixed $description;

    #[ORM\OneToMany(
        targetEntity: UserOrder::class,
        mappedBy: 'product_id', cascade: ["persist", "remove"], orphanRemoval: true)]
    private Collection $orders;

    #[ORM\OneToMany(targetEntity: Files::class, mappedBy: 'product', orphanRemoval: true, cascade: ["persist"])]
    #[Groups([
        self::ADMIN_PRODUCT_VIEW_GROUP
    ])]
    private Collection $files;

    #[ORM\OneToMany(targetEntity: ProductCategory::class, mappedBy: 'product', orphanRemoval: true, cascade: ["persist"])]
    #[Count(min: 1, minMessage: "Має бути хоча б одна категорія")]
    private Collection $productCategory;

    #[Groups([
        Product::PUBLIC_PRODUCT_VIEW_GROUP,
        ShoppingCart::GROUP_VIEW
    ])]
    private array $file_path = [];

    #[ORM\OneToMany(
        targetEntity: PurchaseProduct::class,
        mappedBy: 'product', cascade: ["persist", "remove"], orphanRemoval: true)]
    private Collection $purchaseProduct;

    public function __construct() {
        $this->orders = new ArrayCollection();
        $this->files = new ArrayCollection();
        $this->productCategory = new ArrayCollection();
        $this->purchaseProduct = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductName(?UserLanguageEnum $language = null): mixed
    {
        return ($language !== null && isset($this->product_name[$language->value]))
            ? $this->product_name[$language->value]
            : $this->product_name;
    }

    public function setProductName(mixed $product_name): Product
    {
        $this->product_name = $product_name;

        return $this;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(mixed $price): Product
    {
        $this->price = (int)$price;

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

    public function hasProp(string $name): bool
    {
        foreach ($this->product_properties as $property) {
            if (array_key_exists('property_name', $property)
                && $property['property_name'] === $name
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $propertyName
     * @param string $propertyValue
     * @return bool|ProductProperties
     */
    public function getProp(string $propertyName, string $propertyValue): mixed
    {
        foreach ($this->product_properties as $property) {
            if (array_key_exists('property_name', $property)
                && $property['property_name'] === $propertyName
                && $property['property_value'] === $propertyValue
            ) {
                return (new ProductProperties())
                    ->setPropertyName($property['property_name'])
                    ->setPropertyValue($property['property_value'])
                    ->setPropertyPriceImpact((int)$property['property_price_impact'])
                ;
            }
        }

        return false;
    }

    public function getProductPropertiesMessage(): string
    {
        $output = '';
        foreach ($this->product_properties as $position => $property) {
            $output .= sprintf('Властивість %s:%s', ($position + 1), PHP_EOL);
            foreach ($property as $key => $item) {
                if (isset(self::$propertyKeyMap[$key])) {
                    $output .= sprintf('%s: %s', self::$propertyKeyMap[$key], $item) . PHP_EOL;
                }
            }
            $output .= PHP_EOL;
        }

        return $output;
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

    #[SerializedName('categories_info')]
    #[Groups([self::ADMIN_PRODUCT_VIEW_GROUP, self::PUBLIC_PRODUCT_VIEW_GROUP])]
    public function getCategoriesSelect2Info(): array
    {
        $result = [];
        foreach ($this->getProductCategory() as $key=>$productCategory)
        {
            $result[$key]['id'] = $productCategory->getCategory()->getId();
            $result[$key]['name'] = $productCategory->getCategory()->getCategoryName();
        }

        return $result;
    }

    #[SerializedName('count_purchase')]
    #[Groups([self::PUBLIC_PRODUCT_VIEW_GROUP])]
    public function getCountPurchase()
    {
        return $this->getOrders()->count();
    }

    public function getFilePath(): array
    {
        return $this->file_path;
    }

    public function setFilePath(array $file_path): Product
    {
        $this->file_path = $file_path;

        return $this;
    }

    /**
     * @param ProductProperties[] $productProperties
     * @return void
     */
    public function checkInputProp(array $productProperties): void
    {
        foreach ($productProperties as $productProperty) {
            $prop = $this->getProp(
                $productProperty->getPropertyName(),
                $productProperty->getPropertyValue()
            );
            if (!$prop) {
                throw new HttpException(
                    Response::HTTP_BAD_REQUEST,
                    sprintf('Властивість %s не існує для продутку %s',
                        $productProperty->getPropertyName(),
                        $this->getProductName(UserLanguageEnum::UA))
                );
            }

            if ($prop->getPropertyPriceImpact() != $productProperty->getPropertyPriceImpact()) {
                throw new HttpException(
                    Response::HTTP_BAD_REQUEST,
                    sprintf('Властивість %s для продутку %s має інше значення приросту ціни',
                        $productProperty->getPropertyName(),
                        $this->getProductName(UserLanguageEnum::UA))
                );
            }
        }
    }

    public function getPurchaseProduct(): Collection
    {
        return $this->purchaseProduct;
    }

    public function setPurchaseProduct(Collection $purchaseProduct): Product
    {
        $this->purchaseProduct = $purchaseProduct;

        return $this;
    }

    public function getDescription(?UserLanguageEnum $language = null): mixed
    {
        return ($language !== null && isset($this->description[$language->value]))
            ? $this->description[$language->value]
            : $this->description;
    }

    public function setDescription(mixed $description): Product
    {
        $this->description = $description;

        return $this;
    }

    public function setId(?int $id = null): Product
    {
        $this->id = $id;

        return $this;
    }

    public function makeDuplicate(): Product|static
    {
        $product = clone $this;
        $product->setId();

        $this->setOrders(new ArrayCollection());

        foreach ($this->getFiles() as $file) {
            $newFile = clone $file;
            $newFile->setProduct($product);
        }

        foreach ($this->getProductCategory() as $productCategory) {
            (new ProductCategory())
                ->setCategory($productCategory->getCategory())
                ->setProduct($product);
        }

        $this->setPurchaseProduct(new ArrayCollection());

        return $product;
    }
}
