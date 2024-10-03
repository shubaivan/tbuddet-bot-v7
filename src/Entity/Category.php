<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
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
        'filePath',
        'category_name',
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

    #[ORM\OneToMany(targetEntity: ProductCategory::class, mappedBy: 'category', orphanRemoval: true, cascade: ["persist"])]
    private Collection $productCategory;

    #[ORM\OneToMany(targetEntity: Files::class, mappedBy: 'category', orphanRemoval: true, cascade: ["persist"])]
    #[Groups([self::ADMIN_CATEGORY_VIEW_GROUP])]
    private Collection $files;

    public function __construct() {
        $this->productCategory = new ArrayCollection();
        $this->files = new ArrayCollection();
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
}