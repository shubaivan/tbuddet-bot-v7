<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\CategoryRelation;
use App\Entity\Product;
use App\Entity\ProductCategory;
use App\Entity\TelegramUser;
use App\Entity\UserOrder;
use App\Repository\CategoryRepository;
use App\Repository\FilesRepository;
use App\Repository\ProductCategoryRepository;
use App\Repository\ProductRepository;
use App\Repository\TelegramUserRepository;
use App\Repository\UserOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted("ROLE_ADMIN")]
class AdminController extends AbstractController
{
    public function __construct(
        protected readonly DenormalizerInterface $denormalizer,
        protected readonly SerializerInterface $serializer
    ) {}

    #[Route('/admin', name: 'app_admin')]
    public function index(
//        S3Client $client
    ): Response
    {
//        $result = $client->getBucketCors([
//            'Bucket' => 'bucketeer-5a7f7a9b-c93f-4b05-9e7e-835d595eacae', // REQUIRED
//        ]);

//        $result = $client->putBucketCors([
//            'Bucket' => 'bucketeer-5a7f7a9b-c93f-4b05-9e7e-835d595eacae', // REQUIRED
//            'CORSConfiguration' => [ // REQUIRED
//                'CORSRules' => [ // REQUIRED
//                    [
//                        'AllowedHeaders' => ['Authorization'],
//                        'AllowedMethods' => ['POST', 'GET', 'PUT'], // REQUIRED
//                        'AllowedOrigins' => ['*'], // REQUIRED
//                        'ExposeHeaders' => [],
//                        'MaxAgeSeconds' => 3000
//                    ],
//                ],
//            ]
//        ]);

        return $this->render('admin/index.html.twig', [
        ]);
    }

    #############
    # Telegram Users
    #############
    #[Route('/admin/users', name: 'app_admin_users')]
    public function users(EntityManagerInterface $em): Response
    {
        $fieldNames = TelegramUser::$dataTableFields;

        array_map(function ($k) use (&$dataTableColumnData) {
            $dataTableColumnData[] = ['data' => $k];
        }, $fieldNames);

        return $this->render('admin/telegram-users.html.twig', [
            'th_keys' => $fieldNames,
            'dataTableKeys' => $dataTableColumnData,
        ]);
    }

    #[Route('/admin/users/data-table', name: 'admin-users-data-table', options: ['expose' => true])]
    public function getUsersDataTable(TelegramUserRepository $repository, Request $request)
    {
        $dataTable = $repository
            ->getDataTablesData($request->request->all());

        return $this->json(
            array_merge(
                [
                    "draw" => $request->request->get('draw'),
                    "recordsTotal" => $repository
                        ->getDataTablesData($request->request->all(), true, true),
                    "recordsFiltered" => $repository
                        ->getDataTablesData($request->request->all(), true)
                ],
                ['data' => $dataTable]
            )
        );
    }

    #############
    # User Orders
    #############

    #[Route('/admin/orders', name: 'app_admin_orders')]
    public function orders(EntityManagerInterface $em): Response
    {
        $fieldNames = UserOrder::$dataTableFields;

        array_map(function ($k) use (&$dataTableColumnData) {
            $dataTableColumnData[] = ['data' => $k];
        }, $fieldNames);

        return $this->render('admin/user-orders.html.twig', [
            'th_keys' => $fieldNames,
            'dataTableKeys' => $dataTableColumnData,
        ]);
    }

    #[Route('/admin/orders/data-table', name: 'admin-orders-data-table', options: ['expose' => true])]
    public function ordersDatTable(UserOrderRepository $repository, Request $request)
    {
        $dataTable = $repository
            ->getDataTablesData($request->request->all());

        return $this->json(
            array_merge(
                [
                    "draw" => $request->request->get('draw'),
                    "recordsTotal" => $repository
                        ->getDataTablesData($request->request->all(), true, true),
                    "recordsFiltered" => $repository
                        ->getDataTablesData($request->request->all(), true)
                ],
                ['data' => $dataTable]
            )
        );
    }

    #############
    # Products
    #############

    #[Route('/admin/products', name: 'app_admin_products')]
    public function products(EntityManagerInterface $em): Response
    {
        $fieldNames = Product::$dataTableFields;
        $fieldNames[] = 'action';

        array_map(function ($k) use (&$dataTableColumnData) {
            $dataTableColumnData[] = ['data' => $k];
        }, $fieldNames);

        return $this->render('admin/products.html.twig', [
            'th_keys' => $fieldNames,
            'dataTableKeys' => $dataTableColumnData,
        ]);
    }

    #[Route('/admin/products/data-table', name: 'admin-products-data-table', options: ['expose' => true])]
    public function productsDatTable(
        FilesystemOperator $defaultStorage,
        ProductRepository $repository,
        Request $request
    ): JsonResponse
    {
        $dataTable = $repository
            ->getDataTablesData($request->request->all())->getResult();

        foreach ($dataTable as $key => $product) {
            if (isset($product['categories'])) {
                $setCategory = [];
                $categories = explode(',', $product['categories']);
                foreach ($categories as $category) {
                    $category = trim($category, '}');
                    $category = trim($category, '{');
                    if ($category == 'NULL') {
                        continue;
                    }
                    $setCategory[$category] = 1;
                }

                $dataTable[$key]['categories'] = array_keys($setCategory);
            }
        }

        foreach ($dataTable as $key => $product) {
            if (isset($product['filePath'])) {
                $filePath = [];
                $files = explode(',', $product['filePath']);
                foreach ($files as $file) {
                    $file = trim($file, '}');
                    $file = trim($file, '{');
                    if ($file == 'NULL') {
                        continue;
                    }
                    if (array_key_exists($file, $filePath)) {
                        continue;
                    }
                    #$filePath[$file] = $defaultStorage->temporaryUrl($file, (new \DateTime())->modify('+1 hour'));
                    $filePath[$file] = $defaultStorage->publicUrl($file);
                }

                $dataTable[$key]['filePath'] = array_values($filePath);
            }
        }

        return $this->json(
            array_merge(
                [
                    "draw" => $request->request->get('draw'),
                    "recordsTotal" => $repository
                        ->getDataTablesData($request->request->all(), true, true)
                        ->getSingleScalarResult(),
                    "recordsFiltered" => $repository
                        ->getDataTablesData($request->request->all(), true)
                        ->getSingleScalarResult()
                ],
                ['data' => $dataTable]
            )
        );
    }

    #[Route('/admin/products/create', name: 'admin-products-create', options: ['expose' => true])]
    public function productCreate(
        Request $request,
        ProductRepository $repository,
        CategoryRepository $categoryRepository,
        EntityManagerInterface $em,
        FilesRepository $filesRepository,
        ValidatorInterface $validator,
        ProductCategoryRepository $productCategoryRepository
    )
    {
        $params = $request->request->all();
        if (isset($params['price'])) {
            $params['price'] = (int) $params['price'];
        }
        $context = [
            AbstractNormalizer::CALLBACKS => [
                'product_properties' => function (?array $product_properties): ?array {
                    if (!$product_properties) {
                        return $product_properties;
                    }

                    return array_values($product_properties);
                },
                'price' => function (mixed $price): ?int {
                    return is_null($price) ? null : (int)$price;
                },
            ]
        ];
        if ($request->request->get('product_id')) {
            $currentProduct = $repository->find($request->request->get('product_id'));
            $context += [
                AbstractNormalizer::OBJECT_TO_POPULATE => $currentProduct,
            ];
        }

        $product = $this->denormalizer->denormalize(
            $params,
            Product::class,
            null,
            $context
        );

        $fileIds = $request->get('file_ids');
        if (is_array($fileIds) && count($fileIds)) {
            $files = $filesRepository
                ->getByIds($fileIds);
            foreach ($files as $file) {
                $file->setProduct($product);
            }
        }

        $categoryIds = $request->get('category_ids');
        if (is_array($categoryIds) && count($categoryIds)) {
            $currentProductCategories = $productCategoryRepository->findBy(['product' => $product]);
            if ($currentProductCategories) {
                foreach ($currentProductCategories as $productCategory) {
                    $em->remove($productCategory);
                }
                $em->flush();
            }

            $categories = $categoryRepository
                ->getByIds($categoryIds);
            foreach ($categories as $category) {
                $productCategory = (new ProductCategory())
                    ->setProduct($product)
                    ->setCategory($category);
                $em->persist($productCategory);
            }
        }

        $violations = new ConstraintViolationList();
        $violations->addAll($validator->validate($product));

        if (\count($violations)) {
            throw new HttpException(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                implode("\n", array_map(static fn($e) => $e->getMessage(), iterator_to_array($violations))), new ValidationFailedException($product, $violations)
            );
        }

        $em->persist($product);
        $em->flush();

        $response = $this->serializer->serialize(
            $product, 'json',
            [AbstractNormalizer::GROUPS => [
                Product::ADMIN_PRODUCT_VIEW_GROUP,
            ]]
        );

        return new JsonResponse($response, Response::HTTP_OK, [], true);
    }

    #[Route('/admin/product/duplicate/{id}', name: 'admin-product-duplicate', options: ['expose' => true], methods: Request::METHOD_POST)]
    public function productDuplicate(
        #[MapEntity(id: 'id')] Product $product,
        EntityManagerInterface $em
    )
    {
        $duplicateProduct = $product->makeDuplicate();
        $em->persist($duplicateProduct);
        $em->flush();

        $response = $this->serializer->serialize(
            $duplicateProduct, 'json',
            [AbstractNormalizer::GROUPS => [
                Product::ADMIN_PRODUCT_VIEW_GROUP,
            ]]
        );

        return new JsonResponse($response, Response::HTTP_OK, [], true);
    }

    #[Route('/admin/product/{id}', name: 'admin-product-get', options: ['expose' => true], methods: [Request::METHOD_GET])]
    public function getProduct(
        #[MapEntity(id: 'id')] Product $product,
    ): JsonResponse
    {
        $response = $this->serializer->serialize(
            $product, 'json',
            [
                AbstractNormalizer::GROUPS => [
                    Product::ADMIN_PRODUCT_VIEW_GROUP,
                ]
            ]
        );

        return new JsonResponse($response, Response::HTTP_OK, [], true);
    }

    #[Route('/admin/product/{id}', name: 'admin-product-delete', options: ['expose' => true], methods: [Request::METHOD_DELETE])]
    public function deleteProduct(
        #[MapEntity(id: 'id')] Product $product,
        EntityManagerInterface $em
    ): JsonResponse
    {
        $em->remove($product);
        $em->flush();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    #############
    # Categories
    #############

    #[Route('/admin/categories', name: 'app_admin_categories')]
    public function categories(EntityManagerInterface $em): Response
    {
        $fieldNames = Category::$dataTableFields;
        $fieldNames[] = 'action';

        array_map(function ($k) use (&$dataTableColumnData) {
            $dataTableColumnData[] = ['data' => $k];
        }, $fieldNames);

        return $this->render('admin/categories.html.twig', [
            'th_keys' => $fieldNames,
            'dataTableKeys' => $dataTableColumnData,
        ]);
    }

    #[Route('/admin/categories/data-table', name: 'admin-categories-data-table', options: ['expose' => true])]
    public function categoriesDatTable(
        FilesystemOperator $defaultStorage,
        CategoryRepository $repository,
        Request $request
    ): JsonResponse
    {
        $dataTable = $repository
            ->getDataTablesData($request->request->all())->getResult();

        foreach ($dataTable as $key => $category) {
            if (isset($category['filePath'])) {
                $filePath = [];
                $files = explode(',', $category['filePath']);
                foreach ($files as $file) {
                    $file = trim($file, '}');
                    $file = trim($file, '{');
                    if ($file == 'NULL') {
                        continue;
                    }
                    //$filePath[] = $categoryStorage->temporaryUrl($file, (new \DateTime())->modify('+1 hour'));
                    $filePath[$defaultStorage->publicUrl($file)] = 1;
                }

                $dataTable[$key]['filePath'] = array_values(array_keys($filePath));
            }

            if (isset($category['parents'])) {
                $resultParents = [];
                $parents = explode(',', $category['parents']);
                foreach ($parents as $parent) {
                    $parent = trim($parent, '}');
                    $parent = trim($parent, '{');
                    if ($parent == 'NULL') {
                        continue;
                    }
                    $resultParents[$parent] = 1;
                }

                $dataTable[$key]['parents'] = array_values(array_keys($resultParents));
            }
        }

        return $this->json(
            array_merge(
                [
                    "draw" => $request->request->get('draw'),
                    "recordsTotal" => $repository
                        ->getDataTablesData($request->request->all(), true, true)
                        ->getSingleScalarResult(),
                    "recordsFiltered" => $repository
                        ->getDataTablesData($request->request->all(), true)
                        ->getSingleScalarResult()
                ],
                ['data' => $dataTable]
            )
        );
    }

    #[Route('/admin/categories/create', name: 'admin-categories-create', options: ['expose' => true])]
    public function categoryCreate(
        Request $request,
        CategoryRepository $repository,
        EntityManagerInterface $em,
        FilesRepository $filesRepository,
        ValidatorInterface $validator
    )
    {
        $params = $request->request->all();
        $context = [];
        if ($request->request->get('category_id')) {
            $currentProduct = $repository->find($request->request->get('category_id'));
            $context += [
                AbstractNormalizer::OBJECT_TO_POPULATE => $currentProduct,
            ];
        }

        $category = $this->denormalizer->denormalize(
            $params,
            Category::class,
            null,
            $context
        );

        $fileIds = $request->get('file_ids');
        if (is_array($fileIds) && count($fileIds)) {
            $filterIds = array_filter($fileIds, function ($fileId) {
                return strlen($fileId);
            });
            $files = $filesRepository
                ->getByIds($filterIds);
            foreach ($files as $file) {
                $file->setCategory($category);
            }
        }

        if (is_array($request->get('category_ids')) && count($request->get('category_ids'))) {
            $categories = $repository
                ->getByIds($request->get('category_ids'));
            foreach ($categories as $parentCategory) {
                $childRelation = $category->getChild();

                if (is_null($childRelation)) {
                    $exists = false;
                } else {
                    $exists = $category->getChild()->exists(function ($key, CategoryRelation $element) use ($parentCategory) {
                        return $parentCategory->getId() === $element->getParent()->getId();
                    });
                }

                $categoryRelation = new CategoryRelation();
                $categoryRelation
                    ->setChild($category)
                    ->setParent($parentCategory);

                if (!$exists && !$childRelation->contains($categoryRelation)) {
                    $em->persist($categoryRelation);
                }
            }
        }

        $violations = new ConstraintViolationList();
        $violations->addAll($validator->validate($category));

        if (\count($violations)) {
            throw new HttpException(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                implode("\n", array_map(static fn($e) => $e->getMessage(), iterator_to_array($violations))), new ValidationFailedException($category, $violations)
            );
        }

        $em->persist($category);
        $em->flush();

        $response = $this->serializer->serialize(
            [
                'id' => $category->getId(),
                'category_name' => $category->getCategoryName(),
                'parents' => $category->getChild()->map(function (CategoryRelation $categoryRelation) {
                    return [
                        'id' => $categoryRelation->getParent()->getId(),
                        'name' => $categoryRelation->getParent()->getCategoryName(),
                    ];
                })
            ],
            'json'
        );

        return new JsonResponse($response, Response::HTTP_OK, [], true);
    }

    #[Route('/admin/category/{id}', name: 'admin-category-get', options: ['expose' => true], methods: [Request::METHOD_GET])]
    public function getCategory(
        #[MapEntity(id: 'id')] Category $category,
    ): JsonResponse
    {
        $response = $this->serializer->serialize(
            [
                'id' => $category->getId(),
                'category_name' => $category->getCategoryName(),
                'parents' => $category->getChild()->map(function (CategoryRelation $categoryRelation) {
                    return [
                        'id' => $categoryRelation->getParent()->getId(),
                        'name' => $categoryRelation->getParent()->getCategoryName(),
                    ];
                })
            ],
            'json'
        );

        return new JsonResponse($response, Response::HTTP_OK, [], true);
    }

    #[Route('/admin/category/{id}', name: 'admin-category-delete', options: ['expose' => true], methods: [Request::METHOD_DELETE])]
    public function deleteCategory(
        #[MapEntity(id: 'id')] Category $category,
        EntityManagerInterface $em
    ): JsonResponse
    {
        $em->remove($category);
        $em->flush();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    #[Route('/admin/category/select2', name: 'admin-category-select2', options: ['expose' => true], methods: [Request::METHOD_POST])]
    public function categorySelect2Action(
        Request $request,
        CategoryRepository $categoryRepository
    )
    {
        $parameterBag = new ParameterBag($request->request->all());
        $data = $categoryRepository->getShopsForSelect2($parameterBag);

        $more = $parameterBag->get('page') * 25 < $categoryRepository
                ->getShopsForSelect2($parameterBag, true);

        return $this->json(array_merge(
            [
                "pagination" => [
                    'more' => $more
                ],
            ],
            ['results' => $data]
        ));
    }
}
