<?php

namespace App\Pagination;

use Pagerfanta\Pagerfanta;
use Doctrine\ORM\QueryBuilder;
use Pagerfanta\Doctrine\ORM\QueryAdapter;

class Paginator
{
    public const DEFAULT_PAGE = 1;
    public const DEFAULT_LIMIT = 10;
    public const PAGE = 'page';
    public const LIMIT = 'limit';
    public const PAGINATION_URL = 'baseUrl';

    public function getPaginatedRepresentation(
        QueryBuilder $builder,
        array $params,
        ?\Closure $callback = null
    ): PaginatedRepresentation {
        $page = $this->getCurrentPage($params[self::PAGE] ?? self::DEFAULT_PAGE);
        $limit = $this->getLimit($params[self::LIMIT] ?? self::DEFAULT_LIMIT);

        $pagerfanta = $this->createPagerfanta($builder, $page, $limit);

        return $this->createRepresentation($pagerfanta, $page, $limit, $params, $callback);
    }

    private function getCurrentPage(?int $page): int
    {
        return ($page && $page > 1) ? $page : self::DEFAULT_PAGE;
    }

    private function getLimit(?int $limit): int
    {
        return ($limit && $limit > 0) ? $limit : self::DEFAULT_LIMIT;
    }

    private function createPagerfanta(QueryBuilder $builder, int $page, int $limit): Pagerfanta
    {
        return (new Pagerfanta(new QueryAdapter($builder)))
            ->setNormalizeOutOfRangePages(true)
            ->setMaxPerPage($limit)
            ->setCurrentPage($page);
    }

    private function createRepresentation(
        Pagerfanta $pagerfanta,
        int $page,
        int $limit,
        array $params,
        ?\Closure $callback = null
    ): PaginatedRepresentation {
        return (new PaginatedRepresentation())
            ->setData($this->getCurrentPageResults($pagerfanta, $callback))
            ->setLinks($pagerfanta->getNbPages(), $params[self::PAGINATION_URL] ?? null)
            ->setMeta($page, $pagerfanta->getNbPages(), $limit, $pagerfanta->getNbResults());
    }

    private function getCurrentPageResults(Pagerfanta $pagerfanta, ?\Closure $callback = null): array
    {
        $result = $pagerfanta->getCurrentPageResults();

        if ($callback) {
            $result = $callback($result);
        }

        if (is_array($result)) {
            return $result;
        }

        if ($result instanceof \ArrayIterator || method_exists($result, 'getArrayCopy')) {
            return $result->getArrayCopy();
        }

        return [];
    }
}
