<?php

namespace App\Controller\API\Response;

class CategoryThreeResponse
{
    private string $category_name;
    private int $category_id;
    private CategoryThreeResponse $parent;
    private array $child;
    private array $filePath = [];
    /**
     * @param string $category_name
     * @param int $category_id
     */
    public function __construct(string $category_name, int $category_id)
    {
        $this->category_name = $category_name;
        $this->category_id = $category_id;
    }


    public function getCategoryName(): string
    {
        return $this->category_name;
    }

    public function setCategoryName(string $category_name): CategoryThreeResponse
    {
        $this->category_name = $category_name;

        return $this;
    }

    public function getCategoryId(): int
    {
        return $this->category_id;
    }

    public function setCategoryId(int $category_id): CategoryThreeResponse
    {
        $this->category_id = $category_id;

        return $this;
    }

    public function getChild(): array
    {
        return $this->child;
    }

    public function setChild(array $child): CategoryThreeResponse
    {
        $this->child = $child;

        return $this;
    }

    public function getParent(): CategoryThreeResponse
    {
        return $this->parent;
    }

    public function setParent(CategoryThreeResponse $parent): CategoryThreeResponse
    {
        $this->parent = $parent;

        return $this;
    }

    public function getFilePath(): array
    {
        return $this->filePath;
    }

    public function setFilePath(array $filePath): CategoryThreeResponse
    {
        $this->filePath = $filePath;

        return $this;
    }
}