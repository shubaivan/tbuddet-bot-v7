<?php

namespace App\Controller;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Entity\Category;
use App\Entity\CategoryRelation;
use App\Entity\Enum\OrderStatusEnum;
use App\Entity\Enum\RoleEnum;
use App\Entity\Product;
use App\Entity\ProductCategory;
use App\Entity\PurchaseProduct;
use App\Entity\TelegramUser;
use App\Entity\UserOrder;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
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
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted("ROLE_MANAGER")]
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
    public function ordersDatTable(
        UserOrderRepository $repository,
        Request $request,
        ProductRepository $productRepository
    )
    {
        $dataTable = $repository
            ->getDataTablesData($request->request->all());

        foreach ($dataTable as $key => $order) {
            if (isset($order['id'])) {
                $order = $repository->findOneBy(['id' => $order['id']]);
                if ($order->getProductId()) {
                    $dataTable[$key]['product_info'] = sprintf('%s ціна за шт: %s грн',
                        $order->getProductId()->getProductName(UserLanguageEnum::UA),
                        $order->getProductId()->getPrice(UserLanguageEnum::UA)
                    );
                } elseif ($order->getPurchaseProduct()->count()) {
                    $dataTable[$key]['product_info'] = implode(';', $order->getPurchaseProduct()->map(function (PurchaseProduct $purchaseProduct) {
                        return sprintf('%s ціна за шт: %s грн',
                            $purchaseProduct->getProduct()->getProductName(UserLanguageEnum::UA),
                            $purchaseProduct->getProduct()->getPrice(UserLanguageEnum::UA)
                        );
                    })->toArray());
                }
            }
        }

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

    #[Route('/admin/orders/{id}', name: 'app_admin_order_detail', methods: [Request::METHOD_GET])]
    public function orderDetail(
        #[MapEntity(id: 'id')] UserOrder $order,
    ): Response
    {
        $liqPayResponseFormatted = null;
        if ($order->getLiqPayResponse()) {
            $decoded = json_decode($order->getLiqPayResponse(), true);
            if ($decoded) {
                $liqPayResponseFormatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                $liqPayResponseFormatted = $order->getLiqPayResponse();
            }
        }

        return $this->render('admin/order-detail.html.twig', [
            'order' => $order,
            'statuses' => OrderStatusEnum::cases(),
            'liqPayResponseFormatted' => $liqPayResponseFormatted,
        ]);
    }

    #[Route('/admin/orders/{id}/update', name: 'app_admin_order_update', methods: [Request::METHOD_POST])]
    public function orderUpdate(
        #[MapEntity(id: 'id')] UserOrder $order,
        Request $request,
        EntityManagerInterface $em,
        Nutgram $bot,
        TelegramUserRepository $telegramUserRepository,
    ): Response
    {
        $oldStatus = $order->getOrderStatus();
        $oldTracking = $order->getNovaPoshtaTrackingNumber();

        $newStatus = $request->request->get('order_status');
        $trackingNumber = $request->request->get('nova_poshta_tracking_number');

        $order->setOrderStatus($newStatus);
        $order->setNovaPoshtaTrackingNumber($trackingNumber ?: null);
        $em->flush();

        // Notify client via Telegram on status change
        $chatId = $order->getTelegramUserId()?->getChatId();
        if ($chatId && $oldStatus !== $newStatus) {
            $statusLabel = OrderStatusEnum::tryFrom($newStatus)?->label() ?? $newStatus;
            $text = match ($newStatus) {
                OrderStatusEnum::PROCESSING->value => sprintf(
                    "Ваше замовлення #%d <b>прийнято в обробку</b> ✅",
                    $order->getId()
                ),
                OrderStatusEnum::SHIPPED->value => $trackingNumber
                    ? sprintf(
                        "Ваше замовлення #%d <b>відправлено</b>! 📦\nТТН: <code>%s</code>\nВідстежити: https://novaposhta.ua/tracking/?cargo_number=%s",
                        $order->getId(), $trackingNumber, $trackingNumber
                    )
                    : sprintf("Ваше замовлення #%d <b>відправлено</b>! 📦", $order->getId()),
                OrderStatusEnum::DELIVERED->value => sprintf(
                    "Ваше замовлення #%d <b>доставлено</b>! 🎉",
                    $order->getId()
                ),
                OrderStatusEnum::CANCELLED->value => sprintf(
                    "Ваше замовлення #%d <b>скасовано</b> ❌\nЗверніться до менеджера для деталей.",
                    $order->getId()
                ),
                default => null,
            };

            if ($text) {
                try {
                    $bot->sendMessage(text: $text, chat_id: $chatId, parse_mode: ParseMode::HTML);
                } catch (\Throwable) {}
            }
        }

        // Notify client when tracking number is added/changed (even without status change)
        if ($chatId && $trackingNumber && $oldTracking !== $trackingNumber && $newStatus !== OrderStatusEnum::SHIPPED->value) {
            try {
                $bot->sendMessage(
                    text: sprintf(
                        "Оновлено ТТН для замовлення #%d: <code>%s</code>\nВідстежити: https://novaposhta.ua/tracking/?cargo_number=%s",
                        $order->getId(), $trackingNumber, $trackingNumber
                    ),
                    chat_id: $chatId,
                    parse_mode: ParseMode::HTML
                );
            } catch (\Throwable) {}
        }

        // Notify managers on status change
        if ($oldStatus !== $newStatus) {
            $managers = $telegramUserRepository->findByRole(RoleEnum::MANAGER);
            foreach ($managers as $manager) {
                try {
                    $statusLabel = OrderStatusEnum::tryFrom($newStatus)?->label() ?? $newStatus;
                    $bot->sendMessage(
                        text: sprintf(
                            "Замовлення #%d → <b>%s</b>%s",
                            $order->getId(),
                            $statusLabel,
                            $trackingNumber ? "\nТТН: <code>$trackingNumber</code>" : ''
                        ),
                        chat_id: $manager->getChatId(),
                        parse_mode: ParseMode::HTML
                    );
                } catch (\Throwable) {}
            }
        }

        $this->addFlash('notice', 'Замовлення оновлено');

        return $this->redirectToRoute('app_admin_order_detail', ['id' => $order->getId()]);
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
                $parentCategories = json_decode($product['categories'], true);
                $dataTable[$key]['categories'] = [];
                foreach ($parentCategories as $parentCategory) {
                    if (is_null($parentCategory)) {
                        continue;
                    }
                    $dataTable[$key]['categories'][] = $parentCategory[UserLanguageEnum::UA->value];
                }

                $dataTable[$key]['categories'] = array_unique($dataTable[$key]['categories']);
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
    ): JsonResponse
    {
        $params = $request->request->all();

        $context = [
            AbstractNormalizer::CALLBACKS => [
                'product_properties' => function (?array $product_properties): ?array {
                    if (!$product_properties) {
                        return $product_properties;
                    }

                    return array_values($product_properties);
                }
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

    #[Route('/admin/product/duplicate/{id}', name: 'admin-product-duplicate', options: ['expose' => true], methods: Request::METHOD_GET)]
    public function productDuplicate(
        #[MapEntity(id: 'id')] Product $product,
        EntityManagerInterface $em
    )
    {
        $duplicateProduct = $product->makeDuplicate();
        $em->persist($duplicateProduct);
        $em->flush();

        return $this->redirectToRoute('admin-product-form', ['id' => $duplicateProduct->getId()]);
    }

    #[Route('/admin/product/form/{id}', name: 'admin-product-form', defaults: ['id' => null], methods: [Request::METHOD_GET])]
    public function productForm(
        ?int $id,
        ProductRepository $productRepository,
        CategoryRepository $categoryRepository,
    ): Response
    {
        $product = $id ? $productRepository->find($id) : null;
        $categories = $categoryRepository->findAll();

        return $this->render('admin/product-form.html.twig', [
            'product' => $product,
            'categories' => $categories,
        ]);
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

    #[Route('/admin/category/form/{id}', name: 'admin-category-form', defaults: ['id' => null], methods: [Request::METHOD_GET])]
    public function categoryForm(
        ?int $id,
        CategoryRepository $categoryRepository,
    ): Response
    {
        $category = $id ? $categoryRepository->find($id) : null;

        return $this->render('admin/category-form.html.twig', [
            'category' => $category,
        ]);
    }

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
                $parentCategories = json_decode($category['parents'], true);
                $dataTable[$key]['parents'] = [];
                foreach ($parentCategories as $parentCategory) {
                    if (is_null($parentCategory)) {
                        continue;
                    }
                    $dataTable[$key]['parents'][] = $parentCategory[UserLanguageEnum::UA->value];
                }
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

        if (isset($params['order_category'])) {
            $params['order_category'] = (int) $params['order_category'];
        }

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
    ): JsonResponse
    {
        $parameterBag = new ParameterBag($request->request->all());

        /** @var array $data */
        $data = $categoryRepository->getCategoriesForSelect2($parameterBag);

        $more = $parameterBag->get('page') * 25 < $categoryRepository
                ->getCategoriesForSelect2($parameterBag, true);

        foreach ($data as $key => $category) {
            $data[$key]['text'] = $category['text'][UserLanguageEnum::UA->value];
        }

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
