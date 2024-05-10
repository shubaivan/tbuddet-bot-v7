<?php

namespace App\DataFixtures;

use App\Entity\Product;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ProductFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $product = new Product('Кільце 1м', 'Ring', 100);
        $product->setProductProperties(['diameter=' => 1]);
        $manager->persist($product);

        $product = new Product('Кільце 1.5м', 'Ring', 200);
        $product->setProductProperties(['diameter=' => 1.5]);
        $manager->persist($product);

        $product = new Product('Кільце 2м', 'Ring', 300);
        $product->setProductProperties(['diameter=' => 2]);
        $manager->persist($product);

        $product = new Product('Кільце 3м', 'Ring', 400);
        $product->setProductProperties(['diameter=' => 3]);
        $manager->persist($product);


        $manager->flush();
    }
}
