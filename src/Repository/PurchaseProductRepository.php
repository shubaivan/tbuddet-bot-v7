<?php

namespace App\Repository;

use App\Entity\PurchaseProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseProduct>
 *
 * @method PurchaseProduct|null find($id, $lockMode = null, $lockVersion = null)
 * @method PurchaseProduct|null findOneBy(array $criteria, array $orderBy = null)
 * @method PurchaseProduct[]    findAll()
 * @method PurchaseProduct[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PurchaseProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseProduct::class);
    }
}
