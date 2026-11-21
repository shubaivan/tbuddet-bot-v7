<?php

declare(strict_types=1);

namespace App\Controller\API;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Controller\API\Request\Guest\GuestCheckoutRequest;
use App\Controller\API\Request\Guest\GuestQuoteRequest;
use App\Entity\Enum\CurrencyEnum;
use App\Entity\UserOrder;
use App\Invoice\InvoiceCheckout;
use App\Service\Cart\CartCheckout;
use App\Service\Cart\CartTotalCalculator;
use App\Service\Cart\CheckoutRejected;
use App\Service\Cart\GuestCartLines;
use App\Service\Cart\GuestContact;
use App\Service\Delivery\CityDelivery;
use App\Service\LocalizationService;
use App\Service\Promocode\PromocodeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * One-page checkout for a buyer without an account. The cart lives in the
 * browser (localStorage) and is sent whole; the server prices it from the
 * catalogue and places the order through the same CartCheckout as the
 * logged-in cart. Public (security.yaml: ^/api/v1/cart/guest), rate-limited
 * per IP, with a honeypot field.
 */
#[Route(path: 'api/v1/cart/guest')]
class GuestCheckoutController extends AbstractController
{
    /** City delivery and pay-by-invoice availability for the guest checkout page. */
    #[Route('/options', name: 'guest_checkout_options', methods: [Request::METHOD_GET])]
    public function options(LocalizationService $localizationService, CityDelivery $cityDelivery, InvoiceCheckout $invoiceCheckout): JsonResponse
    {
        return $this->json([
            'city_delivery' => $cityDelivery->options($localizationService->getLanguage()),
            'invoice_available' => $invoiceCheckout->isAvailable(),
        ]);
    }

    /**
     * Today's prices of the guest cart + an optional promocode preview: exactly the
     * subtotal and discount the checkout will use. Saves nothing.
     */
    #[Route('/quote', name: 'guest_checkout_quote', methods: [Request::METHOD_POST])]
    public function quote(
        Request $request,
        #[MapRequestPayload] GuestQuoteRequest $quoteRequest,
        LocalizationService $localizationService,
        GuestCartLines $guestCartLines,
        CartTotalCalculator $cartTotalCalculator,
        PromocodeService $promocodeService,
        RateLimiterFactoryInterface $guestQuoteLimiter,
    ): JsonResponse {
        if (!$guestQuoteLimiter->create($request->getClientIp() ?? 'anon')->consume(1)->isAccepted()) {
            return $this->tooManyRequests();
        }

        $language = $localizationService->getLanguage();
        // Same rule as CartCheckout: an invoice is priced in hryvnia on /en too.
        $priceLanguage = $quoteRequest->isInvoice() ? UserLanguageEnum::UA : $language;
        $currency = CurrencyEnum::fromUserLanguage($priceLanguage);

        try {
            $lines = $guestCartLines->build($quoteRequest->getItems(), $language);
        } catch (CheckoutRejected $e) {
            return $this->json($e->payload, $e->status);
        }

        $pricedLines = [];
        foreach ($lines as $line) {
            $unit = $cartTotalCalculator->unitPrice($line, $priceLanguage, $quoteRequest->isInvoice());
            $pricedLines[] = [
                'product_id' => $line->getProduct()->getId(),
                'quantity' => $line->getQuantity(),
                'unit_price' => $unit,
                'sum' => $unit * $line->getQuantity(),
                'product_properties' => $line->getProductProperties(),
            ];
        }
        $subtotal = $cartTotalCalculator->calculateLines($lines, $priceLanguage, $quoteRequest->isInvoice());

        $promocode = null;
        $discount = 0;
        if (null !== $quoteRequest->getPromocode() && '' !== $quoteRequest->getPromocode()) {
            $result = $promocodeService->validate(
                $quoteRequest->getPromocode(),
                $subtotal,
                $currency,
                null,
                null,
                $quoteRequest->getPhone(),
            );
            if ($result->ok) {
                $discount = $result->discount;
                $promocode = [
                    'ok' => true,
                    'code' => $result->promocode->getCode(),
                    'discount' => $result->discount,
                    'description' => $result->promocode->describeDiscount($result->discount),
                ];
            } else {
                $promocode = [
                    'ok' => false,
                    'error_code' => $result->error->value,
                    'error_message' => $result->error->userMessage(),
                ];
            }
        }

        return $this->json([
            'lines' => $pricedLines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total_after_discount' => $subtotal - $discount,
            'currency' => $currency->value,
            'promocode' => $promocode,
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('/checkout', name: 'guest_checkout', methods: [Request::METHOD_POST])]
    public function checkout(
        Request $request,
        #[MapRequestPayload] GuestCheckoutRequest $checkoutRequest,
        LocalizationService $localizationService,
        GuestCartLines $guestCartLines,
        CartCheckout $cartCheckout,
        RateLimiterFactoryInterface $guestCheckoutLimiter,
    ): JsonResponse {
        // Honeypot filled in → a bot. Same answer as a broken request, nothing saved.
        if (null !== $checkoutRequest->getWebsite() && '' !== trim($checkoutRequest->getWebsite())) {
            return $this->json(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }
        if (!$guestCheckoutLimiter->create($request->getClientIp() ?? 'anon')->consume(1)->isAccepted()) {
            return $this->tooManyRequests();
        }

        $language = $localizationService->getLanguage();

        try {
            $lines = $guestCartLines->build($checkoutRequest->getItems(), $language);
            $result = $cartCheckout->place(
                $checkoutRequest,
                $lines,
                $language,
                null,
                new GuestContact(
                    (string) $checkoutRequest->getPhone(),
                    (string) $checkoutRequest->getName(),
                    $checkoutRequest->getEmail(),
                ),
            );
        } catch (CheckoutRejected $e) {
            return $this->json($e->payload, $e->status);
        }

        return $this->json($result, Response::HTTP_OK, ['Cache-Control' => 'no-store'], [
            AbstractNormalizer::GROUPS => [UserOrder::PROTECTED_ORDER_VIEW_GROUP],
        ]);
    }

    private function tooManyRequests(): JsonResponse
    {
        return $this->json([
            'error' => 'too_many_requests',
            'error_message' => 'Забагато спроб. Зачекайте кілька хвилин або напишіть менеджеру.',
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }
}
