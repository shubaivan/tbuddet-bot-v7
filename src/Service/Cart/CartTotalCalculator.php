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
     * @param bool  $catalogueOptionPrices option surcharges from the catalogue in $language instead of the
     *                                     stored ones (invoices: everything in UAH, as InvoiceService::buildLines)
     */
    public function calculate(array $purchaseProductIds, UserLanguageEnum $language, bool $catalogueOptionPrices = false): int
    {
        $total = 0;

        foreach ($purchaseProductIds as $purchaseProductId) {
            $purchaseProduct = $this->purchaseProductRepository->find($purchaseProductId);
            if ($purchaseProduct === null) {
                continue;
            }

            $product = $purchaseProduct->getProduct();
            if ($catalogueOptionPrices) {
                $price = $product->unitPriceIn($purchaseProduct->getProductProperties(), $language);
            } else {
                $price = (int) $product->getPrice($language);
                foreach ($purchaseProduct->getProductProperties() as $prop) {
                    $price += (int) ($prop['property_price_impact'] ?? 0);
                }
            }

            $total += $price * $purchaseProduct->getQuantity();
        }

        return $total;
    }
}
