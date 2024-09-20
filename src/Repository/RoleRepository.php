<?php

namespace App\Repository;

use App\Entity\Enum\RoleEnum;
use App\Entity\Role;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Role>
 *
 * @method Role|null find($id, $lockMode = null, $lockVersion = null)
 * @method Role|null findOneBy(array $criteria, array $orderBy = null)
 * @method Role[]    findAll()
 * @method Role[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    public function getRoleByName(RoleEnum $name): Role
    {
        $qb = $this->createQueryBuilder('role');

        return $qb
            ->where($qb->expr()->eq('role.name', ':roleName'))
            ->setParameter('roleName', $name->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(Role $role)
    {
        $this->getEntityManager()->persist($role);
        $this->getEntityManager()->flush();
    }
}
