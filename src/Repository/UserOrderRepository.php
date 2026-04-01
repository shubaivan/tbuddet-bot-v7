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
     * @return UserOrder[]
     */
    public function findShippedWithTracking(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.order_status = :status')
            ->andWhere('u.nova_poshta_tracking_number IS NOT NULL')
            ->setParameter('status', 'shipped')
            ->getQuery()
            ->getResult();
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
                LEFT JOIN o.client_user_id as cui
            ';
        } else {
            $dql = '
                SELECT
                o.id,
                o.total_amount,
                o.order_status,
                o.nova_poshta_tracking_number,
                o.delivery_city,
                o.delivery_department,
                o.liq_pay_status,
                GROUP_CONCAT(tu.phone_number, \' \', tu.first_name, \' \', tu.last_name, \' \', tu.username) as t_user_info,
                GROUP_CONCAT(cui.firstName, \' \', cui.lastName, \' \', cui.phone) as c_user_info,
                date_format(o.created_at, \'%Y-%m-%d %H:%i:%s\') as created_at
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
            $or[] = 'ILIKE(JSON_GET_FIELD_AS_TEXT(p.product_name, \'en\'), :var_search) = TRUE';
            $or[] = 'ILIKE(JSON_GET_FIELD_AS_TEXT(p.product_name, \'ua\'), :var_search) = TRUE';
            $or[] = 'ILIKE(JSON_GET_FIELD_AS_TEXT(p.price, \'en\'), :var_search) = TRUE';
            $or[] = 'ILIKE(JSON_GET_FIELD_AS_TEXT(p.price, \'ua\'), :var_search) = TRUE';

            $or[] = 'ILIKE(o.description, :var_search) = TRUE';
            $or[] = 'ILIKE(o.order_status, :var_search) = TRUE';
            $or[] = 'ILIKE(o.nova_poshta_tracking_number, :var_search) = TRUE';
            $or[] = 'ILIKE(o.delivery_city, :var_search) = TRUE';
            $or[] = 'ILIKE(o.delivery_department, :var_search) = TRUE';
            $or[] = 'ILIKE(o.liq_pay_status, :var_search) = TRUE';

            $or[] = 'ILIKE(tu.phone_number, :var_search) = TRUE';
            $or[] = 'ILIKE(tu.first_name, :var_search) = TRUE';
            $or[] = 'ILIKE(tu.last_name, :var_search) = TRUE';

            $or[] = 'ILIKE(cui.firstName, :var_search) = TRUE';
            $or[] = 'ILIKE(cui.lastName, :var_search) = TRUE';
            $or[] = 'ILIKE(cui.phone, :var_search) = TRUE';

            $bindParams['var_search'] = '%'.$parameterBag->get('search').'%';
            $conditions[] = '(' . implode(' OR ', $or) .')';
        }

        // Dropdown filters (skip for total count)
        if (!$total) {
            if (!empty($params['filter_status'])) {
                $conditions[] = 'o.order_status = :filter_status';
                $bindParams['filter_status'] = $params['filter_status'];
            }
            if (!empty($params['filter_payment'])) {
                if ($params['filter_payment'] === 'pending') {
                    $conditions[] = 'o.liq_pay_status IS NULL';
                } else {
                    $conditions[] = 'o.liq_pay_status = :filter_payment';
                    $bindParams['filter_payment'] = $params['filter_payment'];
                }
            }
            if (!empty($params['filter_date_from'])) {
                $conditions[] = 'o.created_at >= :filter_date_from';
                $bindParams['filter_date_from'] = $params['filter_date_from'] . ' 00:00:00';
            }
            if (!empty($params['filter_date_to'])) {
                $conditions[] = 'o.created_at <= :filter_date_to';
                $bindParams['filter_date_to'] = $params['filter_date_to'] . ' 23:59:59';
            }
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
