<?php

namespace App\Controller\API\Request;

use Symfony\Component\Validator\Constraints as Assert;

class ProductListRequest
{
    #[Assert\Type('int')]
    #[Assert\NotBlank]
    private $page;

    #[Assert\Type('int')]
    #[Assert\NotBlank]
    private $limit;

    public function __construct()
    {
        $this->page = 1;
        $this->limit = 10;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function setPage(int $page): ProductListRequest
    {
        $this->page = $page;

        return $this;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function setLimit(int $limit): ProductListRequest
    {
        $this->limit = $limit;

        return $this;
    }
}