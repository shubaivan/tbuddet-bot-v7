<?php

namespace App\Controller\API\Response;

class CategoryThreeResponse
{
    private string $category_name;
    private int $category_id;
    private CategoryThreeResponse $parent;
    private array $child;
    private array $filePath = [];
    private int $order_category = 0;

    public function __construct(string $category_name, int $category_id, int $order_category)
    {
        $this->category_name = $category_name;
        $this->category_id = $category_id;
        $this->order_category = $order_category;
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

    public function getOrderCategory(): int
    {
        return $this->order_category;
    }

    public function setOrderCategory(int $order_category): CategoryThreeResponse
    {
        $this->order_category = $order_category;

        return $this;
    }
}