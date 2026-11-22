<?php

declare(strict_types=1);

namespace App\Service\Cart;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Controller\API\Request\Purchase\CartCheckoutPayload;
use App\Controller\API\Request\Purchase\ProductProperties;
use App\Entity\Enum\CurrencyEnum;
use App\Entity\PurchaseProduct;
use App\Entity\User;
use App\Entity\UserOrder;
use App\Invoice\InvoiceCheckout;
use App\Invoice\InvoiceService;
use App\Liqpay\LiqPay;
use App\Service\Analytics\ActivityService;
use App\Service\Campaign\CampaignTracker;
use App\Service\Delivery\CityDelivery;
use App\Service\Payment\PaymentResultUrl;
use App\Service\Promocode\PromocodeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * One cart → one order, for both entry points:
 *  - the logged-in cart (ShoppingCartController::checkoutAction): the lines are the
 *    saved cart rows, the buyer is the account;
 *  - the guest cart (GuestCheckoutController): the lines arrive with the request and
 *    are saved together with the order, the buyer is the phone + name typed in.
 *
 * Delivery, prices, promocode, invoice and LiqPay are decided here only, so the two
 * flows can never charge differently for the same cart.
 */
class CartCheckout
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $liqpayPublicKey,
        private readonly string $liqpayPrivateKey,
        private readonly string $liqpayServerUrl,
        private readonly PaymentResultUrl $paymentResultUrl,
        private readonly EntityManagerInterface $em,
        private readonly CartTotalCalculator $cartTotalCalculator,
        private readonly PromocodeService $promocodeService,
        private readonly ActivityService $activity,
        private readonly CityDelivery $cityDelivery,
        private readonly InvoiceService $invoiceService,
        private readonly InvoiceCheckout $invoiceCheckout,
        private readonly CampaignTracker $campaignTracker,
    ) {
    }

    /**
     * Exactly one of $user / $guest is given.
     *
     * @param list<PurchaseProduct> $lines saved cart rows (logged-in) or new, not yet persisted rows (guest)
     *
     * @return array{order: UserOrder, invoice?: array<string, mixed>, liqpay?: mixed, link?: string}
     *
     * @throws CheckoutRejected before anything is saved
     */
    public function place(
        CartCheckoutPayload $request,
        array $lines,
        UserLanguageEnum $language,
        ?User $user,
        ?GuestContact $guest = null,
    ): array {
        if ((null === $user) === (null === $guest)) {
            throw new \LogicException('A cart checkout needs either the account or the guest contact.');
        }

        // Pay-by-invoice is offered only once the seller requisites are filled in;
        // the FE hides the option, this guards direct API calls.
        if ($request->isInvoice() && !$this->invoiceService->isAvailable()) {
            throw new CheckoutRejected([
                'error' => 'invoice_unavailable',
                'error_message' => 'Оплата за рахунком тимчасово недоступна. Оберіть оплату карткою або зверніться до менеджера.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Invoices are always in hryvnia (the PDF, «Оплатити онлайн» and the bank transfer):
        // on /en the order of an invoice is priced in UAH too, options included.
        $priceLanguage = $request->isInvoice() ? UserLanguageEnum::UA : $language;

        // Спосіб доставки. Старий фронт його не шле — тоді НП, якщо є місто, інакше самовивіз.
        $deliveryMethod = $request->getDeliveryMethod()
            ?? ($request->getDeliveryCity() ? 'nova_poshta' : 'pickup');
        $deliveryAmount = 0;
        $deliveryAddress = null;
        if ('nova_poshta' === $deliveryMethod
            && (trim((string) $request->getDeliveryCity()) === '' || trim((string) $request->getDeliveryDepartment()) === '')
        ) {
            throw new CheckoutRejected([
                'error' => 'delivery_address_invalid',
                'error_message' => 'Оберіть місто та відділення Нової Пошти.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (CityDelivery::METHOD === $deliveryMethod) {
            if (!$this->cityDelivery->isAvailable($priceLanguage)) {
                throw new CheckoutRejected([
                    'error' => 'delivery_unavailable',
                    'error_message' => 'Доставка по місту зараз недоступна. Оберіть Нову Пошту або самовивіз.',
                ], Response::HTTP_BAD_REQUEST);
            }
            $addressError = $this->cityDelivery->validateAddress($request->getDeliveryAddress());
            if (null !== $addressError) {
                throw new CheckoutRejected([
                    'error' => 'delivery_address_invalid',
                    'error_message' => $addressError,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $deliveryAddress = $this->cityDelivery->normalizeAddress((string) $request->getDeliveryAddress());
            // In the order's currency: 800 UAH on /uk, a fixed $20 on /en (card).
            $deliveryAmount = $this->cityDelivery->price($priceLanguage);
        }

        // Subtotal uses the shared calculator so the promocode preview and this
        // final apply step always agree on the number.
        $subtotal = $this->cartTotalCalculator->calculateLines($lines, $priceLanguage, $request->isInvoice());

        // Re-validate the promocode server-side even though the FE already previewed it —
        // the cart may have changed, or the code may have been deactivated in between.
        $appliedPromocode = null;
        $discount = 0;
        if ($request->getPromocode() !== null && $request->getPromocode() !== '') {
            $validation = $this->promocodeService->validate(
                $request->getPromocode(),
                $subtotal,
                CurrencyEnum::fromUserLanguage($priceLanguage),
                $user,
                null,
                $guest?->phone,
            );
            if (!$validation->ok) {
                throw new CheckoutRejected([
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
        $userOrder->setCurrency(CurrencyEnum::fromUserLanguage($priceLanguage)->value);
        if (null !== $guest) {
            $userOrder->setPhone($guest->phone);
            $userOrder->setContactName($guest->name);
            $userOrder->setContactEmail($guest->email);
        }
        $userOrder->setDeliveryMethod($deliveryMethod);
        if ('nova_poshta' === $deliveryMethod) {
            $userOrder->setDeliveryCity($request->getDeliveryCity());
            $userOrder->setDeliveryCityRef($request->getDeliveryCityRef());
            $userOrder->setDeliveryDepartment($request->getDeliveryDepartment());
            $userOrder->setDeliveryDepartmentRef($request->getDeliveryDepartmentRef());
        } elseif (CityDelivery::METHOD === $deliveryMethod) {
            $userOrder->setDeliveryCity($this->cityDelivery->city());
            $userOrder->setDeliveryAddress($deliveryAddress);
            $userOrder->setDeliveryAmount($deliveryAmount);
        }
        $this->em->persist($userOrder);

        $description = '';
        $propExplainingTemplate = 'Назва: %s, Значення: %s, Плюс до ціни продкта: %s';

        foreach ($lines as $purchaseProduct) {
            $purchaseProduct->setUserOrder($userOrder);
            if (null === $purchaseProduct->getId()) {
                // Guest line: saved now, with the order (no shopping cart behind it).
                $this->em->persist($purchaseProduct);
            }

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
                // Invoice: the Ukrainian option and its hryvnia surcharge, as on the invoice.
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

            $description .= sprintf('Ваше замовлення: %s: в кількості: %s одиниць'.PHP_EOL,
                $product->getProductName($priceLanguage),
                $purchaseProduct->getQuantity()
            );

            if (count($propExplainingSet)) {
                $description .= PHP_EOL.implode(PHP_EOL, $propExplainingSet).PHP_EOL;
            }
        }

        if ($deliveryAmount > 0) {
            $description .= sprintf('%s%s: %s — %s', PHP_EOL, $this->cityDelivery->label(), $deliveryAddress, $this->cityDelivery->priceText($deliveryAmount, $priceLanguage));
        } elseif ('nova_poshta' === $deliveryMethod && $request->getDeliveryCity()) {
            $description .= sprintf('%sДоставка: %s, %s',
                PHP_EOL,
                $request->getDeliveryCity(),
                $request->getDeliveryDepartment() ?? ''
            );
        }

        if ($appliedPromocode !== null) {
            // User-visible line in LiqPay description so the buyer sees exactly which
            // code applied and how much was knocked off.
            $description .= PHP_EOL.$appliedPromocode->describeDiscount($discount);
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

        if ($request->isInvoice()) {
            $invoiceLines = $this->invoiceService->buildLines($lines, UserLanguageEnum::UA);
            if ($deliveryAmount > 0) {
                $invoiceLines[] = InvoiceService::deliveryLine($this->cityDelivery->label().': '.$deliveryAddress, $deliveryAmount);
            }
            $this->invoiceCheckout->prepare(
                $userOrder,
                $request->getPayer() ?? [],
                $invoiceLines,
                // A guest has no account to remember the payer on.
                $request->isRememberPayer() ? $user : null,
            );
        }

        $this->em->flush();

        if ($appliedPromocode !== null) {
            // Records the ledger row + bumps times_used. UNIQUE constraint catches
            // accidental double-apply on retried POSTs (treated as idempotent).
            $this->promocodeService->redeem(
                $appliedPromocode,
                $userOrder,
                $user,
                null,
                $guest?->phone,
                $discount,
            );
        }

        $this->campaignTracker->attributeOrder($userOrder, $user);

        // Сповіщення менеджерам у групу «Заявки ArtBeton» про щойно створене замовлення.
        $currencyLabel = $priceLanguage === UserLanguageEnum::UA ? 'грн' : 'USD';
        if (null !== $user) {
            $customerName = trim($user->getFirstName().' '.$user->getLastName());
            if ($user->getPhone()) {
                $customerName = trim($customerName.', '.$user->getPhone());
            }
        } else {
            $customerName = '👤 гість (без акаунта): '.$guest->label().($guest->email ? ', '.$guest->email : '');
        }
        $amountLine = sprintf('Сума: %s %s', $total_amount, $currencyLabel)
            .($userOrder->isInvoicePayment() ? ' · 🧾 оплата за рахунком № '.$userOrder->getInvoiceNumber() : '');
        if ($deliveryAmount > 0) {
            $amountLine .= sprintf(' (товари %s + доставка %s)', $total_amount - $deliveryAmount, $deliveryAmount)
                ."\n🚚 ".$userOrder->getDeliverySummary();
        }
        $this->activity->orderPlaced(
            $userOrder->getId(),
            $amountLine,
            $customerName !== '' ? $customerName : null,
            count($lines),
        );

        if ($userOrder->isInvoicePayment()) {
            return [
                'order' => $userOrder,
                'invoice' => $this->invoiceCheckout->complete($userOrder),
            ];
        }

        $liqPayOrderID = sprintf('%s-%s', $userOrder->getId(), time());
        // The currency the order is priced in (card orders: the site language).
        $currency = (string) $userOrder->getCurrency();

        $liqpay = $this->newLiqPay();

        $phoneNumber = $user?->getPhone() ?? $userOrder->getPhone();

        $params = [
            'action' => 'invoice_send',
            'version' => '3',
            'phone' => $phoneNumber,
            'amount' => $userOrder->getTotalAmount(),
            'currency' => $currency,
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'description' => $description,
        ];
        $res = $liqpay->api('request', $params);
        $userOrder->setLiqPayresponse(json_encode($res));
        $userOrder->setLiqPayorderid($liqPayOrderID);
        $this->em->flush();

        $lang = $language === UserLanguageEnum::UA ? 'uk' : 'en';

        $params = [
            'action' => 'pay',
            'version' => '3',
            'amount' => $userOrder->getTotalAmount(),
            'currency' => $currency,
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            // Signed status link: a guest has no login to see the order otherwise.
            'result_url' => $this->paymentResultUrl->build($lang, $userOrder->getId()),
            'description' => $description,
        ];
        $cnb_form_raw = $liqpay->cnb_form_raw($params);
        $link = sprintf(
            '%s?%s&%s',
            $cnb_form_raw['url'],
            'data='.$cnb_form_raw['data'],
            'signature='.$cnb_form_raw['signature'],
        );

        return [
            'order' => $userOrder,
            'liqpay' => $res,
            'link' => $link,
        ];
    }

    /** Seam for tests (the LiqPay client calls the LiqPay API over curl). */
    protected function newLiqPay(): LiqPay
    {
        return new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);
    }
}
