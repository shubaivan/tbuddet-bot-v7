<?php

declare(strict_types=1);

namespace App\Service\Cart;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Entity\PurchaseProduct;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the guest cart sent by the browser into cart lines (PurchaseProduct, not
 * saved) that CartCheckout / CartTotalCalculator price exactly like a saved cart.
 *
 * Nothing the browser says about money is trusted: every option is looked up in
 * the catalogue and stored as it reads in $language (name, value and surcharge
 * of its catalogue twin), so a cart filled on /en and paid on /uk — or an old
 * surcharge kept in localStorage — is priced by today's catalogue.
 */
class GuestCartLines
{
    public function __construct(private readonly ProductRepository $productRepository)
    {
    }

    /**
     * @param list<array{product_id: int, quantity: int, product_properties?: list<array{property_name: string, property_value: string}>}> $items
     *
     * @return list<PurchaseProduct>
     *
     * @throws CheckoutRejected when a product or an option is no longer in the catalogue
     */
    public function build(array $items, UserLanguageEnum $language): array
    {
        $lines = [];
        foreach ($items as $index => $item) {
            $product = $this->productRepository->find((int) $item['product_id']);
            if (null === $product) {
                throw new CheckoutRejected([
                    'error' => 'cart_outdated',
                    'item' => $index,
                    'error_message' => 'Одного з товарів уже немає в каталозі. Видаліть його з кошика й спробуйте ще раз.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $price = $product->getPrice($language);
            if (!is_numeric($price) || (int) $price <= 0) {
                throw new CheckoutRejected([
                    'error' => 'cart_outdated',
                    'item' => $index,
                    'error_message' => sprintf('«%s» зараз не продається онлайн. Видаліть товар із кошика або напишіть менеджеру.', (string) $product->getProductName(UserLanguageEnum::UA)),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // One value per option name (the product page works the same way).
            $options = [];
            foreach ($item['product_properties'] ?? [] as $option) {
                $twin = $product->optionIn((string) $option['property_name'], (string) $option['property_value'], $language);
                if (null === $twin || !isset($twin['property_name'], $twin['property_value'])) {
                    throw new CheckoutRejected([
                        'error' => 'cart_outdated',
                        'item' => $index,
                        'error_message' => sprintf(
                            'Опція «%s: %s» для «%s» більше недоступна. Видаліть товар із кошика й додайте знову.',
                            $option['property_name'],
                            $option['property_value'],
                            (string) $product->getProductName(UserLanguageEnum::UA),
                        ),
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $options[(string) $twin['property_name']] = [
                    'property_name' => (string) $twin['property_name'],
                    'property_value' => (string) $twin['property_value'],
                    'property_price_impact' => (int) ($twin['property_price_impact'] ?? 0),
                ];
            }

            $lines[] = (new PurchaseProduct())
                ->setProduct($product)
                ->setQuantity((int) $item['quantity'])
                ->setProductProperties(array_values($options));
        }

        return $lines;
    }
}
