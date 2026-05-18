<?php

namespace App\Service\Cart;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Repository\PurchaseProductRepository;

/**
 * Compute the pre-discount subtotal for a list of PurchaseProduct IDs.
 *
 * Extracted from ShoppingCartController::checkoutAction so the same formula
 * powers the /api/v1/promocode/validate preview endpoint and the actual
 * checkout — preventing client/server discount-amount drift.
 */
class CartTotalCalculator
{
    public function __construct(private readonly PurchaseProductRepository $purchaseProductRepository)
    {
    }

    /**
     * @param int[] $purchaseProductIds
     */
    public function calculate(array $purchaseProductIds, UserLanguageEnum $language): int
    {
        $total = 0;

        foreach ($purchaseProductIds as $purchaseProductId) {
            $purchaseProduct = $this->purchaseProductRepository->find($purchaseProductId);
            if ($purchaseProduct === null) {
                continue;
            }

            $product = $purchaseProduct->getProduct();
            $price = (int) $product->getPrice($language);

            foreach ($purchaseProduct->getProductProperties() as $prop) {
                $price += (int) ($prop['property_price_impact'] ?? 0);
            }

            $total += $price * $purchaseProduct->getQuantity();
        }

        return $total;
    }
}
