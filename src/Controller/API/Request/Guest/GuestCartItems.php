<?php

declare(strict_types=1);

namespace App\Controller\API\Request\Guest;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The guest cart as the browser keeps it (localStorage) — sent whole with the
 * quote and the checkout. Prices are NOT taken from here: the server prices
 * every line from the catalogue (GuestCartLines).
 */
trait GuestCartItems
{
    /**
     * @var list<array{product_id: int, quantity: int, product_properties?: list<array{property_name: string, property_value: string}>}>
     */
    #[Assert\NotBlank(message: 'Кошик порожній.')]
    #[Assert\Type('array')]
    #[Assert\Count(min: 1, max: 50, maxMessage: 'Забагато позицій у кошику (до {{ limit }}).')]
    #[Assert\All([
        new Assert\Collection(
            fields: [
                'product_id' => [new Assert\NotBlank(), new Assert\Type('int'), new Assert\Positive()],
                'quantity' => [new Assert\NotBlank(), new Assert\Type('int'), new Assert\Range(min: 1, max: 10000)],
                'product_properties' => new Assert\Optional([
                    new Assert\Type('array'),
                    new Assert\Count(max: 20),
                    new Assert\All([
                        new Assert\Collection(
                            fields: [
                                'property_name' => [new Assert\NotBlank(), new Assert\Type('string')],
                                'property_value' => [new Assert\NotBlank(), new Assert\Type('string')],
                            ],
                            allowExtraFields: true,
                        ),
                    ]),
                ]),
            ],
            allowExtraFields: true,
        ),
    ])]
    private array $items = [];

    public function getItems(): array
    {
        return $this->items;
    }

    public function setItems(array $items): static
    {
        $this->items = $items;

        return $this;
    }
}
