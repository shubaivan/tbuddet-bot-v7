<?php

namespace App\Repository;

use App\Entity\CategoryRelation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CategoryRelation>
 *
 * @method CategoryRelation|null find($id, $lockMode = null, $lockVersion = null)
 * @method CategoryRelation|null findOneBy(array $criteria, array $orderBy = null)
 * @method CategoryRelation[]    findAll()
 * @method CategoryRelation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CategoryRelatinRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategoryRelation::class);
    }

    //    /**
    //     * @return CategoryRelatin[] Returns an array of CategoryRelatin objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('c.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?CategoryRelatin
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
