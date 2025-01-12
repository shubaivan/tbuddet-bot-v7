<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints\NotBlank;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class Category implements AttachmentFilesInterface
{
    use CreatedUpdatedAtAwareTrait;

    const ADMIN_CATEGORY_VIEW_GROUP = 'admin_category_view';

    public static array $dataTableFields = [
        'id',
        'parents',
        'filePath',
        'category_name',
        'order_category',
        'created_at',
        'updated_at'
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[NotBlank(message: 'Вкажіть назву')]
    #[Groups([self::ADMIN_CATEGORY_VIEW_GROUP])]
    private string $category_name;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[NotBlank(message: 'Вкажіть порядок')]
    #[Groups([self::ADMIN_CATEGORY_VIEW_GROUP])]
    private int $order_category = 0;

    #[ORM\OneToMany(targetEntity: ProductCategory::class, mappedBy: 'category', orphanRemoval: true, cascade: ["persist"])]
    private Collection $productCategory;

    #[ORM\OneToMany(targetEntity: Files::class, mappedBy: 'category', orphanRemoval: true, cascade: ["persist"])]
    #[Groups([self::ADMIN_CATEGORY_VIEW_GROUP])]
    private Collection $files;

    #[ORM\OneToMany(targetEntity: CategoryRelation::class, mappedBy: 'parent', cascade: ['persist'])]
    #[Groups([self::ADMIN_CATEGORY_VIEW_GROUP])]
    private Collection|PersistentCollection|ArrayCollection $parent;

    #[ORM\OneToMany(targetEntity: CategoryRelation::class, mappedBy: 'child', cascade: ['persist'])]
    #[Groups([self::ADMIN_CATEGORY_VIEW_GROUP])]
    private Collection|PersistentCollection|ArrayCollection $child;

    public function __construct() {
        $this->productCategory = new ArrayCollection();
        $this->files = new ArrayCollection();
        $this->parent = new ArrayCollection();
        $this->child = new ArrayCollection();
        $this->order_category = 0;
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductCategory(): Collection
    {
        return $this->productCategory;
    }

    public function setProductCategory(Collection $productCategory): self
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

    public function getCategoryName(): string
    {
        return $this->category_name;
    }

    public function setCategoryName(string $category_name): Category
    {
        $this->category_name = $category_name;

        return $this;
    }

    /**
     * @return ArrayCollection|Collection|PersistentCollection|CategoryRelation[]
     */
    public function getParent(): ArrayCollection|Collection|PersistentCollection
    {
        return $this->parent;
    }

    public function setParent(ArrayCollection|Collection|PersistentCollection $parent): Category
    {
        $this->parent = $parent;

        return $this;
    }

    public function getChild(): ArrayCollection|Collection|PersistentCollection
    {
        return $this->child;
    }

    public function setChild(ArrayCollection|Collection|PersistentCollection $child): Category
    {
        $this->child = $child;

        return $this;
    }

    public function getOrderCategory(): int
    {
        return $this->order_category;
    }

    public function setOrderCategory(int $order_category): Category
    {
        $this->order_category = $order_category;

        return $this;
    }
}
