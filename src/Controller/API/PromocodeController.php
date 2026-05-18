<?php

namespace App\Controller\API;

use App\Controller\API\Request\Promocode\ValidatePromocodeRequest;
use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\RoleEnum;
use App\Entity\User;
use App\Service\Cart\CartTotalCalculator;
use App\Service\LocalizationService;
use App\Service\Promocode\PromocodeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: 'api/v1/promocode')]
class PromocodeController extends AbstractController
{
    /**
     * Stateless validation: tells the FE whether $code can be applied to the current
     * cart, and previews the resulting discount. Does NOT reserve or consume anything.
     * The same code is re-validated server-side during checkout.
     */
    #[IsGranted(RoleEnum::USER->value)]
    #[Route('/validate', name: 'promocode_validate', methods: [Request::METHOD_POST])]
    public function validate(
        #[MapRequestPayload] ValidatePromocodeRequest $request,
        #[CurrentUser] User $user,
        PromocodeService $promocodeService,
        CartTotalCalculator $cartTotalCalculator,
        LocalizationService $localizationService,
    ): JsonResponse {
        $language = $localizationService->getLanguage();
        $subtotal = $cartTotalCalculator->calculate($request->getPurchaseProductIds(), $language);
        $currency = CurrencyEnum::fromUserLanguage($language);

        $result = $promocodeService->validate(
            $request->getCode(),
            $subtotal,
            $currency,
            $user,
            null,
            null,
        );

        if (!$result->ok) {
            return $this->json([
                'ok' => false,
                'error_code' => $result->error->value,
                'error_message' => $result->error->userMessage(),
            ], Response::HTTP_OK);
        }

        return $this->json([
            'ok' => true,
            'code' => $result->promocode->getCode(),
            'discount' => $result->discount,
            'subtotal' => $subtotal,
            'total_after_discount' => $subtotal - $result->discount,
            'currency' => $currency->value,
            'description' => $result->promocode->describeDiscount($result->discount),
        ]);
    }
}
