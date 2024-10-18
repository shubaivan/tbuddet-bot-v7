<?php

namespace App\Pagination;

use Symfony\Component\Serializer\Annotation\Groups;

class PaginatedRepresentation
{
    public const PAGINATION_DEFAULT = 'pagination_default';

    #[Groups([self::PAGINATION_DEFAULT])]
    private array $data;

    #[Groups([self::PAGINATION_DEFAULT])]
    private array $meta;

    #[Groups([self::PAGINATION_DEFAULT])]
    private array $links = [];

    public function getAllPaginationParams(): array
    {
        return [
            'data' => $this->data,
            'links' => $this->links,
            'meta' => $this->meta,
        ];
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getLinks(): array
    {
        return $this->links;
    }

    public function setLinks(int $lastPage, ?string $baseUrl = null): self
    {
        if ($baseUrl) {
            $this->links = [
                'first' => sprintf('%s?%s=%s', $baseUrl, Paginator::PAGE, Paginator::DEFAULT_PAGE),
                'last' => sprintf('%s?%s=%s', $baseUrl, Paginator::PAGE, $lastPage),
            ];
        }

        return $this;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function setMeta(int $currentPage, int $lastPage, int $limit, int $totalElements): self
    {
        $this->meta = [
            'current_page' => $currentPage,
            'from' => Paginator::DEFAULT_PAGE,
            'to' => $lastPage,
            'per_page' => $limit,
            'total' => $totalElements,
        ];

        return $this;
    }
}
