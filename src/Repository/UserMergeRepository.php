<?php

namespace App\Repository;

use App\Entity\TelegramUser;
use App\Entity\User;
use App\Entity\UserMerge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserMerge>
 *
 * @method UserMerge|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserMerge|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserMerge[]    findAll()
 * @method UserMerge[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserMergeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserMerge::class);
    }

    public function getByUser(User $user)
    {
        return $this->createQueryBuilder('um')
            ->where('um.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
