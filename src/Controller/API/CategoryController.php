<?php

namespace App\Controller\API;

use App\Controller\API\Response\CategoryThreeResponse;
use App\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route(path: 'api/v1/category')]
class CategoryController extends AbstractController
{

    public function __construct(
        protected readonly SerializerInterface $serializer
    ) {}

    #[Route(name: 'public_category_list', methods: [Request::METHOD_GET])]
    public function getCategories(
        CategoryRepository $categoryRepository
    )
    {
        $categories = $categoryRepository->findAll();
        $three = [];
        foreach ($categories as $category) {
            $categoryThreeResponse = new CategoryThreeResponse($category->getCategoryName(), $category->getId());
            $setChild = [];
            foreach ($category->getParent() as $categoryRelation) {
                $child = $categoryRelation->getChild();
                if ($child) {
                    $setChild[] = new CategoryThreeResponse($child->getCategoryName(), $child->getId());
                }
            }
            $categoryThreeResponse->setChild($setChild);
            $three[] = $categoryThreeResponse;
        }

        return $this->json($three);
    }
}