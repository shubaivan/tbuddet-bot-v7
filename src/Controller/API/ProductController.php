<?php

namespace App\Controller\API;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Controller\API\Request\ProductListRequest;
use App\Controller\API\Request\Purchase\PublicPurchaseProduct;
use App\Controller\API\Request\Purchase\PurchaseProduct;
use App\Entity\CategoryRelation;
use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\RoleEnum;
use App\Entity\Product;
use App\Entity\User;
use App\Entity\UserOrder;
use App\Invoice\InvoiceCheckout;
use App\Invoice\InvoiceService;
use App\Liqpay\LiqPay;
use App\Service\Payment\LiqPayStatusSync;
use App\Service\Payment\OrderOnlinePayment;
use App\Service\Payment\PaymentResultUrl;
use App\Pagination\Paginator;
use App\Repository\CategoryRepository;
use App\Repository\FilesRepository;
use App\Repository\ProductRepository;
use App\Repository\UserOrderRepository;
use App\Service\Buyer\GuestOrderLinker;
use App\Service\LocalizationService;
use App\Service\ObjectHandler;
use App\Service\Promocode\PromocodeService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\Delivery\CityDelivery;
use App\Service\Analytics\ActivityService;
use App\Service\Campaign\CampaignTracker;
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
        private readonly PaymentResultUrl $paymentResultUrl,
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

    #[Route('/view/{id}', name: 'product_view_by_id', requirements: ['id' => '\d+'], methods: [Request::METHOD_GET])]
    public function viewId(
        string $id,
        ProductRepository $repository,
        FilesystemOperator $defaultStorage,
    ): JsonResponse
    {
        // Ids beyond the integer column are unknown products too (not a 500 from Postgres).
        $product = \strlen($id) <= 10 && (int) $id <= 2147483647 ? $repository->find((int) $id) : null;
        // An unknown product (old link, removed product) is an expected answer, not an
        // error: 200 + flag keeps the browser console clean; the FE shows «not found»
        // (noindex) exactly as it did for the former 422.
        if (null === $product) {
            return $this->json(['not_found' => true], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
        }

        $path = [];
        foreach ($product->getFiles() as $file) {
            $path[] = $defaultStorage->publicUrl($file->getPath());
        }
        $product->setFilePath($path);

        // Capture the full name map before flattening — the frontend needs the
        // other language's name to build that language's SEO slug URL for the
        // hreflang pair. Keys are normalised to the locale codes used in public
        // URLs ('uk'), since the stored jsonb uses 'ua' for Ukrainian.
        $nameMap = $product->getProductName();
        $nameMap = is_array($nameMap) ? $nameMap : [];
        $product->setProductNameI18n(array_filter([
            UserLanguageEnum::UK->value => $nameMap[UserLanguageEnum::UA->value]
                ?? $nameMap[UserLanguageEnum::UK->value]
                ?? null,
            UserLanguageEnum::EN->value => $nameMap[UserLanguageEnum::EN->value] ?? null,
        ]));

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
        LocalizationService $localizationService,
        PromocodeService $promocodeService,
        CityDelivery $cityDelivery,
        ActivityService $activity,
        CampaignTracker $campaignTracker,
        InvoiceCheckout $invoiceCheckout,
    ): JsonResponse
    {
        if ($purchaseProduct->isInvoice() && !$invoiceCheckout->isAvailable()) {
            return $this->invoiceUnavailable();
        }

        $objectHandler->entityLookup($id, Product::class, 'id');
        $product = $repository->findOneBy(['id' => $id]);

        $product->checkInputProp($purchaseProduct->getProductProperties());

        // Invoices are always in hryvnia, also on /en: price, option texts and delivery in UA.
        $priceLanguage = $purchaseProduct->isInvoice() ? UserLanguageEnum::UA : $localizationService->getLanguage();

        // Ті самі правила доставки по місту, що й у кошику (CartCheckout).
        $deliveryMethod = $purchaseProduct->getDeliveryMethod() ?? 'pickup';
        if (!\in_array($deliveryMethod, CityDelivery::METHODS, true)) {
            return $this->json(['error' => 'delivery_method_invalid', 'error_message' => 'Невідомий спосіб доставки.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $deliveryAmount = 0;
        $deliveryAddress = null;
        if (CityDelivery::METHOD === $deliveryMethod) {
            if (!$cityDelivery->isAvailable($priceLanguage)) {
                return $this->json([
                    'error' => 'delivery_unavailable',
                    'error_message' => 'Доставка по місту зараз недоступна. Оберіть Нову Пошту або самовивіз.',
                ], Response::HTTP_BAD_REQUEST);
            }
            $addressError = $cityDelivery->validateAddress($purchaseProduct->getDeliveryAddress());
            if (null !== $addressError) {
                return $this->json(['error' => 'delivery_address_invalid', 'error_message' => $addressError], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $deliveryAddress = $cityDelivery->normalizeAddress((string) $purchaseProduct->getDeliveryAddress());
            // In the order's currency: 800 UAH on /uk, a fixed $20 on /en (card).
            $deliveryAmount = $cityDelivery->price($priceLanguage);
        }

        $userOrder = new UserOrder();
        $userOrder->setProductId($product);
        $userOrder->setQuantityProduct($localizationService->getLanguage(), $purchaseProduct->getQuantity());
        $userOrder->setClientUserId($user);
        $userOrder->setCurrency(CurrencyEnum::fromUserLanguage($priceLanguage)->value);
        $userOrder->setProductProperties($purchaseProduct->getProductPropertiesArray());

        // Quick-buy delivery info: only persist NP fields when method is nova_poshta.
        // Pickup orders leave city/department null which the FE renders as "Самовивіз".
        $userOrder->setDeliveryMethod($deliveryMethod);
        if (CityDelivery::METHOD === $deliveryMethod) {
            $userOrder->setDeliveryCity($cityDelivery->city());
            $userOrder->setDeliveryAddress($deliveryAddress);
            $userOrder->setDeliveryAmount($deliveryAmount);
        }
        if ($purchaseProduct->getDeliveryMethod() === 'nova_poshta') {
            $userOrder->setDeliveryCity($purchaseProduct->getDeliveryCity());
            $userOrder->setDeliveryCityRef($purchaseProduct->getDeliveryCityRef());
            $userOrder->setDeliveryDepartment($purchaseProduct->getDeliveryDepartment());
            $userOrder->setDeliveryDepartmentRef($purchaseProduct->getDeliveryDepartmentRef());
        }

        if ($purchaseProduct->getContactPhone()) {
            $userOrder->setPhone($purchaseProduct->getContactPhone());
        }

        $price = $product->getPrice($localizationService->getLanguage());
        $propExplainingTemplate = 'Назва: %s, Значення: %s, Плюс до ціни продкта: %s';
        $propExplainingSet = [];
        foreach ($purchaseProduct->getProductProperties() as $productProperty) {
            $price += $productProperty->getPropertyPriceImpact();
            $propExplainingSet[] = sprintf(
                $propExplainingTemplate,
                ...$product->optionTextIn(
                    (string) $productProperty->getPropertyName(),
                    (string) $productProperty->getPropertyValue(),
                    (int) $productProperty->getPropertyPriceImpact(),
                    $priceLanguage,
                ),
            );
        }

        // Invoice: priced in UAH (the option surcharges too) — the same number as quickBuyInvoiceLines().
        if ($purchaseProduct->isInvoice()) {
            $price = $product->unitPriceIn($purchaseProduct->getProductProperties(), UserLanguageEnum::UA);
        }

        $subtotal = (int) ($price * $purchaseProduct->getQuantity());

        // Promocode (optional). Re-validate server-side even though the FE
        // previewed it — the code may have been deactivated since. Mirrors
        // ShoppingCartController::checkoutAction.
        $appliedPromocode = null;
        $discount = 0;
        if ($purchaseProduct->getPromocode()) {
            $validation = $promocodeService->validate(
                $purchaseProduct->getPromocode(),
                $subtotal,
                CurrencyEnum::fromUserLanguage($priceLanguage),
                $user,
                null,
                null,
            );
            if (!$validation->ok) {
                return $this->json([
                    'error' => 'promocode_invalid',
                    'error_code' => $validation->error->value,
                    'error_message' => $validation->error->userMessage(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $appliedPromocode = $validation->promocode;
            $discount = $validation->discount;
        }

        // Знижка — лише на товар; доставка окремим рядком поверх.
        $total_amount = $subtotal - $discount + $deliveryAmount;

        $userOrder->setSubtotalAmount($subtotal);
        $userOrder->setDiscountAmount($discount);
        $userOrder->setTotalAmount($total_amount);
        if ($appliedPromocode !== null) {
            $userOrder->setPromocodeCodeUsed($appliedPromocode->getCode());
        }
        $description = sprintf('Ваше замовлення: %s: в кількості: %s одиниць',
            $product->getProductName($priceLanguage),
            $purchaseProduct->getQuantity()
        );

        if (count($propExplainingSet)) {
            $description .= PHP_EOL . implode(PHP_EOL, $propExplainingSet);
        }

        if ($appliedPromocode !== null) {
            $description .= PHP_EOL . $appliedPromocode->describeDiscount($discount);
        }

        if ($deliveryAmount > 0) {
            $description .= PHP_EOL . sprintf('%s: %s — %s', $cityDelivery->label(), $deliveryAddress, $cityDelivery->priceText($deliveryAmount, $priceLanguage));
        }

        $userOrder->setDescription($description);

        if ($purchaseProduct->isInvoice()) {
            $invoiceCheckout->prepare(
                $userOrder,
                $purchaseProduct->getPayer() ?? [],
                $this->quickBuyInvoiceLines($product, $purchaseProduct, $cityDelivery, $deliveryAddress, $deliveryAmount),
                $purchaseProduct->isRememberPayer() ? $user : null,
            );
        }

        $this->em->persist($userOrder);
        $this->em->flush();

        // Record the redemption now that the order has an id. The discount
        // snapshot is already on the order above, so the order stays
        // consistent even if the ledger insert is a retried duplicate.
        if ($appliedPromocode !== null) {
            $promocodeService->redeem($appliedPromocode, $userOrder, $user, null, null, $discount);
        }

        $campaignTracker->attributeOrder($userOrder, $user);

        if ($userOrder->isInvoicePayment()) {
            return $this->invoiceResponse($userOrder, $invoiceCheckout);
        }

        // Доставка по місту з «Купити зараз» — одразу в «Магазин», щоб менеджер побачив адресу ще до оплати.
        if ($deliveryAmount > 0) {
            $activity->orderPlaced(
                $userOrder->getId(),
                sprintf('Сума: %s %s (товар %s + доставка %s)', $total_amount, $userOrder->getCurrencyLabel(), $total_amount - $deliveryAmount, $deliveryAmount)
                    ."\n🚚 ".$userOrder->getDeliverySummary(),
                ActivityService::customerLabel($user),
                1,
            );
        }

        $liqPayOrderID = sprintf('%s-%s', $userOrder->getId(), time());

        $liqpay = new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);

        // Prefer phone provided in the quick-buy payload (already on the order), fall back to user.
        $phoneNumber = $userOrder->getPhone() ?: $userOrder->getClientUserId()?->getPhone();

        $params = array(
            'action' => 'invoice_send',
            'version' => '3',
            'phone' => $phoneNumber,
            'amount' => $userOrder->getTotalAmount(),
            'currency' => $userOrder->getCurrency(),
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'description' => $description,
            // The first link of a new order lives a week (the LiqPay invoice and the checkout).
            'expired_date' => OrderOnlinePayment::firstCheckoutExpiresAt(),
        );
        $res = $liqpay->api("request", $params);
        $userOrder->setLiqPayresponse(json_encode($res));
        $userOrder->setLiqPayorderid($liqPayOrderID);
        $this->em->flush();

        $params = array(
            'action' => 'pay',
            'version' => '3',
            'amount' => $userOrder->getTotalAmount(),
            'currency' => $userOrder->getCurrency(),
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            // The FE now opens LiqPay in the same tab (iOS Safari blocks the old
            // popup), so LiqPay must bring the buyer back to the status page.
            'result_url' => $this->paymentResultUrl->build(
                $localizationService->getLanguage() === UserLanguageEnum::UA ? 'uk' : 'en',
                $userOrder->getId(),
            ),
            'description' => $description,
            'expired_date' => OrderOnlinePayment::firstCheckoutExpiresAt(),
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

    /** Is «Отримати рахунок» offered right now? Public — the guest quick buy needs it too. */
    #[Route('/invoice/options', name: 'public_invoice_options', methods: [Request::METHOD_GET])]
    public function publicInvoiceOptions(InvoiceCheckout $invoiceCheckout): JsonResponse
    {
        return $this->json(['available' => $invoiceCheckout->isAvailable()]);
    }

    #[Route('/public/purchase/{id}', name: 'public_product_purchase_by_id', methods: [Request::METHOD_POST])]
    public function publicPurchaseProduct(
        string $id,
        ProductRepository $repository,
        ObjectHandler $objectHandler,
        #[MapRequestPayload] PublicPurchaseProduct $publicPurchaseProduct,
        LocalizationService $localizationService,
        CampaignTracker $campaignTracker,
        InvoiceCheckout $invoiceCheckout,
        CityDelivery $cityDelivery,
    ): JsonResponse
    {
        if ($publicPurchaseProduct->isInvoice() && !$invoiceCheckout->isAvailable()) {
            return $this->invoiceUnavailable();
        }

        $objectHandler->entityLookup($id, Product::class, 'id');
        $product = $repository->findOneBy(['id' => $id]);

        $product->checkInputProp($publicPurchaseProduct->getProductProperties());

        $userOrder = new UserOrder();
        $userOrder->setProductId($product);
        $userOrder->setQuantityProduct($localizationService->getLanguage(), $publicPurchaseProduct->getQuantity());
        $userOrder->setPhone($publicPurchaseProduct->getPhone());
        $userOrder->setProductProperties($publicPurchaseProduct->getProductPropertiesArray());

        // Invoices are always in hryvnia, also on /en: price and option texts in UA.
        $priceLanguage = $publicPurchaseProduct->isInvoice() ? UserLanguageEnum::UA : $localizationService->getLanguage();
        $userOrder->setCurrency(CurrencyEnum::fromUserLanguage($priceLanguage)->value);
        $price = $product->getPrice($localizationService->getLanguage());
        $propExplainingTemplate = 'Назва: %s, Значення: %s, Плюс до ціни продкта: %s';
        $propExplainingSet = [];
        foreach ($publicPurchaseProduct->getProductProperties() as $productProperty) {
            $price += $productProperty->getPropertyPriceImpact();
            $propExplainingSet[] = sprintf(
                $propExplainingTemplate,
                ...$product->optionTextIn(
                    (string) $productProperty->getPropertyName(),
                    (string) $productProperty->getPropertyValue(),
                    (int) $productProperty->getPropertyPriceImpact(),
                    $priceLanguage,
                ),
            );
        }

        if ($publicPurchaseProduct->isInvoice()) {
            // Invoices are always in hryvnia, also on /en (see purchaseProduct()).
            $price = $product->unitPriceIn($publicPurchaseProduct->getProductProperties(), UserLanguageEnum::UA);
        }

        $total_amount = $price * $publicPurchaseProduct->getQuantity();

        $userOrder->setTotalAmount($total_amount);
        $description = sprintf('Ваше замовлення: %s: в кількості: %s одиниць',
            $product->getProductName($priceLanguage),
            $publicPurchaseProduct->getQuantity()
        );

        if (count($propExplainingSet)) {
            $description .= PHP_EOL . implode(PHP_EOL, $propExplainingSet);
        }

        $userOrder->setDescription($description);

        if ($publicPurchaseProduct->isInvoice()) {
            // Guest: no account, so the payer requisites are not remembered.
            $invoiceCheckout->prepare(
                $userOrder,
                $publicPurchaseProduct->getPayer() ?? [],
                $this->quickBuyInvoiceLines($product, $publicPurchaseProduct, $cityDelivery, null, 0),
            );
        }

        $this->em->persist($userOrder);
        $this->em->flush();

        $campaignTracker->attributeOrder($userOrder, null);

        if ($userOrder->isInvoicePayment()) {
            return $this->invoiceResponse($userOrder, $invoiceCheckout);
        }

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
            'currency' => $userOrder->getCurrency(),
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'description' => $description,
            // The first link of a new order lives a week (the LiqPay invoice and the checkout).
            'expired_date' => OrderOnlinePayment::firstCheckoutExpiresAt(),
        );
        $res = $liqpay->api("request", $params);
        $userOrder->setLiqPayresponse(json_encode($res));
        $userOrder->setLiqPayorderid($liqPayOrderID);
        $this->em->flush();

        $params = array(
            'action' => 'pay',
            'version' => '3',
            'amount' => $userOrder->getTotalAmount(),
            'currency' => $userOrder->getCurrency(),
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            // The FE now opens LiqPay in the same tab (iOS Safari blocks the old
            // popup), so LiqPay must bring the buyer back to the status page.
            'result_url' => $this->paymentResultUrl->build(
                $localizationService->getLanguage() === UserLanguageEnum::UA ? 'uk' : 'en',
                $userOrder->getId(),
            ),
            'description' => $description,
            'expired_date' => OrderOnlinePayment::firstCheckoutExpiresAt(),
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

    private function invoiceUnavailable(): JsonResponse
    {
        return $this->json([
            'error' => 'invoice_unavailable',
            'error_message' => 'Оплата за рахунком тимчасово недоступна. Оберіть оплату карткою або зверніться до менеджера.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function invoiceResponse(UserOrder $userOrder, InvoiceCheckout $invoiceCheckout): JsonResponse
    {
        return $this->json([
            'order' => $userOrder,
            'invoice' => $invoiceCheckout->complete($userOrder),
        ], Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [UserOrder::PROTECTED_ORDER_VIEW_GROUP],
        ]);
    }

    /** Invoice lines for a one-product quick buy (same naming as the cart invoice) + the delivery line. */
    private function quickBuyInvoiceLines(
        Product $product,
        PurchaseProduct $purchaseProduct,
        CityDelivery $cityDelivery,
        ?string $deliveryAddress,
        int $deliveryAmount,
    ): array {
        // Always hryvnia, whatever the site language (options as their Ukrainian twins).
        $price = $product->unitPriceIn($purchaseProduct->getProductProperties(), UserLanguageEnum::UA);
        $options = [];
        foreach ($purchaseProduct->getProductProperties() as $property) {
            if ($property->getPropertyName() && $property->getPropertyValue()) {
                $twin = $product->optionIn($property->getPropertyName(), $property->getPropertyValue(), UserLanguageEnum::UA);
                $options[] = ($twin['property_name'] ?? $property->getPropertyName()).': '.($twin['property_value'] ?? $property->getPropertyValue());
            }
        }
        $name = (string) $product->getProductName(UserLanguageEnum::UA);
        if ($options !== []) {
            $name .= ' ('.implode(', ', $options).')';
        }
        $qty = (int) $purchaseProduct->getQuantity();
        $lines = [['name' => $name, 'unit' => 'шт.', 'qty' => $qty, 'price' => $price, 'sum' => $price * $qty]];
        if ($deliveryAmount > 0) {
            $lines[] = InvoiceService::deliveryLine($cityDelivery->label().': '.$deliveryAddress, $deliveryAmount);
        }

        return $lines;
    }

    /**
     * Payment status for the LiqPay return page without a login — a guest quick
     * buy has no account. Needs the signed key from result_url; answers only the
     * status, nothing about the buyer or the goods.
     */
    #[Route('/order-status/{id}', name: 'public_order_payment_status', requirements: ['id' => '\d+'], methods: [Request::METHOD_GET])]
    public function publicOrderPaymentStatus(int $id, Request $request, UserOrderRepository $repository, LiqPayStatusSync $statusSync): JsonResponse
    {
        if (!$this->paymentResultUrl->isValid($id, $request->query->get('k'))) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $userOrder = $repository->find($id);
        if (null === $userOrder) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        // No callback yet (a declined card often sends none): ask LiqPay, throttled.
        $statusSync->syncIfDue($userOrder);

        return $this->json([
            'id' => $userOrder->getId(),
            'liq_pay_status' => $userOrder->getLiqPayStatus(),
            // Paid by card OR marked paid by a manager (bank transfer for an invoice).
            'paid' => $userOrder->isPaid(),
            'can_pay' => OrderOnlinePayment::canPay($userOrder),
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    /**
     * «Спробувати оплатити ще раз» for a guest on the payment-result page: a fresh
     * LiqPay checkout for the SAME order (no duplicate order), authorised by the
     * same signed key as the status. Paid / in-flight / cancelled → 409.
     */
    #[Route('/order-status/{id}/pay', name: 'public_order_repay', requirements: ['id' => '\d+'], methods: [Request::METHOD_POST])]
    public function publicOrderRepay(
        int $id,
        Request $request,
        UserOrderRepository $repository,
        OrderOnlinePayment $onlinePayment,
        LocalizationService $localizationService,
        LiqPayStatusSync $statusSync,
    ): JsonResponse {
        if (!$this->paymentResultUrl->isValid($id, $request->query->get('k'))) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $userOrder = $repository->find($id);
        if (null === $userOrder) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $statusSync->beforeNewAttempt($userOrder);
        if ($userOrder->isPaid()) {
            return $this->json(['error' => 'order_already_paid'], Response::HTTP_CONFLICT);
        }
        if (!OrderOnlinePayment::canPay($userOrder)) {
            return $this->json(['error' => 'order_not_payable'], Response::HTTP_CONFLICT);
        }

        $lang = $localizationService->getLanguage() === UserLanguageEnum::UA ? 'uk' : 'en';

        return $this->json(['link' => $onlinePayment->checkoutLink($userOrder, $lang)], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
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
        LocalizationService $localizationService,
        GuestOrderLinker $guestOrderLinker,
    ): JsonResponse {
        // Guest orders of a phone / email this account has verified become its own
        // (no account is created at a guest checkout — see GuestOrderLinker).
        $guestOrderLinker->claimFor($user);
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
        // Older guest orders of a VERIFIED phone (an unverified profile phone is not
        // proof of anything — anyone could type a stranger's number).
        foreach ($guestOrderLinker->unlinkedOrdersOfVerifiedPhones($user) as $order) {
            if (!$userOrders->contains($order)) {
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