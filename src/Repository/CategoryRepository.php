<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Files;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * @extends ServiceEntityRepository<Category>
 *
 * @method Category|null find($id, $lockMode = null, $lockVersion = null)
 * @method Category|null findOneBy(array $criteria, array $orderBy = null)
 * @method Category[]    findAll()
 * @method Category[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CategoryRepository extends ServiceEntityRepository
{
    use DataTablesApproachRepository;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * @param array $ids
     * @return Category[]
     */
    public function getByIds(array $ids): array
    {
        $queryBuilder = $this->createQueryBuilder('f');
        return $queryBuilder
            ->where($queryBuilder->expr()->in('f.id', $ids))
            ->getQuery()
            ->getResult();
    }

    public function getMainCategories()
    {
        $dql = '
                SELECT 
                o
                FROM App\Entity\Category o
                LEFT JOIN o.child child
                LEFT JOIN child.parent parent
                WHERE parent.id IS NULL 
            ';

        $dql .= '
                GROUP BY o.id';
        $sortBy = 'o.id';
        $dql .= '
                ORDER BY ' . $sortBy . ' DESC';

        $r = $this->getEntityManager()
            ->createQuery($dql)->getResult();

        return $r;
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
                FROM App\Entity\Category o
            ';
        } else {
            $dql = '
                SELECT 
                o.id,                
                array_agg(f.path) as filePath,                
                array_agg(parent.category_name) as parents,                
                o.category_name,              
                o.order_category,              
                date_format(o.created_at, \'%Y-%m-%d %H:%i:%s\') as created_at,
                date_format(o.updated_at, \'%Y-%m-%d %H:%i:%s\') as updated_at,
                \'edit\' as action
                FROM App\Entity\Category o
                LEFT JOIN o.files f
                LEFT JOIN o.child child
                LEFT JOIN child.parent parent  
            ';
        }

        $bindParams = [];
        $condition = ' WHERE ';
        $conditions = [];
        if ($parameterBag->get('search') && !$total) {
            $or[] = 'ILIKE(o.category_name, :var_search) = TRUE';

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

        return $query;
    }

    /**
     * @param ParameterBag $parameterBag
     * @param bool $count
     * @return mixed
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getShopsForSelect2(
        ParameterBag $parameterBag,
        bool $count = false
    ) {
        if ($count) {
            $dql = '
                SELECT COUNT(c.id) as count    
            ';
        } else {
            $dql = '
                SELECT 
                c.id as id, 
                c.category_name as text
            ';
        }
        $dql .= '
            FROM App\Entity\Category c
        ';

        $bindParams = [];
        $condition = ' WHERE ';
        $conditions = [];

        if ($parameterBag->get('search')) {
            $conditions[] = '
                ILIKE(c.category_name, :search) = TRUE
            ';
            $bindParams['search'] = '%' . $parameterBag->get('search'). '%';
        }

        if (count($conditions)) {
            $conditions = array_unique($conditions);
            $dql .= $condition . implode(' AND ', $conditions);
        }

        $page = $parameterBag->get('page');
        $query = $this->getEntityManager();
        $createQuery = $query
            ->createQuery($dql);

        if ($bindParams) {
            $bindParams = array_unique($bindParams);
            $createQuery
                ->setParameters($bindParams);
        }

        if (!$count) {
            $createQuery
                ->setFirstResult($page <= 1 ? 0 : 25 * $page - 1)
                ->setMaxResults(25);
        }

        if ($count) {
            $result = $createQuery->getSingleScalarResult();
        } else {
            $result = $createQuery->getResult();
        }

        return $result;
    }
}
