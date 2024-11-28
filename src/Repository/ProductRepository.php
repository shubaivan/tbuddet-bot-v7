<?php

namespace App\Repository;

use App\Controller\API\Request\ProductListRequest;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 *
 * @method Product|null find($id, $lockMode = null, $lockVersion = null)
 * @method Product|null findOneBy(array $criteria, array $orderBy = null)
 * @method Product[]    findAll()
 * @method Product[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ProductRepository extends ServiceEntityRepository
{
    use DataTablesApproachRepository;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @return Product[]
     */
    public function getProducts(?int $categoryId): array
    {
        $queryBuilder = $this->createQueryBuilder('p');

        if ($categoryId) {
            $queryBuilder
                ->innerJoin('p.productCategory', 'product_category')
                ->where('product_category.category = :category ')
                ->setParameter('category', $categoryId);
        }

        return $queryBuilder
            ->orderBy('p.updated_at')
            ->getQuery()
            ->getResult();
    }

    public function nativeSqlFilterProducts(
        ProductListRequest $listRequest,
        bool $total = false,
        bool $minPrice = false,
        bool $maxPrice = false
    ) {
        $connection = $this->getEntityManager()->getConnection();

        if ($listRequest->getFullTextSearch()) {
            $handleSearchValue = $this->handleSearchValue($listRequest->getFullTextSearch());
        }

        $from = 'from product as c';
        $select = 'select';
        $where = [];
        $bind = [];
        $orderBy = '';
        if ($listRequest->getCategoryId()) {
            $from .= ' left join public.product_category pc on c.id = pc.product_id';
            $orX = [];

            foreach ($listRequest->getCategoryId() as $key => $categoryId) {
                $orX[] = ' pc.category_id = :category_' . $key;
                $bind['category_' . $key] = $categoryId;
            }

            $orC = implode(' OR ' , $orX);
            $where[] = '(' . $orC . ')';
        }

        if ($listRequest->getFullTextSearch()) {
            if (!$total && !$minPrice && !$maxPrice) {
                $select .= ' ts_rank_cd(
                   c.common_fts,
                   to_tsquery(:search)) AS rank,
                    c.*';
                $orderBy = 'order by rank desc';
            }
            $where[] = 'c.common_fts @@ to_tsquery(:search)';
            $bind['search'] = $handleSearchValue;
        } else {
            $select .= ' c.*';
        }

        if ($listRequest->getPriceFrom() && is_null($listRequest->getPriceTo())) {
            $where[] = 'c.price >= :price_from';
            $bind['price_from'] = $listRequest->getPriceFrom();
        } elseif ($listRequest->getPriceTo() && is_null($listRequest->getPriceFrom())) {
            $where[] = 'c.price <= :price_to';
            $bind['price_to'] = $listRequest->getPriceFrom();
        } elseif ($listRequest->getPriceFrom() && $listRequest->getPriceTo()) {
            $where[] = 'c.price between :price_from and :price_to';
            $bind['price_to'] = $listRequest->getPriceTo();
            $bind['price_from'] = $listRequest->getPriceFrom();
        }

        $limitOfSet = '';
        if (!$total && !$minPrice && !$maxPrice) {
            $limitOfSet = 'limit :limit offset :offset';
            $bind['limit'] = $listRequest->getLimit();
            $bind['offset'] = $listRequest->getOffset();
        }

        if ($total) {
            $select = 'select COUNT(DISTINCT c.id) as total';
            $limitOfSet = '';
            $orderBy = '';
        }

        if ($minPrice) {
            $select = 'select min(c.price) as min_price';
            $limitOfSet = '';
            $orderBy = '';
        }

        if ($maxPrice) {
            $select = 'select max(c.price) as max_price';
            $limitOfSet = '';
            $orderBy = '';
        }

        $q = sprintf('%s %s %s %s %s %s', $select
            , $from,
            (count($where) ? 'WHERE ' .implode(' AND ', $where) : ''),
            !$total && !$maxPrice && !$minPrice ? ' group by c.id ' : '',
            $orderBy,
            $limitOfSet
        );

        $result = $connection->executeQuery($q, $bind);

        return ($total || $maxPrice || $minPrice) ? $result->fetchOne() : $result->fetchAllAssociative();
    }

    public function getMinPrice()
    {
        return $this->createQueryBuilder('p')
            ->select('MIN(p.price)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getMaxPrice()
    {
        return $this->createQueryBuilder('p')
            ->select('MAX(p.price)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function filterProducts(ProductListRequest $listRequest): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('p');

        if ($listRequest->getCategoryId()) {
            $queryBuilder
                ->innerJoin('p.productCategory', 'product_category');
            $orX = $queryBuilder->expr()->orX();
            foreach ($listRequest->getCategoryId() as $key => $categoryId) {
                $orX->add('product_category.category = :category_' . $key);
                $queryBuilder->setParameter('category_'.$key, $categoryId);
            }
            $queryBuilder->andWhere($orX);
        }

        if ($listRequest->getPriceFrom() && is_null($listRequest->getPriceTo())) {
            $queryBuilder->andWhere($queryBuilder->expr()->gte('p.price', $listRequest->getPriceFrom()));
        } elseif ($listRequest->getPriceTo() && is_null($listRequest->getPriceFrom())) {
            $queryBuilder->andWhere($queryBuilder->expr()->lte('p.price', $listRequest->getPriceFrom()));
        } elseif ($listRequest->getPriceFrom() && $listRequest->getPriceTo()) {
            $queryBuilder->andWhere($queryBuilder->expr()->between('p.price', $listRequest->getPriceFrom(), $listRequest->getPriceTo()));
        }

        return $queryBuilder
            ->orderBy('p.updated_at');
    }

    public function getTotalProductByCategory(int $categoryId): int
    {
        $queryBuilder = $this->createQueryBuilder('p');

        return $queryBuilder
            ->select('COUNT(DISTINCT p.id)')
            ->innerJoin('p.productCategory', 'product_category')
            ->where('product_category.category = :category ')
            ->setParameter('category', $categoryId)
            ->getQuery()
            ->getSingleScalarResult()
        ;
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
                FROM App\Entity\Product o
                LEFT JOIN o.productCategory pc 
                LEFT JOIN pc.category category 
            ';
        } else {
            $dql = '
                SELECT 
                o.id,
                array_agg(category.category_name) as categories, 
                array_agg(f.path) as filePath,                
                o.product_name,              
                o.price,
                o.product_properties,
                date_format(o.created_at, \'%Y-%m-%d %H:%i:%s\') as created_at,
                date_format(o.updated_at, \'%Y-%m-%d %H:%i:%s\') as updated_at,
                \'edit\' as action
                FROM App\Entity\Product o
                LEFT JOIN o.files f 
                LEFT JOIN o.productCategory pc 
                LEFT JOIN pc.category category 
            ';
        }

        $bindParams = [];
        $condition = ' WHERE ';
        $conditions = [];
        if ($parameterBag->get('search') && !$total) {
            $or[] = 'ILIKE(o.product_name, :var_search) = TRUE';
            $or[] = 'ILIKE(o.price, :var_search) = TRUE';

            $bindParams['var_search'] = '%' . $parameterBag->get('search') . '%';
            $conditions[] = '(' . implode(' OR ', $or) . ')';

        }

        if ($parameterBag->has('filter_category_id') && !$total) {
            $conditions[] = '
                category.id IN (:filter_category_id)
            ';
            $bindParams['filter_category_id'] = $parameterBag->get('filter_category_id');

        }

        if (count($conditions)) {
            $conditions = array_unique($conditions);
            $dql .= $condition . implode(' AND ', $conditions);
        }

        if (!$count) {
            $dql .= '
                GROUP BY o.id';
            $sortBy = 'o.' . $sortBy;
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

    public function productQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('product');
    }

    /**
     * @param $searchField
     * @param bool $strict
     * @return string
     */
    public function handleSearchValue(
        $searchField
    ): string
    {
        $result = preg_replace('!\s+!', ' ', $searchField);
        $result = explode(' ', $result);

        return implode(':*|', $result) . ':*';
    }
}
