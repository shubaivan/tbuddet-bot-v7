<?php

namespace App\Controller\API;

use App\Controller\API\Response\CategoryThreeResponse;
use App\Repository\CategoryRepository;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route(path: 'api/v1/category')]
class CategoryController extends AbstractController
{

    public function __construct(
        protected readonly SerializerInterface $serializer,
        protected readonly FilesystemOperator $defaultStorage
    ) {}

    #[Route(name: 'public_category_list', methods: [Request::METHOD_GET])]
    public function getCategoriesThree(
        CategoryRepository $categoryRepository
    ) {
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
        $path = [];
        foreach ($category->getFiles() as $file) {
            $path[] = $this->defaultStorage->publicUrl($file->getPath());
        }

        $categoryThreeResponse = new CategoryThreeResponse($category->getCategoryName(), $category->getId());
        $categoryThreeResponse->setFilePath($path);

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