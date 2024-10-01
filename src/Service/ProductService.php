<?php

namespace App\Service;

use App\Entity\Product;
use App\Entity\TelegramUser;
use App\Repository\ProductRepository;

class ProductService
{

    public function __construct(private ProductRepository $productRepository) {}

    /**
     * @return array<Product[]>
     */
    public function getProductsForBot(): array
    {
        $group = [];
        foreach ($this->productRepository->getAllByProducts() as $product) {
            $productProperties = $product->getProductProperties();
            $hasClass = array_filter($productProperties, function (array $prop) {
                return $prop['property_name'] == 'Клас';
            });
            if ($hasClass) {
                $group[array_shift($hasClass)['property_value']][] = $product;
            } else {
                $group['Інші'][]= $product;
            }
        }

        return $group;
    }
}