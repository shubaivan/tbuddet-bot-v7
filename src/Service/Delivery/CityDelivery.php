<?php

declare(strict_types=1);

namespace App\Service\Delivery;

use App\Controller\API\Request\Enum\UserLanguageEnum;

/**
 * Платна доставка по місту (Черкаси) — окремий спосіб отримання поруч із
 * Новою Поштою та самовивозом (рішення Івана 15.11.2026).
 *
 * Ціна й місто — з env (CITY_DELIVERY_PRICE_UAH, CITY_DELIVERY_CITY), фронт
 * бере їх з /api/v1/cart/delivery/options і ніде не тримає своєї копії.
 * Ціна 0 — спосіб вимкнено. Лише для гривневих (українських) замовлень.
 */
final class CityDelivery
{
    public const METHOD = 'city_courier';
    public const METHODS = ['nova_poshta', 'pickup', self::METHOD];

    private const ADDRESS_MIN = 5;
    private const ADDRESS_MAX = 255;

    public function __construct(
        private readonly int $cityDeliveryPriceUah,
        private readonly string $cityDeliveryCity = 'Черкаси',
    ) {
    }

    public function price(): int
    {
        return max(0, $this->cityDeliveryPriceUah);
    }

    public function city(): string
    {
        return '' !== trim($this->cityDeliveryCity) ? trim($this->cityDeliveryCity) : 'Черкаси';
    }

    public function isAvailable(?UserLanguageEnum $language): bool
    {
        return $this->price() > 0 && null !== $language && UserLanguageEnum::EN !== $language;
    }

    /** Назва рядка в замовленні / рахунку / сповіщеннях. */
    public function label(): string
    {
        return 'Доставка по місту ('.$this->city().')';
    }

    /** null — адреса придатна; інакше текст помилки для покупця. */
    public function validateAddress(?string $address): ?string
    {
        $a = trim((string) $address);
        if (mb_strlen($a) < self::ADDRESS_MIN) {
            return \sprintf('Вкажіть адресу доставки в м. %s: вулиця, будинок (квартира).', $this->city());
        }
        if (mb_strlen($a) > self::ADDRESS_MAX) {
            return 'Адреса задовга — до 255 символів.';
        }
        if (!preg_match('/\d/u', $a)) {
            return 'Вкажіть, будь ласка, номер будинку.';
        }

        return null;
    }

    /** Нормалізована адреса, як її збережемо в замовленні. */
    public function normalizeAddress(string $address): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $address));
    }

    /** @return array{available:bool, price:int, currency:string, city:string, label:string} */
    public function options(?UserLanguageEnum $language): array
    {
        return [
            'available' => $this->isAvailable($language),
            'price' => $this->price(),
            'currency' => 'UAH',
            'city' => $this->city(),
            'label' => $this->label(),
        ];
    }
}
