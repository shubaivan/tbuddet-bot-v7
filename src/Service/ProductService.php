<?php

namespace App\Service;

use App\Entity\Product;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Telegram\Model\CategorySet;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductService
{

    public function __construct(
        private ProductRepository $productRepository,
        private CategoryRepository $categoryRepository,
        private LocalizationService $localizationService
    ) {}

    /**
     * @return array<Product[]>
     */
    public function getProductsForBot(?int $categoryId): array
    {
        $group = [];
        $category = $this->categoryRepository->find($categoryId);
        foreach ($this->productRepository->getProducts($categoryId) as $product) {
            $group[$category->getCategoryName($this->localizationService->getLanguage())][] = $product;
        }

        return $group;
    }

    public function getProduct(int $productId): Product
    {
        return $this->productRepository->find($productId);
    }

    /**
     * @return array|CategorySet[]
     */
    public function getCategories(): array
    {
        $result = [];
        foreach ($this->categoryRepository->findAll() as $key=>$category)
        {
            $result[] = new CategorySet($this->productRepository->getTotalProductByCategory($category->getId()), $category);
        }

        return $result;
    }
}