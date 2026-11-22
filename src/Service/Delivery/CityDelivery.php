<?php

declare(strict_types=1);

namespace App\Service\Delivery;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Entity\Enum\CurrencyEnum;

/**
 * Платна доставка по місту (Черкаси) — окремий спосіб отримання поруч із
 * Новою Поштою та самовивозом (рішення Івана 15.11.2026).
 *
 * Ціна й місто — з env (CITY_DELIVERY_PRICE_UAH, CITY_DELIVERY_PRICE_USD,
 * CITY_DELIVERY_CITY), фронт бере їх з /api/v1/cart/delivery/options і ніде не
 * тримає своєї копії. Гривневе замовлення (/uk, рахунок) — ціна в UAH, доларове
 * (/en, картка) — фіксована ціна в USD (рішення Івана 22.11.2026), не курс.
 * Ціна 0 у валюті замовлення — спосіб вимкнено для цієї валюти.
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
        private readonly int $cityDeliveryPriceUsd = 0,
        private readonly string $cityDeliveryCityEn = '',
    ) {
    }

    /**
     * Ціна у валюті замовлення: $priceLanguage — мова, якою оцінене замовлення
     * (UA → гривні; EN → долари; рахунок на /en оцінюється як UA).
     */
    public function price(?UserLanguageEnum $priceLanguage): int
    {
        return max(0, 'USD' === $this->currency($priceLanguage) ? $this->cityDeliveryPriceUsd : $this->cityDeliveryPriceUah);
    }

    /** 'UAH' | 'USD' — валюта ціни price($priceLanguage), те саме правило, що в LiqPay (CurrencyEnum). */
    public function currency(?UserLanguageEnum $priceLanguage): string
    {
        return null === $priceLanguage ? 'UAH' : CurrencyEnum::fromUserLanguage($priceLanguage)->value;
    }

    /** «800 грн» / «20 USD» — для описів LiqPay і повідомлень менеджерам. */
    public function priceText(int $amount, ?UserLanguageEnum $priceLanguage): string
    {
        return $amount.' '.('USD' === $this->currency($priceLanguage) ? 'USD' : 'грн');
    }

    public function city(): string
    {
        return '' !== trim($this->cityDeliveryCity) ? trim($this->cityDeliveryCity) : 'Черкаси';
    }

    public function isAvailable(?UserLanguageEnum $priceLanguage): bool
    {
        return null !== $priceLanguage && $this->price($priceLanguage) > 0;
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

    /** City name for the buyer: English on /en (orders and managers keep city()). */
    public function displayCity(?UserLanguageEnum $language): string
    {
        return UserLanguageEnum::EN === $language && '' !== trim($this->cityDeliveryCityEn)
            ? trim($this->cityDeliveryCityEn)
            : $this->city();
    }

    /** @return array{available:bool, price:int, currency:string, city:string, label:string} */
    public function options(?UserLanguageEnum $language): array
    {
        return [
            'available' => $this->isAvailable($language),
            'price' => $this->price($language),
            'currency' => $this->currency($language),
            'city' => $this->displayCity($language),
            'label' => $this->label(),
        ];
    }
}
