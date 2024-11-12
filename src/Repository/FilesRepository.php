<?php

namespace App\Repository;

use App\Entity\Files;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Files>
 *
 * @method Files|null find($id, $lockMode = null, $lockVersion = null)
 * @method Files|null findOneBy(array $criteria, array $orderBy = null)
 * @method Files[]    findAll()
 * @method Files[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class FilesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Files::class);
    }

    public function save($object)
    {
        $this->getPersist($object);
        $this->getEntityManager()->flush();
    }

    public function remove($object)
    {
        $this->getEntityManager()->remove($object);
        $this->getEntityManager()->flush();
    }

    public function getPersist($object): void
    {
        $this->getEntityManager()->persist($object);
    }

    /**
     * @param array $ids
     * @return Files[]
     */
    public function getByIds(array $ids): array
    {
        $queryBuilder = $this->createQueryBuilder('f');
        return $queryBuilder
            ->where($queryBuilder->expr()->in('f.id', $ids))
            ->getQuery()
            ->getResult();
    }

    /**
     * @param int $productId
     * @return array|Files[]
     */
    public function getFileByProductId(int $productId): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.product = :product')
            ->setParameter('product', $productId)
            ->getQuery()
            ->getResult();
    }
}
