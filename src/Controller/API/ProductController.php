<?php

namespace App\Controller\API;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: 'api/v1/product')]
class ProductController extends AbstractController
{
    #[Route(methods: [Request::METHOD_GET])]
    public function getProducts()
    {
        return $this->json('kk');
    }
}