<?php

namespace App\Controller\API;

use App\Controller\API\Request\CategoryListRequest;
use App\Controller\API\Request\ProductListRequest;
use App\Controller\API\Response\CategoryThreeResponse;
use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Service\LocalizationService;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route(path: 'api/v1/category')]
class CategoryController extends AbstractController
{

    public function __construct(
        protected readonly SerializerInterface $serializer,
        protected readonly FilesystemOperator $defaultStorage,
        protected readonly LocalizationService $localizationService
    ) {}

    #[Route(name: 'public_category_list', methods: [Request::METHOD_GET])]
    public function getCategoriesThree(
        #[MapQueryString] ?CategoryListRequest $listRequest,
        CategoryRepository $categoryRepository,
        ValidatorInterface $validator
    ): JsonResponse {
        //Todo https://github.com/symfony/symfony/issues/50690
        if (is_null($listRequest)) {
            $listRequest = new ProductListRequest();

            $violations = new ConstraintViolationList();

            if (null !== $listRequest && !\count($violations)) {
                $violations->addAll($validator->validate($listRequest));
            }

            if (\count($violations)) {
                throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, implode("\n", array_map(static fn ($e) => $e->getMessage(), iterator_to_array($violations))), new ValidationFailedException($listRequest, $violations));
            }
        }

        $mainCategories = $categoryRepository->getMainCategories();

        $three = [];
        foreach ($mainCategories as $category) {
            $categoryThreeResponse = $this->getThreeResponse($category);
            $three[] = $categoryThreeResponse;
        }

        usort($three, function(CategoryThreeResponse $a, CategoryThreeResponse $b) {
            return $a->getOrderCategory() < $b->getOrderCategory();
        });

        return $this->json($three);
    }

    /**
     * @param Category $category
     * @return CategoryThreeResponse
     */
    public function getThreeResponse(Category $category): CategoryThreeResponse
    {
        $path = [];
        foreach ($category->getFiles() as $file) {
            $path[] = $this->defaultStorage->publicUrl($file->getPath());
        }

        $categoryThreeResponse = new CategoryThreeResponse(
            $category->getCategoryName($this->localizationService->getLanguage()),
            $category->getId(),
            $category->getOrderCategory()
        );
        $categoryThreeResponse->setFilePath($path);

        $setChild = [];
        foreach ($category->getParent() as $categoryRelation) {
            $child = $categoryRelation->getChild();
            if ($child) {
                $setChild[] = $this->getThreeResponse($child);
            }
        }

        usort($setChild, function(CategoryThreeResponse $a, CategoryThreeResponse $b) {
            return $a->getOrderCategory() < $b->getOrderCategory();
        });

        $categoryThreeResponse->setChild($setChild);

        return $categoryThreeResponse;
    }
}