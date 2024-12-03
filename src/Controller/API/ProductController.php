<?php

namespace App\Controller\API;

use App\Controller\API\Request\ProductListRequest;
use App\Controller\API\Request\Purchase\PurchaseProduct;
use App\Entity\Enum\RoleEnum;
use App\Entity\Product;
use App\Entity\User;
use App\Entity\UserOrder;
use App\Liqpay\LiqPay;
use App\Pagination\Paginator;
use App\Repository\FilesRepository;
use App\Repository\ProductRepository;
use App\Repository\UserOrderRepository;
use App\Service\ObjectHandler;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

#[Route(path: 'api/v1/product')]
class ProductController extends AbstractController
{

    public function __construct(
        protected readonly SerializerInterface $serializer,
        private string $liqpayPublicKey,
        private string $liqpayPrivateKey,
        private string $liqpayServerUrl,
    ) {}

    #[Route(name: 'public_product_list', methods: [Request::METHOD_GET])]
    public function getProducts(
        #[MapQueryString] ?ProductListRequest $listRequest,
        ProductRepository $repository,
        Paginator $paginator,
        FilesystemOperator $defaultStorage,
        FilesRepository $filesRepository
    ) {
        //Todo https://github.com/symfony/symfony/issues/50690
        if (is_null($listRequest)) {
            $listRequest = new ProductListRequest();
        }

        $total = $repository->nativeSqlFilterProducts($listRequest, true);
        if ($listRequest->getLimit() >= $total) {
            $offset = 0;
        } else {
            $step = (int)($total / $listRequest->getLimit());
            $offset = $listRequest->getLimit() * $listRequest->getPage();
        }

        $listRequest->setOffset($offset);

        $products = $repository->nativeSqlFilterProducts($listRequest);
        foreach ($products as $key => $productData) {
            $files = $filesRepository->getFileByProductId($productData['id']);
            $path = [];

            foreach ($files as $file) {
                $path[] = $defaultStorage->publicUrl($file->getPath());
            }
            $productData['file_path'] = $path;
            $productData['product_properties'] = json_decode($productData['product_properties'], true);

            $products[$key] = $productData;
        }

        $parameters = [];
        if ($listRequest->getPage()) {
            $parameters['page'] = $listRequest->getPage();
        }

        if ($listRequest->getLimit()) {
            $parameters['limit'] = $listRequest->getLimit();
        }

        if ($listRequest->getCategoryId()) {
            $parameters['category_id'] = $listRequest->getCategoryId();
        }

        if ($listRequest->getFullTextSearch()) {
            $parameters['full_text_search'] = $listRequest->getFullTextSearch();
        }

        if ($listRequest->getPriceFrom()) {
            $parameters['price_from'] = $listRequest->getPriceFrom();
        }

        if ($listRequest->getPriceTo()) {
            $parameters['price_to'] = $listRequest->getPriceTo();
        }

        $r = [
            'data' => $products,
            'meta' => [
                'current_page' => $listRequest->getPage(),
                'from' => $offset,
                'to' => $offset + $listRequest->getLimit(),
                'per_page' => $listRequest->getLimit(),
                'total' => $total,
                'min_price' => $repository->nativeSqlFilterProducts($listRequest, false, true),
                'max_price' => $repository->nativeSqlFilterProducts($listRequest, false, false, true),
                'total_min_price' => $repository->getMinPrice(),
                'total_max_price' => $repository->getMaxPrice()
            ],
            'links' => [
                'first' => $this->generateUrl(
                    'public_product_list',
                    $parameters,
                    UrlGeneratorInterface::ABSOLUTE_URL
                )
            ]
        ];

        return $this->json($r);

//        $paginatedRepresentation = $paginator->getPaginatedRepresentation(
//            $repository->filterProducts($listRequest),
//            [
//                Paginator::PAGE => $listRequest->getPage(),
//                Paginator::LIMIT => $listRequest->getLimit(),
//                Paginator::PAGINATION_URL => $this->generateUrl(
//                    'public_product_list',
//                    [],
//                    UrlGeneratorInterface::ABSOLUTE_URL
//                ),
//            ],
//            function (\ArrayIterator $arrayIterator) use ($defaultStorage) {
//                while($arrayIterator->valid() )
//                {
//                    /** @var Product $product */
//                    $product = $arrayIterator->current();
//                    $path = [];
//                    foreach ($product->getFiles() as $file) {
//                        $path[] = $defaultStorage->publicUrl($file->getPath());
//                    }
//                    $product->setFilePath($path);
//                    $arrayIterator->next();
//                }
//
//                return $arrayIterator;
//            }
//        );
//
//        return new JsonResponse(
//            $this->serializer->serialize($paginatedRepresentation, JsonEncoder::FORMAT, [
//                AbstractNormalizer::GROUPS => [
//                    PaginatedRepresentation::PAGINATION_DEFAULT,
//                    Product::PUBLIC_PRODUCT_VIEW_GROUP
//                ]
//            ]),
//            Response::HTTP_OK, [], true
//        );
    }

    #[Route('/view/{id}', name: 'product_view_by_id', methods: [Request::METHOD_GET])]
    public function viewId(
        string $id,
        ProductRepository $repository,
        ObjectHandler $objectHandler,
        FilesystemOperator $defaultStorage,
    ): JsonResponse
    {
        $objectHandler->entityLookup($id, Product::class, 'id');
        $product = $repository->findOneBy(['id' => $id]);

        $path = [];
        foreach ($product->getFiles() as $file) {
            $path[] = $defaultStorage->publicUrl($file->getPath());
        }
        $product->setFilePath($path);

        return $this->json($product, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [Product::PUBLIC_PRODUCT_VIEW_GROUP],
        ]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route('/purchase/{id}', name: 'product_purchase_by_id', methods: [Request::METHOD_POST])]
    public function purchaseProduct(
        string $id,
        ProductRepository $repository,
        ObjectHandler $objectHandler,
        #[MapRequestPayload] PurchaseProduct $purchaseProduct,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ): JsonResponse
    {
        $objectHandler->entityLookup($id, Product::class, 'id');
        $product = $repository->findOneBy(['id' => $id]);

        $userOrder = new UserOrder();
        $userOrder->setProductId($product);
        $userOrder->setQuantityProduct($purchaseProduct->getQuantity());
        $userOrder->setClientUserId($user);
        $userOrder->setProductProperties($purchaseProduct->getProductPropertiesArray());

        $price = $product->getPrice();
        $propExplainingTemplate = 'Назва: %s, Значення: %s, Плюс до ціни продкта: %s';
        $propExplainingSet = [];
        foreach ($purchaseProduct->getProductProperties() as $productProperty) {
            $prop = $product->getProp(
                $productProperty->getPropertyName(),
                $productProperty->getPropertyValue()
            );
            if (!$prop) {
                throw new HttpException(Response::HTTP_BAD_REQUEST, sprintf('Властивість %s не існує для продутку %s', $productProperty->getPropertyName(), $product->getProductName()));
            }

            if ($prop->getPropertyPriceImpact() != $productProperty->getPropertyPriceImpact()) {
                throw new HttpException(Response::HTTP_BAD_REQUEST, sprintf('Властивість %s для продутку %s має інше значення приросту ціни', $productProperty->getPropertyName(), $product->getProductName()));
            }

            $price += $productProperty->getPropertyPriceImpact();
            $propExplainingSet[] = sprintf($propExplainingTemplate, $productProperty->getPropertyName(), $productProperty->getPropertyValue(), $productProperty->getPropertyPriceImpact());
        }

        $total_amount = $price * $purchaseProduct->getQuantity();

        $userOrder->setTotalAmount($total_amount);
        $description = sprintf('Ваше замовлення: %s: в кількості: %s одиниць',
            $product->getProductName(),
            $purchaseProduct->getQuantity()
        );

        if (count($propExplainingSet)) {
            $description .= PHP_EOL . implode(PHP_EOL, $propExplainingSet);
        }

        $userOrder->setDescription($description);

        $em->persist($userOrder);
        $em->flush();

        $liqPayOrderID = sprintf('%s-%s', $userOrder->getId(), time());

        $liqpay = new LiqPay($logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);

        if ($userOrder->getTelegramUserid()) {
            $phoneNumber = $userOrder->getTelegramUserid()->getPhoneNumber();
        }

        if ($userOrder->getClientUserId()) {
            $phoneNumber = $userOrder->getClientUserId()->getPhone();
        }

        $params = array(
            'action' => 'invoice_send',
            'version' => '3',
            'phone' => $phoneNumber,
            'amount' => $userOrder->getTotalAmount(),
            'currency' => 'UAH',
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'description' => $description
        );
        $res = $liqpay->api("request", $params);
        $userOrder->setLiqPayresponse(json_encode($res));
        $userOrder->setLiqPayorderid($liqPayOrderID);
        $em->flush();

        $params = array(
            'action' => 'pay',
            'version' => '3',
            'amount' => $userOrder->getTotalAmount(),
            'currency' => 'UAH',
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'description' => $description
        );
        $cnb_form_raw = $liqpay->cnb_form_raw($params);
        $link = sprintf(
            '%s?%s&%s',
            $cnb_form_raw['url'],
            'data=' . $cnb_form_raw['data'],
            'signature=' . $cnb_form_raw['signature'],
        );

        return $this->json([
            'order' => $userOrder,
            'liqpay' => $res,
            'link' => $link
        ], Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [UserOrder::PROTECTED_ORDER_VIEW_GROUP],
        ]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route('/user-order/view/{id}', name: 'user_order_view_by_id', methods: [Request::METHOD_GET])]
    public function userOrderById(
        string $id,
        UserOrderRepository $repository,
        ObjectHandler $objectHandler,
        #[CurrentUser] User $user
    ): JsonResponse
    {
        $objectHandler->entityLookup($id, UserOrder::class, 'id');
        $userOrder = $repository->findOneBy(['id' => $id]);

        if (!$user->getClientOrders()->contains($userOrder)) {
            return $this->json(['error' => 'user not owner of order'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($userOrder, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [UserOrder::PROTECTED_ORDER_VIEW_GROUP],
        ]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route('/user-orders', name: 'user_orders', methods: [Request::METHOD_GET])]
    public function userOrders(
        #[CurrentUser] User $user
    ): JsonResponse {
        return $this->json($user->getClientOrders(), Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [UserOrder::PROTECTED_ORDER_VIEW_GROUP],
        ]);
    }
}