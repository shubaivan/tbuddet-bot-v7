<?php

namespace App\Entity;

use App\Repository\CategoryRelatinRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategoryRelatinRepository::class)]
class CategoryRelation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'parent')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    private Category $parent;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'child')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    private Category $child;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParent(): Category
    {
        return $this->parent;
    }

    public function setParent(Category $parent): CategoryRelation
    {
        $this->parent = $parent;

        return $this;
    }

    public function getChild(): Category
    {
        return $this->child;
    }

    public function setChild(Category $child): CategoryRelation
    {
        $this->child = $child;

        return $this;
    }
}
