<?php

namespace App\Controller\API;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Controller\API\Request\ProductListRequest;
use App\Controller\API\Request\Purchase\PublicPurchaseProduct;
use App\Controller\API\Request\Purchase\PurchaseProduct;
use App\Entity\CategoryRelation;
use App\Entity\Enum\RoleEnum;
use App\Entity\Product;
use App\Entity\User;
use App\Entity\UserOrder;
use App\Liqpay\LiqPay;
use App\Pagination\Paginator;
use App\Repository\CategoryRepository;
use App\Repository\FilesRepository;
use App\Repository\ProductRepository;
use App\Repository\UserOrderRepository;
use App\Service\LocalizationService;
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
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route(path: 'api/v1/product')]
class ProductController extends AbstractController
{
    public function __construct(
        private LocalizationService $localizationService,
        protected EntityManagerInterface $em,
        protected LoggerInterface $logger,
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
        FilesRepository $filesRepository,
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
        $categoryIds = $listRequest->getCategoryId();

        if (count($categoryIds) > 0) {
            $categories = $categoryRepository->getByIds($categoryIds);

            $mainCategories = [];
            $categoryCriteria = [];

            foreach ($categories as $category) {
                if ($category->getChild()->count() === 0) {
                    $mainCategories[$category->getId()] = $category;
                    $categoryCriteria[$category->getId()] = [];
                }
            }

            foreach ($categories as $category) {
                if (isset($mainCategories[$category->getId()])) {
                    continue;
                }
                foreach ($mainCategories as $mainCategory) {
                    $arrayCollection = $mainCategory->getParent();
                    $filter = $arrayCollection->filter(function (CategoryRelation $categoryRelation) use ($category) {
                        return $categoryRelation->getChild() === $category;
                    });
                    if ($filter->count()) {
                        $categoryCriteria[$mainCategory->getId()][] = $category->getId();
                    }
                }
            }

            $listRequest->setCategoryId($categoryCriteria);
        } else {
            $mainCategories = $categoryRepository->getMainCategories();
            $biggestOrder = 0;
            $topCategoryId = null;
            foreach ($mainCategories as $mainCategory) {
                if ($biggestOrder < $mainCategory->getOrderCategory()) {
                    $biggestOrder = $mainCategory->getOrderCategory();
                    $topCategoryId = $mainCategory->getId();
                }
            }
            $listRequest->setTopCategoryId($topCategoryId);
        }

        $total = $repository->nativeSqlFilterProducts(
            $this->localizationService->getLanguage(), $listRequest, true
        );
        if ($listRequest->getLimit() >= $total) {
            $offset = 0;
        } else {
            $step = (int)($total / $listRequest->getLimit());
            $offset = $listRequest->getLimit() * $listRequest->getPage();
        }

        $listRequest->setOffset($offset);

        $products = $repository->nativeSqlFilterProducts(
            $this->localizationService->getLanguage(),
            $listRequest
        );

        foreach ($products as $key => $productData) {
            $files = $filesRepository->getFileByProductId($productData['id']);
            $path = [];

            foreach ($files as $file) {
                $path[] = $defaultStorage->publicUrl($file->getPath());
            }
            $productData['file_path'] = $path;
            $product_name = json_decode($productData['product_name'], true);
            if (isset($product_name[$this->localizationService->getLanguage()->value])) {
                $productData['product_name'] = $product_name[$this->localizationService->getLanguage()->value];
            }

            $description = json_decode($productData['description'], true);
            if (isset($description[$this->localizationService->getLanguage()->value])) {
                $productData['description'] = $description[$this->localizationService->getLanguage()->value];
            }

            $description = json_decode($productData['price'], true);
            if (isset($description[$this->localizationService->getLanguage()->value])) {
                $productData['price'] = (int)$description[$this->localizationService->getLanguage()->value];
            }

            $product_properties = json_decode($productData['product_properties'], true);
            $productData['product_properties'] = [];
            foreach ($product_properties as $product_property_language) {
                foreach ($product_property_language as $lang => $prop) {
                    if (isset($product_property_language[$this->localizationService->getLanguage()->value])) {
                        $productData['product_properties'][] = $product_property_language[$this->localizationService->getLanguage()->value];
                        break;
                    }
                }
            }

            unset($productData['common_fts']);
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
                'min_price' => $repository->nativeSqlFilterProducts($this->localizationService->getLanguage(), $listRequest, false, true),
                'max_price' => $repository->nativeSqlFilterProducts($this->localizationService->getLanguage(), $listRequest, false, false, true),
                'total_min_price' => $repository->getMinPrice($this->localizationService->getLanguage()),
                'total_max_price' => $repository->getMaxPrice($this->localizationService->getLanguage())
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

        $product->setPrice($product->getPrice($this->localizationService->getLanguage()));
        $product->setProductName($product->getProductName($this->localizationService->getLanguage()));
        $product->setDescription($product->getDescription($this->localizationService->getLanguage()));
        $product->setProductProperties($product->getProductProperties($this->localizationService->getLanguage()));

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
        LocalizationService $localizationService
    ): JsonResponse
    {
        $objectHandler->entityLookup($id, Product::class, 'id');
        $product = $repository->findOneBy(['id' => $id]);

        $product->checkInputProp($purchaseProduct->getProductProperties());

        $userOrder = new UserOrder();
        $userOrder->setProductId($product);
        $userOrder->setQuantityProduct($localizationService->getLanguage(), $purchaseProduct->getQuantity());
        $userOrder->setClientUserId($user);
        $userOrder->setProductProperties($purchaseProduct->getProductPropertiesArray());

        $price = $product->getPrice($localizationService->getLanguage());
        $propExplainingTemplate = 'Назва: %s, Значення: %s, Плюс до ціни продкта: %s';
        $propExplainingSet = [];
        foreach ($purchaseProduct->getProductProperties() as $productProperty) {
            $price += $productProperty->getPropertyPriceImpact();
            $propExplainingSet[] = sprintf(
                $propExplainingTemplate,
                $productProperty->getPropertyName(),
                $productProperty->getPropertyValue(),
                $productProperty->getPropertyPriceImpact()
            );
        }

        $total_amount = $price * $purchaseProduct->getQuantity();

        $userOrder->setTotalAmount($total_amount);
        $description = sprintf('Ваше замовлення: %s: в кількості: %s одиниць',
            $product->getProductName($localizationService->getLanguage()),
            $purchaseProduct->getQuantity()
        );

        if (count($propExplainingSet)) {
            $description .= PHP_EOL . implode(PHP_EOL, $propExplainingSet);
        }

        $userOrder->setDescription($description);

        $this->em->persist($userOrder);
        $this->em->flush();

        $liqPayOrderID = sprintf('%s-%s', $userOrder->getId(), time());

        $liqpay = new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);

        if ($userOrder->getClientUserId()) {
            $phoneNumber = $userOrder->getClientUserId()->getPhone();
        } elseif ($userOrder->getPhone()) {
            $phoneNumber = $userOrder->getPhone();
        }

        $params = array(
            'action' => 'invoice_send',
            'version' => '3',
            'phone' => $phoneNumber,
            'amount' => $userOrder->getTotalAmount(),
            'currency' => $localizationService->getLanguage() === UserLanguageEnum::UA ? 'UAH' : 'USD',
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'description' => $description
        );
        $res = $liqpay->api("request", $params);
        $userOrder->setLiqPayresponse(json_encode($res));
        $userOrder->setLiqPayorderid($liqPayOrderID);
        $this->em->flush();

        $params = array(
            'action' => 'pay',
            'version' => '3',
            'amount' => $userOrder->getTotalAmount(),
            'currency' => $localizationService->getLanguage() === UserLanguageEnum::UA ? 'UAH' : 'USD',
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

    #[Route('/public/purchase/{id}', name: 'public_product_purchase_by_id', methods: [Request::METHOD_POST])]
    public function publicPurchaseProduct(
        string $id,
        ProductRepository $repository,
        ObjectHandler $objectHandler,
        #[MapRequestPayload] PublicPurchaseProduct $publicPurchaseProduct,
        LocalizationService $localizationService
    ): JsonResponse
    {
        $objectHandler->entityLookup($id, Product::class, 'id');
        $product = $repository->findOneBy(['id' => $id]);

        $product->checkInputProp($publicPurchaseProduct->getProductProperties());

        $userOrder = new UserOrder();
        $userOrder->setProductId($product);
        $userOrder->setQuantityProduct($localizationService->getLanguage(), $publicPurchaseProduct->getQuantity());
        $userOrder->setPhone($publicPurchaseProduct->getPhone());
        $userOrder->setProductProperties($publicPurchaseProduct->getProductPropertiesArray());

        $price = $product->getPrice($localizationService->getLanguage());
        $propExplainingTemplate = 'Назва: %s, Значення: %s, Плюс до ціни продкта: %s';
        $propExplainingSet = [];
        foreach ($publicPurchaseProduct->getProductProperties() as $productProperty) {
            $price += $productProperty->getPropertyPriceImpact();
            $propExplainingSet[] = sprintf(
                $propExplainingTemplate,
                $productProperty->getPropertyName(),
                $productProperty->getPropertyValue(),
                $productProperty->getPropertyPriceImpact()
            );
        }

        $total_amount = $price * $publicPurchaseProduct->getQuantity();

        $userOrder->setTotalAmount($total_amount);
        $description = sprintf('Ваше замовлення: %s: в кількості: %s одиниць',
            $product->getProductName($localizationService->getLanguage()),
            $publicPurchaseProduct->getQuantity()
        );

        if (count($propExplainingSet)) {
            $description .= PHP_EOL . implode(PHP_EOL, $propExplainingSet);
        }

        $userOrder->setDescription($description);

        $this->em->persist($userOrder);
        $this->em->flush();

        $liqPayOrderID = sprintf('%s-%s', $userOrder->getId(), time());

        $liqpay = new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);

        if ($userOrder->getClientUserId()) {
            $phoneNumber = $userOrder->getClientUserId()->getPhone();
        } elseif ($userOrder->getTelegramUserId()) {
            $phoneNumber = $userOrder->getTelegramUserId()->getPhoneNumber();
        } elseif ($userOrder->getPhone()) {
            $phoneNumber = $userOrder->getPhone();
        }

        $params = array(
            'action' => 'invoice_send',
            'version' => '3',
            'phone' => $phoneNumber,
            'amount' => $userOrder->getTotalAmount(),
            'currency' => $localizationService->getLanguage() === UserLanguageEnum::UA ? 'UAH' : 'USD',
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'description' => $description
        );
        $res = $liqpay->api("request", $params);
        $userOrder->setLiqPayresponse(json_encode($res));
        $userOrder->setLiqPayorderid($liqPayOrderID);
        $this->em->flush();

        $params = array(
            'action' => 'pay',
            'version' => '3',
            'amount' => $userOrder->getTotalAmount(),
            'currency' => $localizationService->getLanguage() === UserLanguageEnum::UA ? 'UAH' : 'USD',
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
        #[CurrentUser] User $user,
        UserOrderRepository $orderRepository,
        LocalizationService $localizationService
    ): JsonResponse {
        $userOrders = $user->getClientOrders();
        if ($user->getMerge()
            && $user->getMerge()->getTelegramUser()
            && $user->getMerge()->getTelegramUser()->getOrders()
        ) {
            $telegramUserOrder = $user->getMerge()->getTelegramUser()->getOrders();
            foreach ($telegramUserOrder as $order) {
                $userOrders->add($order);
            }
        }
        $userOrdersByPhone = $orderRepository->findBy(['phone' => $user->getPhone()]);
        if ($userOrdersByPhone) {
            foreach ($userOrdersByPhone as $order) {
                $userOrders->add($order);
            }
        }
        /** @var UserOrder $order */
        foreach ($userOrders as $order) {
            if ($order->getProductId()) {
                $order->getProductId()->setProductName($order->getProductId()->getProductName($localizationService->getLanguage()));
                $order->getProductId()->setDescription($order->getProductId()->getDescription($localizationService->getLanguage()));
                $order->getProductId()->setProductProperties($order->getProductId()->getProductProperties($localizationService->getLanguage()));
                $order->getProductId()->setPrice($order->getProductId()->getPrice($localizationService->getLanguage()));
            }

            /** @var \App\Entity\PurchaseProduct[] $purchaseProduct */
            $purchaseProduct = $order->getPurchaseProduct();
            foreach ($purchaseProduct as $pp) {
                $product = $pp->getProduct();
                $product->setProductName($product->getProductName($localizationService->getLanguage()));
                $product->setDescription($product->getDescription($localizationService->getLanguage()));
                $product->setProductProperties($product->getProductProperties($localizationService->getLanguage()));
                $product->setPrice($product->getPrice($localizationService->getLanguage()));
            }
        }

        return $this->json($userOrders, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [UserOrder::PROTECTED_ORDER_VIEW_GROUP],
        ]);
    }
}