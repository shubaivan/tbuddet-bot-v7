<?php

namespace App\Controller\API\Request;

class ProductListRequest
{
    private int $page;
    private int $limit;
    private array $category_id;
    private ?string $full_text_search;
    private ?int $price_from;
    private ?int $price_to;

    public function __construct()
    {
        $this->page = 1;
        $this->limit = 10;
        $this->category_id = [];
        $this->full_text_search = null;
        $this->price_from = null;
        $this->price_to = null;
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

    public function getFullTextSearch(): ?string
    {
        return $this->full_text_search;
    }

    public function setFullTextSearch(?string $full_text_search): ProductListRequest
    {
        $this->full_text_search = $full_text_search;

        return $this;
    }

    public function getPriceFrom(): ?int
    {
        return $this->price_from;
    }

    public function setPriceFrom(?int $price_from): ProductListRequest
    {
        $this->price_from = $price_from;

        return $this;
    }

    public function getPriceTo(): ?int
    {
        return $this->price_to;
    }

    public function setPriceTo(?int $price_to): ProductListRequest
    {
        $this->price_to = $price_to;

        return $this;
    }
}