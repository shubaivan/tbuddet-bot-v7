<?php

namespace App\Entity;

use App\Repository\FilesRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: FilesRepository::class)]
class Files
{
    const ADMIN_FILES_VIEW_GROUP = 'admin_files_view_group';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups([Product::ADMIN_PRODUCT_VIEW_GROUP, self::ADMIN_FILES_VIEW_GROUP])]
    private ?int $id = null;

    #[Assert\File(maxSize: "100M")]
    #[Assert\NotBlank]
    #[ORM\Column(type: 'string')]
    #[Groups([Product::ADMIN_PRODUCT_VIEW_GROUP, self::ADMIN_FILES_VIEW_GROUP])]
    private string|UploadedFile $path;

    #[ORM\Column(type: 'string')]
    private string $extension;

    #[ORM\Column(type: 'string')]
    private string $originalName;

    #[ORM\Column(type: 'string')]
    private string $size;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'files')]
    private Product $product;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPath(): string|UploadedFile
    {
        return $this->path;
    }

    public function setPath($path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function setExtension(string $extension): Files
    {
        $this->extension = $extension;

        return $this;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): Files
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getSize(): string
    {
        return $this->size;
    }

    public function setSize(string $size): Files
    {
        $this->size = $size;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): Files
    {
        $this->description = $description;

        return $this;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): Files
    {
        $this->product = $product;

        return $this;
    }
}
