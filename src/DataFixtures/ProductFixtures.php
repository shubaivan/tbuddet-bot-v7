<?php

namespace App\DataFixtures;

use App\Entity\Product;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ProductFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $product = new Product();
        $product
            ->setProductName('Кільце 1м')
            ->setPrice(100)
            ->setProductProperties(['diameter=' => 1]);
        $manager->persist($product);

        $product = new Product();
        $product
            ->setProductName('Кільце 1.5м')
            ->setPrice(200)
            ->setProductProperties(['diameter=' => 1.5]);
        $manager->persist($product);

        $product = new Product();
        $product
            ->setProductName('Кільце 2м')
            ->setPrice(300)
            ->setProductProperties(['diameter=' => 2]);
        $manager->persist($product);

        $product = new Product();
        $product
            ->setProductName('Кільце 3м')
            ->setPrice(400)
            ->setProductProperties(['diameter=' => 3]);
        $manager->persist($product);


        $manager->flush();
    }
}
