<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\CategoryRelatinRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: CategoryRelatinRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class CategoryRelation
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Groups([Category::ADMIN_CATEGORY_VIEW_GROUP])]
    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'parent')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    private Category $parent;

    #[Groups([Category::ADMIN_CATEGORY_VIEW_GROUP])]
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
