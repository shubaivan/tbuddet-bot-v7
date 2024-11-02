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
        $all = [];
        foreach ($categories as $category) {
            $all[$category->getId()] = $category;
        }

        $mainCategories = $categoryRepository->getMainCategories();

        $three = [];
        foreach ($mainCategories as $category) {
            $categoryThreeResponse = $this->getThreeResponse($category);
            $three[] = $categoryThreeResponse;
        }

        return $this->json($three);
    }

    /**
     * @param \App\Entity\Category $category
     * @return CategoryThreeResponse
     */
    public function getThreeResponse(\App\Entity\Category $category): CategoryThreeResponse
    {
        $categoryThreeResponse = new CategoryThreeResponse($category->getCategoryName(), $category->getId());
        $setChild = [];
        foreach ($category->getParent() as $categoryRelation) {
            $child = $categoryRelation->getChild();
            if ($child) {
                $setChild[] = $this->getThreeResponse($child);
            }
        }
        $categoryThreeResponse->setChild($setChild);

        return $categoryThreeResponse;
    }
}