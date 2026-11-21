<?php

namespace App\Service\Cart;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Entity\PurchaseProduct;
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
        $lines = [];
        foreach ($purchaseProductIds as $purchaseProductId) {
            $purchaseProduct = $this->purchaseProductRepository->find($purchaseProductId);
            if ($purchaseProduct !== null) {
                $lines[] = $purchaseProduct;
            }
        }

        return $this->calculateLines($lines, $language, $catalogueOptionPrices);
    }

    /**
     * The same formula for lines that are already loaded — or not saved at all
     * (a guest cart lives in the browser and arrives with the checkout request).
     *
     * @param iterable<PurchaseProduct> $purchaseProducts
     */
    public function calculateLines(iterable $purchaseProducts, UserLanguageEnum $language, bool $catalogueOptionPrices = false): int
    {
        $total = 0;
        foreach ($purchaseProducts as $purchaseProduct) {
            $total += $this->unitPrice($purchaseProduct, $language, $catalogueOptionPrices) * $purchaseProduct->getQuantity();
        }

        return $total;
    }

    /** Price of one unit of a cart line: base price + the option surcharges. */
    public function unitPrice(PurchaseProduct $purchaseProduct, UserLanguageEnum $language, bool $catalogueOptionPrices = false): int
    {
        $product = $purchaseProduct->getProduct();
        if ($catalogueOptionPrices) {
            return $product->unitPriceIn($purchaseProduct->getProductProperties(), $language);
        }

        $price = (int) $product->getPrice($language);
        foreach ($purchaseProduct->getProductProperties() as $prop) {
            $price += (int) ($prop['property_price_impact'] ?? 0);
        }

        return $price;
    }
}
