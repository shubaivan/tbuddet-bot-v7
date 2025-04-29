<?php

namespace App\Repository;

use App\Entity\TelegramUser;
use App\Entity\UserOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserOrder>
 *
 * @method UserOrder|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserOrder|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserOrder[]    findAll()
 * @method UserOrder[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserOrderRepository extends ServiceEntityRepository
{
    use DataTablesApproachRepository;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserOrder::class);
    }

    public function getByIdFromLiqPay(int $id): ?UserOrder
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param TelegramUser $user
     * @return UserOrder[]
     */
    public function getOwnOrders(TelegramUser $user): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.telegram_user_id = :user')
            ->setParameter('user', $user)
            ->orderBy('u.created_at')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array $params
     * @param bool $count
     * @param bool $total
     * @return mixed
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getDataTablesData(
        array $params,
        bool $count = false,
        bool $total = false
    )
    {
        $parameterBag = $this->handleDataTablesRequest($params);

        $limit = $parameterBag->get('limit');
        $offset = $parameterBag->get('offset');
        $sortBy = $parameterBag->get('sort_by');
        $sortOrder = $parameterBag->get('sort_order');

        if ($count) {
            $dql = '
                SELECT COUNT(DISTINCT o)
                FROM App\Entity\UserOrder o
                LEFT JOIN o.product_id as p
                LEFT JOIN o.telegram_user_id as tu
            ';
        } else {
            $dql = '
                SELECT 
                o.id, 
                o.total_amount,              
                o.description,
                o.quantity_product,
                o.liq_pay_status,          
                o.liq_pay_order_id,
                p.id as product_info,
                GROUP_CONCAT(tu.phone_number, \' \', tu.first_name, \' \', tu.last_name, \' \', tu.username) as t_user_info,
                GROUP_CONCAT(cui.firstName, \' \', cui.lastName, \' \', cui.phone) as c_user_info,
                date_format(o.created_at, \'%Y-%m-%d %H:%i:%s\') as created_at,
                date_format(o.updated_at, \'%Y-%m-%d %H:%i:%s\') as updated_at
                FROM App\Entity\UserOrder o
                LEFT JOIN o.product_id as p
                LEFT JOIN o.telegram_user_id as tu
                LEFT JOIN o.client_user_id as cui
            ';
        }

        $bindParams = [];
        $condition = ' WHERE ';
        $conditions = [];
        if ($parameterBag->get('search') && !$total) {
            $or[] = 'ILIKE(p.product_name, :var_search) = TRUE';
            $or[] = 'ILIKE(p.price, :var_search) = TRUE';

            $or[] = 'ILIKE(o.description, :var_search) = TRUE';

            $or[] = 'ILIKE(tu.phone_number, :var_search) = TRUE';
            $or[] = 'ILIKE(tu.first_name, :var_search) = TRUE';
            $or[] = 'ILIKE(tu.last_name, :var_search) = TRUE';
            $bindParams['var_search'] = '%'.$parameterBag->get('search').'%';
            $conditions[] = '(' . implode(' OR ', $or) .')';

        }

        if (count($conditions)) {
            $conditions = array_unique($conditions);
            $dql .= $condition . implode(' AND ', $conditions);
        }

        if (!$count) {
            $dql .= '
                GROUP BY o.id';
            $sortBy = 'o.'.$sortBy;
            $dql .= '
                ORDER BY ' . $sortBy . ' ' . $sortOrder;
        }

        $query = $this->getEntityManager()
            ->createQuery($dql);
        if (!$count) {
            $query
                ->setMaxResults($limit)
                ->setFirstResult($offset);
        }

        if ($bindParams) {
            $bindParams = array_unique($bindParams);
            $query
                ->setParameters($bindParams);
        }
        if ($count) {
            $result = $query->getSingleScalarResult();
        } else {
            $result = $query->getResult();
        }

        return $result;
    }
}
