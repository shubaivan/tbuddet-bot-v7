<?php

namespace App\Controller\API\Request;

class ProductListRequest
{
    private int $page;
    private int $limit;
    private array $category_id;

    public function __construct()
    {
        $this->page = 1;
        $this->limit = 10;
        $this->category_id = [];
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function setPage(int $page): ProductListRequest
    {
        $this->page = $page;

        return $this;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function setLimit(int $limit): ProductListRequest
    {
        $this->limit = $limit;

        return $this;
    }

    public function getCategoryId(): array
    {
        return $this->category_id;
    }

    public function setCategoryId(array $category_id): ProductListRequest
    {
        $this->category_id = $category_id;

        return $this;
    }
}