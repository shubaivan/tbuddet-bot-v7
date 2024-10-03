<?php

namespace App\Telegram\Model;

use App\Entity\Category;

class CategorySet
{
    private int $totalProduct;
    private Category $category;

    /**
     * @param int $totalProduct
     * @param Category $category
     */
    public function __construct(int $totalProduct, Category $category)
    {
        $this->totalProduct = $totalProduct;
        $this->category = $category;
    }

    public function getTotalProduct(): int
    {
        return $this->totalProduct;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }


}