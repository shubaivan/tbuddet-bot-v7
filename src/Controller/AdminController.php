<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\TelegramUser;
use App\Entity\UserOrder;
use App\Repository\ProductRepository;
use App\Repository\TelegramUserRepository;
use App\Repository\UserOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

class AdminController extends AbstractController
{
    public function __construct(
        protected readonly DenormalizerInterface $denormalizer,
        protected readonly SerializerInterface $serializer
    ) {}

    #[Route('/admin', name: 'app_admin')]
    public function index(EntityManagerInterface $em): Response
    {
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
    public function productsDatTable(ProductRepository $repository, Request $request)
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

    #[Route('/admin/products/create', name: 'admin-products-create', options: ['expose' => true])]
    public function productCreate(
        Request $request,
        ProductRepository $repository,
        EntityManagerInterface $em
    )
    {
        $params = $request->request->all();
        $context = [
            AbstractNormalizer::CALLBACKS => [
                'product_properties' => function (?array $product_properties): ?array {
                    if (!$product_properties) {
                        return $product_properties;
                    }

                    return array_values($product_properties);
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

        $em->persist($product);
        $em->flush();

        $response = $this->serializer->serialize(
            $product, 'json',
            [AbstractNormalizer::IGNORED_ATTRIBUTES => ['orders']]
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
            [AbstractNormalizer::IGNORED_ATTRIBUTES => ['orders']]
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
}
