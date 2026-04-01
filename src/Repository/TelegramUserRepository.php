<?php

namespace App\Repository;

use App\Entity\Enum\RoleEnum;
use App\Entity\TelegramUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @extends ServiceEntityRepository<TelegramUser>
 *
 * @method TelegramUser|null find($id, $lockMode = null, $lockVersion = null)
 * @method TelegramUser|null findOneBy(array $criteria, array $orderBy = null)
 * @method TelegramUser[]    findAll()
 * @method TelegramUser[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TelegramUserRepository extends ServiceEntityRepository
{
    use DataTablesApproachRepository;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramUser::class);
    }

    public function getByTelegramId(string $telegramId): ?TelegramUser
    {
        return $this->createQueryBuilder('tu')
            ->where('tu.telegram_id = :telegram_id')
            ->setParameter('telegram_id', $telegramId)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * @return TelegramUser[]
     */
    public function findByRole(RoleEnum $role): array
    {
        return $this->createQueryBuilder('tu')
            ->innerJoin('tu.userRoles', 'r')
            ->where('r.name = :role')
            ->andWhere('tu.chat_id IS NOT NULL')
            ->setParameter('role', $role->value)
            ->getQuery()
            ->getResult();
    }

    public function save(TelegramUser $telegramUser)
    {
        $this->getEntityManager()->persist($telegramUser);
        $this->getEntityManager()->flush();
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

        $hasOrdersFilter = !$total ? ($params['filter_orders'] ?? '') : '';

        if ($count) {
            if ($hasOrdersFilter === 'with_orders') {
                $dql = '
                    SELECT COUNT(DISTINCT b)
                    FROM App\Entity\TelegramUser b
                    INNER JOIN b.orders o
                ';
            } elseif ($hasOrdersFilter === 'without_orders') {
                $dql = '
                    SELECT COUNT(DISTINCT b)
                    FROM App\Entity\TelegramUser b
                    LEFT JOIN b.orders o
                ';
            } else {
                $dql = '
                    SELECT COUNT(DISTINCT b)
                    FROM App\Entity\TelegramUser b
                ';
            }
        } else {
            $dql = '
                SELECT
                b.id,
                b.phone_number,
                b.first_name,
                b.last_name,
                b.username,
                GROUP_CONCAT(\'order id:\', o.id, \'-amount:\', o.total_amount SEPARATOR \'|\') as order_info,
                date_format(b.created_at, \'%Y-%m-%d %H:%i:%s\') as start,
                date_format(b.updated_at, \'%Y-%m-%d %H:%i:%s\') as last_visit
                FROM App\Entity\TelegramUser b
                LEFT JOIN b.orders o
            ';
        }

        $bindParams = [];
        $condition = ' WHERE ';
        $conditions = [];
        if ($parameterBag->get('search') && !$total) {
            $or[] = 'ILIKE(b.username, :var_search) = TRUE';
            $or[] = 'ILIKE(b.first_name, :var_search) = TRUE';
            $or[] = 'ILIKE(b.last_name, :var_search) = TRUE';
            $or[] = 'ILIKE(b.phone_number, :var_search) = TRUE';
            $bindParams['var_search'] = '%'.$parameterBag->get('search').'%';
            $conditions[] = '(' . implode(' OR ', $or) .')';
        }

        // Date filters (skip for total count)
        if (!$total) {
            if (!empty($params['filter_reg_from'])) {
                $conditions[] = 'b.created_at >= :filter_reg_from';
                $bindParams['filter_reg_from'] = $params['filter_reg_from'] . ' 00:00:00';
            }
            if (!empty($params['filter_reg_to'])) {
                $conditions[] = 'b.created_at <= :filter_reg_to';
                $bindParams['filter_reg_to'] = $params['filter_reg_to'] . ' 23:59:59';
            }
        }

        if (count($conditions)) {
            $conditions = array_unique($conditions);
            $dql .= $condition . implode(' AND ', $conditions);
        }

        // "without orders" needs HAVING after GROUP BY
        $having = '';
        if ($hasOrdersFilter === 'without_orders' && $count) {
            $dql .= (count($conditions) ? ' AND ' : ' WHERE ') . 'o.id IS NULL';
        }

        if (!$count) {
            $dql .= '
                GROUP BY b.id';

            if ($hasOrdersFilter === 'with_orders') {
                $dql .= ' HAVING COUNT(o.id) > 0';
            } elseif ($hasOrdersFilter === 'without_orders') {
                $dql .= ' HAVING COUNT(o.id) = 0';
            }

            $sortBy = 'b.'.$sortBy;
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
