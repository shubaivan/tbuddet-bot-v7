<?php

namespace App\Controller\API;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Controller\API\Request\Purchase\CheckoutRequest;
use App\Controller\API\Request\Purchase\ProductProperties;
use App\Controller\API\Request\Purchase\PurchaseProduct;
use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\OrderStatusEnum;
use App\Entity\Enum\RoleEnum;
use App\Entity\Product;
use App\Entity\PurchaseProduct as EntityPurchaseProduct;
use App\Entity\ShoppingCart;
use App\Entity\User;
use App\Entity\UserOrder;
use App\Invoice\InvoiceCheckout;
use App\Invoice\InvoiceRequisitesMissingException;
use App\Invoice\InvoiceService;
use App\Liqpay\LiqPay;
use App\Repository\ProductRepository;
use App\Repository\PurchaseProductRepository;
use App\Repository\UserOrderRepository;
use App\Service\Analytics\ActivityService;
use App\Service\Campaign\CampaignTracker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Service\Cart\CartCheckout;
use App\Service\Cart\CheckoutRejected;
use App\Service\LocalizationService;
use App\Service\ObjectHandler;
use App\Service\Promocode\PromocodeService;
use App\Service\Payment\LiqPayStatusSync;
use App\Service\Payment\OrderOnlinePayment;
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
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use App\Service\Delivery\CityDelivery;
use App\Invoice\InvoicePublicLink;
use App\Service\Payment\PaymentResultUrl;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

#[Route(path: 'api/v1/cart')]
class ShoppingCartController extends AbstractController
{
    public function __construct(
        protected LoggerInterface $logger,
        private string $liqpayPublicKey,
        private string $liqpayPrivateKey,
        private string $liqpayServerUrl,
        private readonly PaymentResultUrl $paymentResultUrl,
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
        CampaignTracker $campaignTracker,
    ): JsonResponse
    {
        $objectHandler->entityLookup($id, Product::class, 'id');
        $product = $repository->findOneBy(['id' => $id]);
        $product->checkInputProp($inputPurchaseProduct->getProductProperties());
        if (!is_numeric($inputPurchaseProduct->getQuantity()) || (int) $inputPurchaseProduct->getQuantity() < 1) {
            return $this->json(['error' => 'invalid_quantity'], Response::HTTP_BAD_REQUEST);
        }

        $shoppingCart = $user->getShoppingCart();
        if (!$shoppingCart) {
            $shoppingCart = new ShoppingCart();
            $shoppingCart->setUser($user);
            $em->persist($shoppingCart);
        }

        // Same product with the same options already in the cart → one line, summed quantity
        // (the options are re-saved so a changed option price is taken from this request).
        $properties = $inputPurchaseProduct->getProductPropertiesArray();
        $sameLine = $shoppingCart->findSameUnpurchasedLine($product, $properties);
        if (null !== $sameLine) {
            $sameLine
                ->setQuantity((int) $sameLine->getQuantity() + (int) $inputPurchaseProduct->getQuantity())
                ->setProductProperties($properties);
        } else {
            $entityPurchaseProduct = new EntityPurchaseProduct();
            $entityPurchaseProduct
                ->setQuantity($inputPurchaseProduct->getQuantity())
                ->setProductProperties($properties)
                ->setProduct($product);
            $em->persist($entityPurchaseProduct);
            $shoppingCart->addPurchaseProduct($entityPurchaseProduct);
        }

        $em->flush();

        $campaignTracker->addedToCart($user);

        // Менеджерам — одразу: хто, що й на скільки. Сповіщення не має ламати кошик.
        try {
            $unit = (float) ($product->getPrice(UserLanguageEnum::UA) ?: 0);
            foreach ($properties as $property) {
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
                (int) $inputPurchaseProduct->getQuantity(),
                $unit * (int) $inputPurchaseProduct->getQuantity(),
                $this->generateUrl('app_admin_user_detail', ['source' => 'web', 'id' => $user->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        } catch (\Throwable) {
        }

        return $this->json($sameLine ?? $entityPurchaseProduct, Response::HTTP_OK, [], [
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
        LocalizationService $localizationService,
        CartCheckout $cartCheckout,
    ): JsonResponse
    {
        // The saved cart rows (existence checked by MatchId on the request).
        $lines = [];
        foreach ($checkoutRequest->getPurchaseProductIds() as $purchaseProductId) {
            $purchaseProduct = $purchaseProductRepository->find($purchaseProductId);
            if (null !== $purchaseProduct) {
                $lines[] = $purchaseProduct;
            }
        }

        try {
            $result = $cartCheckout->place($checkoutRequest, $lines, $localizationService->getLanguage(), $user);
        } catch (CheckoutRejected $e) {
            return $this->json($e->payload, $e->status);
        }

        return $this->json($result, Response::HTTP_OK, [], [
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

    /**
     * «Надіслати рахунок ще раз» — the buyer gets the same invoice PDF again in Telegram
     * (customer bot) and/or by email; while unpaid, with an «Оплатити онлайн» link.
     * Owner only; one resend per order every few minutes (rate_limiter.invoice_resend).
     */
    #[isGranted(RoleEnum::USER->value)]
    #[Route('/order/{id}/invoice/resend', name: 'order_invoice_resend', methods: [Request::METHOD_POST], requirements: ['id' => '\d+'])]
    public function resendInvoice(
        string $id,
        #[CurrentUser] User $user,
        UserOrderRepository $orderRepository,
        InvoiceCheckout $invoiceCheckout,
        RateLimiterFactoryInterface $invoiceResendLimiter,
        RateLimiterFactoryInterface $invoiceResendDailyLimiter,
    ): JsonResponse {
        $order = $orderRepository->find((int) $id);
        if ($order === null || $order->getClientUserId()?->getId() !== $user->getId() || !$order->getInvoiceNumber()) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        if ($order->getOrderStatus() === OrderStatusEnum::CANCELLED->value) {
            return $this->json([
                'error' => 'order_cancelled',
                'error_message' => 'Замовлення скасовано — рахунок більше не дійсний.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!InvoiceCheckout::hasChannel($order)) {
            return $this->json([
                'error' => 'no_channel',
                'error_message' => 'Немає куди надіслати: у замовленні немає email, а Telegram не підключено. Завантажте PDF кнопкою «Завантажити рахунок».',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Per account per day as well — the payer email is typed by the buyer, so the
        // resend must not become a way to mail someone else over and over.
        $daily = $invoiceResendDailyLimiter->create('user-'.$user->getId())->consume(0);
        if ($daily->getRemainingTokens() < 1) {
            return $this->json([
                'error' => 'too_many_requests',
                'error_message' => 'Ліміт повторних надсилань на сьогодні вичерпано. Завантажте PDF кнопкою «Завантажити рахунок» або напишіть нам.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $limit = $invoiceResendLimiter->create('order-'.$order->getId())->consume(1);
        if (!$limit->isAccepted()) {
            $wait = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return $this->json([
                'error' => 'too_many_requests',
                'retry_after' => $wait,
                'error_message' => sprintf('Рахунок щойно надіслано. Повторити можна через %s.', self::humanWait($wait)),
            ], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $wait]);
        }

        $invoiceResendDailyLimiter->create('user-'.$user->getId())->consume(1);

        try {
            $result = $invoiceCheckout->resend($order);
        } catch (InvoiceRequisitesMissingException) {
            $this->logger->error('Invoice resend blocked: seller requisites incomplete', ['order' => $order->getId()]);
            $invoiceResendLimiter->create('order-'.$order->getId())->reset();

            return $this->json([
                'error' => 'invoice_unavailable',
                'error_message' => 'Рахунок тимчасово недоступний. Зверніться до менеджера.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (null === $result['emailed_to'] && !$result['telegram_sent']) {
            $invoiceResendLimiter->create('order-'.$order->getId())->reset();

            return $this->json([
                'error' => 'send_failed',
                'error_message' => 'Не вдалося надіслати рахунок. Спробуйте пізніше або завантажте PDF.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json([
            'emailed_to' => $result['emailed_to'],
            'telegram_sent' => $result['telegram_sent'],
            'with_pay_link' => null !== $result['pay_url'],
        ]);
    }

    private static function humanWait(int $seconds): string
    {
        return $seconds < 60 ? sprintf('%d с', $seconds) : sprintf('%d хв', max(1, (int) round($seconds / 60)));
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
        LiqPayStatusSync $statusSync,
    ): JsonResponse
    {
        $objectHandler->entityLookup($id, UserOrder::class, 'id');
        /** @var UserOrder $userOrder */
        $userOrder = $orderRepository->findOneBy(['id' => $id]);

        if (!$user->getClientOrders()->contains($userOrder)) {
            return $this->json(['error' => 'user not owner of order'], Response::HTTP_BAD_REQUEST);
        }

        // The previous attempt may have been paid without a callback, or its LiqPay
        // invoice may still be open: check it and cancel the invoice first.
        $statusSync->beforeNewAttempt($userOrder);

        // Paid by card OR by a bank transfer a manager confirmed — never take the money twice.
        if ($userOrder->isPaid()) {
            return $this->json(['error' => 'order_already_paid'], Response::HTTP_BAD_REQUEST);
        }
        if (!OrderOnlinePayment::canPay($userOrder)) {
            return $this->json(['error' => 'order_not_payable'], Response::HTTP_BAD_REQUEST);
        }

        $language = $localizationService->getLanguage();
        $liqPayOrderID = sprintf('%s-%s', $userOrder->getId(), time());

        $liqpay = new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);

        $lang = $language === UserLanguageEnum::UA ? 'uk' : 'en';
        $resultUrl = $this->paymentResultUrl->build($lang, $userOrder->getId());

        $params = array(
            'action' => 'pay',
            'version' => '3',
            'amount' => $userOrder->getTotalAmount(),
            'currency' => OrderOnlinePayment::currency($userOrder, $lang),
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'result_url' => $resultUrl,
            'description' => OrderOnlinePayment::description($userOrder),
            'expired_date' => OrderOnlinePayment::expiresAt(),
        );
        $cnb_form_raw = $liqpay->cnb_form_raw($params);

        $userOrder->setLiqPayOrderId($liqPayOrderID);
        // New attempt: the old «failure/expired» must not make the result page say
        // «не пройшов» before this attempt's callback arrives.
        $userOrder->setLiqPaystatus(null);
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
