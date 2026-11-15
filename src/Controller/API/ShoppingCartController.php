<?php

namespace App\Controller\API;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Controller\API\Request\Purchase\CheckoutRequest;
use App\Controller\API\Request\Purchase\ProductProperties;
use App\Controller\API\Request\Purchase\PurchaseProduct;
use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\OrderStatusEnum;
use App\Entity\Enum\PaymentMethodEnum;
use App\Entity\Enum\RoleEnum;
use App\Entity\Product;
use App\Entity\PurchaseProduct as EntityPurchaseProduct;
use App\Entity\ShoppingCart;
use App\Entity\User;
use App\Entity\UserOrder;
use App\Invoice\InvoiceNotifier;
use App\Invoice\InvoicePayer;
use App\Invoice\InvoiceRequisitesMissingException;
use App\Invoice\InvoiceService;
use App\Liqpay\LiqPay;
use App\Service\EmailService;
use App\Repository\ProductRepository;
use App\Repository\PurchaseProductRepository;
use App\Repository\UserOrderRepository;
use App\Service\Analytics\ActivityService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Service\Cart\CartTotalCalculator;
use App\Service\LocalizationService;
use App\Service\ObjectHandler;
use App\Service\Promocode\PromocodeService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\Delivery\CityDelivery;
use App\Invoice\InvoicePublicLink;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

#[Route(path: 'api/v1/cart')]
class ShoppingCartController extends AbstractController
{
    public function __construct(
        protected LoggerInterface $logger,
        private string $liqpayPublicKey,
        private string $liqpayPrivateKey,
        private string $liqpayServerUrl,
        private string $frontendUrl,
    ) {}

    #[isGranted(RoleEnum::USER->value)]
    #[Route(path: '/add/product/{id}', name: 'add_purchase_product_to_cart', methods: Request::METHOD_POST)]
    public function addProduct(
        string $id,
        ProductRepository $repository,
        #[MapRequestPayload] PurchaseProduct $inputPurchaseProduct,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        ObjectHandler $objectHandler,
        ActivityService $activity,
    ): JsonResponse
    {
        $objectHandler->entityLookup($id, Product::class, 'id');
        $product = $repository->findOneBy(['id' => $id]);
        $product->checkInputProp($inputPurchaseProduct->getProductProperties());

        $entityPurchaseProduct = new EntityPurchaseProduct();
        $entityPurchaseProduct
            ->setQuantity($inputPurchaseProduct->getQuantity())
            ->setProductProperties($inputPurchaseProduct->getProductPropertiesArray())
            ->setProduct($product);
        $em->persist($entityPurchaseProduct);

        $shoppingCart = $user->getShoppingCart();
        if (!$shoppingCart) {
            $shoppingCart = new ShoppingCart();
            $shoppingCart->setUser($user);
            $em->persist($shoppingCart);
        }

        $shoppingCart->addPurchaseProduct($entityPurchaseProduct);

        $em->flush();

        // Менеджерам — одразу: хто, що й на скільки. Сповіщення не має ламати кошик.
        try {
            $unit = (float) ($product->getPrice(UserLanguageEnum::UA) ?: 0);
            foreach ($entityPurchaseProduct->getProductProperties() as $property) {
                $unit += (float) (is_array($property) ? ($property['property_price_impact'] ?? 0) : 0);
            }
            $name = $product->getProductName(UserLanguageEnum::UA);
            $activity->addedToCart(
                trim(implode(' · ', array_filter([
                    trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? '')),
                    $user->getPhone(),
                    $user->getEmail(),
                ]))),
                is_string($name) ? $name : 'товар #'.$product->getId(),
                $entityPurchaseProduct->getQuantity(),
                $unit * $entityPurchaseProduct->getQuantity(),
                $this->generateUrl('app_admin_user_detail', ['source' => 'web', 'id' => $user->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        } catch (\Throwable) {
        }

        return $this->json($entityPurchaseProduct, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [EntityPurchaseProduct::GROUP_VIEW],
        ]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route(name: 'show_cart', methods: Request::METHOD_GET)]
    public function show(
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        FilesystemOperator $defaultStorage,
        LocalizationService $localizationService
    ): JsonResponse
    {
        $shoppingCart = $user->getShoppingCart();
        if (!$shoppingCart) {
            $shoppingCart = new ShoppingCart();
            $shoppingCart->setUser($user);
            $em->persist($shoppingCart);
            $em->flush();
        }

        foreach ($shoppingCart->getPurchaseProduct() as $up) {
            $product = $up->getProduct();
            if ($product) {
                $path = [];
                foreach ($product->getFiles() as $file) {
                    $path[] = $defaultStorage->publicUrl($file->getPath());
                }
                $product->setFilePath($path);

                $product->setProductName($product->getProductName($localizationService->getLanguage()));
                $product->setDescription($product->getDescription($localizationService->getLanguage()));
                $product->setProductProperties($product->getProductProperties($localizationService->getLanguage()));
                $product->setPrice($product->getPrice($localizationService->getLanguage()));
            }
        }

        return $this->json($shoppingCart, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [ShoppingCart::GROUP_VIEW],
        ]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route(name: 'clear_cart', methods: Request::METHOD_DELETE)]
    public function clear(
        #[CurrentUser] User $user,
        EntityManagerInterface $em
    ): JsonResponse
    {
        $shoppingCart = $user->getShoppingCart();
        if (!$shoppingCart) {
            $shoppingCart = new ShoppingCart();
            $shoppingCart->setUser($user);
            $em->persist($shoppingCart);
            $em->flush();
        }

        if (count($shoppingCart->getUnpurchasedProduct()) > 0) {
            foreach ($shoppingCart->getUnpurchasedProduct() as $product) {
                $em->remove($product);
            }
            $em->flush();
        }

        return $this->json($shoppingCart, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [ShoppingCart::GROUP_VIEW],
        ]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route(path: '/{purchase_id}', name: 'remove_purchase_product', methods: Request::METHOD_DELETE)]
    public function removePurchaseProduct(
        string $purchase_id,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        ObjectHandler $objectHandler,
        PurchaseProductRepository $repository
    ): JsonResponse
    {
        $objectHandler->entityLookup($purchase_id, EntityPurchaseProduct::class, 'id');
        $purchaseProduct = $repository->findOneBy(['id' => $purchase_id]);

        $shoppingCart = $user->getShoppingCart();
        if (!$shoppingCart) {
            $shoppingCart = new ShoppingCart();
            $shoppingCart->setUser($user);
            $em->persist($shoppingCart);
            $em->flush();
        }

        if ($purchaseProduct->getShoppingCart() !== $user->getShoppingCart()) {
            return $this->json(['error' => 'user not owner of purchase product'], Response::HTTP_BAD_REQUEST);
        }

        $em->remove($purchaseProduct);
        $em->flush();

        return $this->json($shoppingCart, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [ShoppingCart::GROUP_VIEW],
        ]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route(path: '/{purchase_id}', name: 'update_purchase_product', methods: Request::METHOD_PUT)]
    public function updatePurchaseProduct(
        string $purchase_id,
        #[MapRequestPayload] PurchaseProduct $inputPurchaseProduct,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        ObjectHandler $objectHandler,
        PurchaseProductRepository $repository
    ): JsonResponse
    {
        $objectHandler->entityLookup($purchase_id, EntityPurchaseProduct::class, 'id');
        $entityPurchaseProduct = $repository->findOneBy(['id' => $purchase_id]);
        $product = $entityPurchaseProduct->getProduct();
        $product->checkInputProp($inputPurchaseProduct->getProductProperties());

        $entityPurchaseProduct
            ->setQuantity($inputPurchaseProduct->getQuantity())
            ->setProductProperties($inputPurchaseProduct->getProductPropertiesArray());

        $em->flush();

        return $this->json($entityPurchaseProduct, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [EntityPurchaseProduct::GROUP_VIEW],
        ]);
    }

    /** Способи доставки для checkout: ціна й місто доставки по місту — лише звідси (env). */
    #[isGranted(RoleEnum::USER->value)]
    #[Route('/delivery/options', name: 'cart_delivery_options', methods: [Request::METHOD_GET])]
    public function deliveryOptions(LocalizationService $localizationService, CityDelivery $cityDelivery): JsonResponse
    {
        return $this->json(['city_delivery' => $cityDelivery->options($localizationService->getLanguage())]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route('checkout', name: 'checkout_action', methods: [Request::METHOD_POST])]
    public function checkoutAction(
        #[MapRequestPayload] CheckoutRequest $checkoutRequest,
        #[CurrentUser] User $user,
        PurchaseProductRepository $purchaseProductRepository,
        EntityManagerInterface $em,
        LocalizationService $localizationService,
        CartTotalCalculator $cartTotalCalculator,
        PromocodeService $promocodeService,
        ActivityService $activity,
        CityDelivery $cityDelivery,
        InvoiceService $invoiceService,
        InvoiceNotifier $invoiceNotifier,
        EmailService $emailService,
        InvoicePublicLink $invoiceLinks,
    ): JsonResponse
    {
        $language = $localizationService->getLanguage();
        $purchaseProductIds = $checkoutRequest->getPurchaseProductIds();

        // Pay-by-invoice is offered only once the seller requisites are filled in;
        // the FE hides the option, this guards direct API calls.
        if ($checkoutRequest->isInvoice() && !$invoiceService->isAvailable()) {
            return $this->json([
                'error' => 'invoice_unavailable',
                'error_message' => 'Оплата за рахунком тимчасово недоступна. Оберіть оплату карткою або зверніться до менеджера.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Спосіб доставки. Старий фронт його не шле — тоді НП, якщо є місто, інакше самовивіз.
        $deliveryMethod = $checkoutRequest->getDeliveryMethod()
            ?? ($checkoutRequest->getDeliveryCity() ? 'nova_poshta' : 'pickup');
        $deliveryAmount = 0;
        $deliveryAddress = null;
        if (CityDelivery::METHOD === $deliveryMethod) {
            if (!$cityDelivery->isAvailable($language)) {
                return $this->json([
                    'error' => 'delivery_unavailable',
                    'error_message' => 'Доставка по місту зараз недоступна. Оберіть Нову Пошту або самовивіз.',
                ], Response::HTTP_BAD_REQUEST);
            }
            $addressError = $cityDelivery->validateAddress($checkoutRequest->getDeliveryAddress());
            if (null !== $addressError) {
                return $this->json([
                    'error' => 'delivery_address_invalid',
                    'error_message' => $addressError,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $deliveryAddress = $cityDelivery->normalizeAddress((string) $checkoutRequest->getDeliveryAddress());
            $deliveryAmount = $cityDelivery->price();
        }

        // Subtotal uses the shared calculator so the /promocode/validate preview
        // and this final apply step always agree on the number.
        $subtotal = $cartTotalCalculator->calculate($purchaseProductIds, $language);

        // Re-validate the promocode server-side even though the FE already previewed it —
        // the cart may have changed, or the code may have been deactivated in between.
        $appliedPromocode = null;
        $discount = 0;
        if ($checkoutRequest->getPromocode() !== null && $checkoutRequest->getPromocode() !== '') {
            $validation = $promocodeService->validate(
                $checkoutRequest->getPromocode(),
                $subtotal,
                CurrencyEnum::fromUserLanguage($language),
                $user,
                null,
                null,
            );
            if (!$validation->ok) {
                return $this->json([
                    'error' => 'promocode_invalid',
                    'error_code' => $validation->error->value,
                    'error_message' => $validation->error->userMessage(),
                ], Response::HTTP_BAD_REQUEST);
            }
            $appliedPromocode = $validation->promocode;
            $discount = $validation->discount;
        }

        $userOrder = new UserOrder();
        $userOrder->setClientUserId($user);
        $userOrder->setDeliveryMethod($deliveryMethod);
        if ('nova_poshta' === $deliveryMethod) {
            $userOrder->setDeliveryCity($checkoutRequest->getDeliveryCity());
            $userOrder->setDeliveryCityRef($checkoutRequest->getDeliveryCityRef());
            $userOrder->setDeliveryDepartment($checkoutRequest->getDeliveryDepartment());
            $userOrder->setDeliveryDepartmentRef($checkoutRequest->getDeliveryDepartmentRef());
        } elseif (CityDelivery::METHOD === $deliveryMethod) {
            $userOrder->setDeliveryCity($cityDelivery->city());
            $userOrder->setDeliveryAddress($deliveryAddress);
            $userOrder->setDeliveryAmount($deliveryAmount);
        }
        $em->persist($userOrder);

        $description = '';
        $orderedPurchaseProducts = [];
        $propExplainingTemplate = 'Назва: %s, Значення: %s, Плюс до ціни продкта: %s';

        foreach ($purchaseProductIds as $purchaseProductId) {
            $purchaseProduct = $purchaseProductRepository->find($purchaseProductId);
            $purchaseProduct->setUserOrder($userOrder);
            $orderedPurchaseProducts[] = $purchaseProduct;

            $product = $purchaseProduct->getProduct();

            $productProperties = array_map(function (array $prop) {
                return (new ProductProperties())
                    ->setPropertyPriceImpact($prop['property_price_impact'])
                    ->setPropertyValue($prop['property_value'])
                    ->setPropertyName($prop['property_name']);
            }, $purchaseProduct->getProductProperties());
            $product->checkInputProp($productProperties);

            $propExplainingSet = [];
            foreach ($productProperties as $productProperty) {
                $propExplainingSet[] = sprintf(
                    $propExplainingTemplate,
                    $productProperty->getPropertyName(),
                    $productProperty->getPropertyValue(),
                    $productProperty->getPropertyPriceImpact()
                );
            }

            $description .= sprintf('Ваше замовлення: %s: в кількості: %s одиниць' . PHP_EOL,
                $product->getProductName($language),
                $purchaseProduct->getQuantity()
            );

            if (count($propExplainingSet)) {
                $description .= PHP_EOL . implode(PHP_EOL, $propExplainingSet) . PHP_EOL;
            }
        }

        if ($deliveryAmount > 0) {
            $description .= sprintf('%s%s: %s — %d грн', PHP_EOL, $cityDelivery->label(), $deliveryAddress, $deliveryAmount);
        } elseif ('nova_poshta' === $deliveryMethod && $checkoutRequest->getDeliveryCity()) {
            $description .= sprintf('%sДоставка: %s, %s',
                PHP_EOL,
                $checkoutRequest->getDeliveryCity(),
                $checkoutRequest->getDeliveryDepartment() ?? ''
            );
        }

        if ($appliedPromocode !== null) {
            // User-visible line in LiqPay description so the buyer sees exactly which
            // code applied and how much was knocked off.
            $description .= PHP_EOL . $appliedPromocode->describeDiscount($discount);
        }

        // Знижка промокоду — лише на товари; доставка окремим рядком поверх.
        $total_amount = $subtotal - $discount + $deliveryAmount;
        $userOrder->setSubtotalAmount($subtotal);
        $userOrder->setDiscountAmount($discount);
        $userOrder->setTotalAmount($total_amount);
        $userOrder->setDescription($description);
        if ($appliedPromocode !== null) {
            $userOrder->setPromocodeCodeUsed($appliedPromocode->getCode());
        }

        if ($checkoutRequest->isInvoice()) {
            $payer = InvoicePayer::fromArray($checkoutRequest->getPayer() ?? []);
            $userOrder->setPaymentMethod(PaymentMethodEnum::INVOICE->value);
            $userOrder->setOrderStatus(OrderStatusEnum::AWAITING_INVOICE_PAYMENT->value);
            $userOrder->setInvoicePayer($payer->toArray());
            $invoiceLines = $invoiceService->buildLines($orderedPurchaseProducts, UserLanguageEnum::UA);
            if ($deliveryAmount > 0) {
                $invoiceLines[] = InvoiceService::deliveryLine($cityDelivery->label().': '.$deliveryAddress, $deliveryAmount);
            }
            $userOrder->setInvoiceLines($invoiceLines);
            $invoiceService->issue($userOrder);
            if ($checkoutRequest->isRememberPayer()) {
                $user->setInvoicePayerProfile($payer->toArray());
            }
        }

        $em->flush();

        if ($appliedPromocode !== null) {
            // Records the ledger row + bumps times_used. UNIQUE constraint catches
            // accidental double-apply on retried POSTs (treated as idempotent).
            $promocodeService->redeem(
                $appliedPromocode,
                $userOrder,
                $user,
                null,
                null,
                $discount,
            );
        }

        // Сповіщення менеджерам у групу «Заявки ArtBeton» про щойно створене замовлення.
        $currencyLabel = $language === UserLanguageEnum::UA ? 'грн' : 'USD';
        $customerName = trim($user->getFirstName() . ' ' . $user->getLastName());
        if ($user->getPhone()) {
            $customerName = trim($customerName . ', ' . $user->getPhone());
        }
        $amountLine = sprintf('Сума: %s %s', $total_amount, $currencyLabel)
            .($userOrder->isInvoicePayment() ? ' · 🧾 оплата за рахунком № '.$userOrder->getInvoiceNumber() : '');
        if ($deliveryAmount > 0) {
            $amountLine .= sprintf(' (товари %s + доставка %s)', $total_amount - $deliveryAmount, $deliveryAmount)
                ."\n🚚 ".$userOrder->getDeliverySummary();
        }
        $activity->orderPlaced(
            $userOrder->getId(),
            $amountLine,
            $customerName !== '' ? $customerName : null,
            count($purchaseProductIds),
        );

        if ($userOrder->isInvoicePayment()) {
            return $this->completeInvoiceCheckout($userOrder, $invoiceService, $invoiceNotifier, $emailService, $invoiceLinks);
        }

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
        $em->flush();

        $lang = $localizationService->getLanguage() === UserLanguageEnum::UA ? 'uk' : 'en';
        $resultUrl = sprintf('%s/%s/payment-success?order=%d', $this->frontendUrl, $lang, $userOrder->getId());

        $params = array(
            'action' => 'pay',
            'version' => '3',
            'amount' => $userOrder->getTotalAmount(),
            'currency' => $localizationService->getLanguage() === UserLanguageEnum::UA ? 'UAH' : 'USD',
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'result_url' => $resultUrl,
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

    /**
     * Invoice branch of checkout: render the PDF, email it to the payer, tell the
     * managers. The order already exists — a mail/Telegram failure is reported in
     * the response and to the managers instead of failing the purchase.
     */
    private function completeInvoiceCheckout(
        UserOrder $userOrder,
        InvoiceService $invoiceService,
        InvoiceNotifier $invoiceNotifier,
        EmailService $emailService,
        InvoicePublicLink $invoiceLinks,
    ): JsonResponse {
        $sentTo = null;
        $publicUrl = $invoiceLinks->url($userOrder);
        try {
            $sentTo = $emailService->sendInvoiceEmail($userOrder, $invoiceService->renderPdf($userOrder));
        } catch (\Throwable $e) {
            $this->logger->error('Invoice email failed', ['order' => $userOrder->getId(), 'error' => $e->getMessage()]);
        }

        $invoiceNotifier->invoiceIssued(
            $userOrder,
            $this->generateUrl('app_admin_order_detail', ['id' => $userOrder->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            $sentTo !== null,
            $publicUrl,
        );

        return $this->json([
            'order' => $userOrder,
            'invoice' => [
                'number' => $userOrder->getInvoiceNumber(),
                'due_date' => $userOrder->getInvoiceDueDate()?->format('Y-m-d'),
                'email' => $userOrder->getInvoiceEmail(),
                'emailed' => $sentTo !== null,
                'public_url' => $publicUrl,
                'total' => $userOrder->getTotalAmount(),
            ],
        ], Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [UserOrder::PROTECTED_ORDER_VIEW_GROUP],
        ]);
    }

    /**
     * Whether pay-by-invoice is offered right now, plus the payer requisites the
     * customer chose to remember last time (to prefill the checkout form).
     */
    #[isGranted(RoleEnum::USER->value)]
    #[Route('/invoice/options', name: 'checkout_invoice_options', methods: [Request::METHOD_GET])]
    public function invoiceOptions(#[CurrentUser] User $user, InvoiceService $invoiceService): JsonResponse
    {
        return $this->json([
            'available' => $invoiceService->isAvailable(),
            'saved_payer' => $user->getInvoicePayerProfile(),
        ]);
    }

    /** Публічне посилання на PDF рахунку власного замовлення (для «Поділитися» в профілі / після перезавантаження). */
    #[isGranted(RoleEnum::USER->value)]
    #[Route('/order/{id}/invoice/link', name: 'order_invoice_link', methods: [Request::METHOD_GET], requirements: ['id' => '\d+'])]
    public function invoiceLink(
        string $id,
        #[CurrentUser] User $user,
        UserOrderRepository $orderRepository,
        InvoicePublicLink $invoiceLinks,
    ): JsonResponse {
        $order = $orderRepository->find((int) $id);
        if ($order === null || $order->getClientUserId()?->getId() !== $user->getId() || !$order->getInvoiceNumber()) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'number' => $order->getInvoiceNumber(),
            'public_url' => $invoiceLinks->url($order),
            'total' => $order->getTotalAmount(),
            'due_date' => $order->getInvoiceDueDate()?->format('Y-m-d'),
            'email' => $order->getInvoiceEmail(),
        ]);
    }

    /** The buyer downloads the invoice PDF of their own order. */
    #[isGranted(RoleEnum::USER->value)]
    #[Route('/order/{id}/invoice', name: 'order_invoice_pdf', methods: [Request::METHOD_GET], requirements: ['id' => '\d+'])]
    public function downloadInvoice(
        string $id,
        #[CurrentUser] User $user,
        UserOrderRepository $orderRepository,
        InvoiceService $invoiceService,
    ): Response {
        $order = $orderRepository->find((int) $id);
        if ($order === null || $order->getClientUserId()?->getId() !== $user->getId() || !$order->getInvoiceNumber()) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $pdf = $invoiceService->renderPdf($order);
        } catch (InvoiceRequisitesMissingException $e) {
            $this->logger->error('Invoice PDF blocked: seller requisites incomplete', ['order' => $order->getId()]);

            return $this->json([
                'error' => 'invoice_unavailable',
                'error_message' => 'Рахунок тимчасово недоступний. Зверніться до менеджера.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="Rahunok-%s.pdf"', $order->getInvoiceNumber()),
        ]);
    }

    /**
     * Regenerate a LiqPay payment link for an existing, not-yet-paid order so the
     * customer can retry payment (e.g. after a declined card) straight from /profile.
     * The order itself is reused — only a fresh LiqPay order_id is issued.
     */
    #[isGranted(RoleEnum::USER->value)]
    #[Route('/order/{id}/pay', name: 'order_repay', methods: [Request::METHOD_POST])]
    public function repayOrder(
        string $id,
        #[CurrentUser] User $user,
        UserOrderRepository $orderRepository,
        ObjectHandler $objectHandler,
        EntityManagerInterface $em,
        LocalizationService $localizationService,
    ): JsonResponse
    {
        $objectHandler->entityLookup($id, UserOrder::class, 'id');
        /** @var UserOrder $userOrder */
        $userOrder = $orderRepository->findOneBy(['id' => $id]);

        if (!$user->getClientOrders()->contains($userOrder)) {
            return $this->json(['error' => 'user not owner of order'], Response::HTTP_BAD_REQUEST);
        }

        if ($userOrder->getLiqPayStatus() === 'success') {
            return $this->json(['error' => 'order_already_paid'], Response::HTTP_BAD_REQUEST);
        }

        $language = $localizationService->getLanguage();
        $liqPayOrderID = sprintf('%s-%s', $userOrder->getId(), time());

        $liqpay = new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);

        $lang = $language === UserLanguageEnum::UA ? 'uk' : 'en';
        $resultUrl = sprintf('%s/%s/payment-success?order=%d', $this->frontendUrl, $lang, $userOrder->getId());

        $params = array(
            'action' => 'pay',
            'version' => '3',
            'amount' => $userOrder->getTotalAmount(),
            'currency' => $language === UserLanguageEnum::UA ? 'UAH' : 'USD',
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'result_url' => $resultUrl,
            'description' => $userOrder->getDescription(),
        );
        $cnb_form_raw = $liqpay->cnb_form_raw($params);

        $userOrder->setLiqPayOrderId($liqPayOrderID);
        $em->flush();

        $link = sprintf(
            '%s?%s&%s',
            $cnb_form_raw['url'],
            'data=' . $cnb_form_raw['data'],
            'signature=' . $cnb_form_raw['signature'],
        );

        return $this->json(['link' => $link], Response::HTTP_OK);
    }
}
