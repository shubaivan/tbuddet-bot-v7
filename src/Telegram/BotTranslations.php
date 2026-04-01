<?php

namespace App\Telegram;

class BotTranslations
{
    private const TRANSLATIONS = [
        // Start menu
        'menu.choose' => ['ua' => 'Оберіть питання:', 'en' => 'Choose an option:'],
        'menu.products' => ['ua' => 'Продукція', 'en' => 'Products'],
        'menu.my_orders' => ['ua' => 'Мої покупки', 'en' => 'My orders'],
        'menu.route' => ['ua' => 'Як доїхати на завод?', 'en' => 'How to get to factory?'],
        'menu.language' => ['ua' => '↔ Мова', 'en' => '↔ Language'],

        // Language toggle
        'lang.changed_ua' => ['ua' => '🇺🇦 Мову змінено на <b>Українську</b>. Ціни в <b>грн</b>.', 'en' => '🇺🇦 Мову змінено на <b>Українську</b>. Ціни в <b>грн</b>.'],
        'lang.changed_en' => ['ua' => '🇬🇧 Language changed to <b>English</b>. Prices in <b>USD</b>.', 'en' => '🇬🇧 Language changed to <b>English</b>. Prices in <b>USD</b>.'],

        // Product carousel
        'product.select' => ['ua' => '🛒 Обрати', 'en' => '🛒 Select'],
        'product.categories' => ['ua' => '🔙 Категорії', 'en' => '🔙 Categories'],
        'product.currency' => ['ua' => 'грн', 'en' => 'USD'],
        'product.sum' => ['ua' => 'Сума', 'en' => 'Total'],
        'product.quantity' => ['ua' => 'Кількість', 'en' => 'Quantity'],

        // Properties
        'props.choose' => ['ua' => '👆 Оберіть властивість <b>%s</b>:', 'en' => '👆 Choose <b>%s</b> property:'],

        // City / Nova Poshta
        'city.ask' => [
            'ua' => "📍 <b>Введіть назву вашого міста</b>\n<i>Напишіть назву міста (наприклад: Київ, Черкаси, Одеса) — ми знайдемо найближче відділення Нової Пошти</i>",
            'en' => "📍 <b>Enter your city name</b>\n<i>Type the city name (e.g. Kyiv, Cherkasy, Odesa) — we'll find the nearest Nova Poshta branch</i>",
        ],
        'city.not_found' => [
            'ua' => "❌ Місто не знайдено.\n<i>Спробуйте ввести інше місто (наприклад: Київ, Черкаси, Одеса)</i>",
            'en' => "❌ City not found.\n<i>Try another city (e.g. Kyiv, Cherkasy, Odesa)</i>",
        ],
        'city.choose' => ['ua' => '🏙 <b>Оберіть місто:</b>', 'en' => '🏙 <b>Choose city:</b>'],
        'city.search_another' => ['ua' => '🔍 Шукати інше місто', 'en' => '🔍 Search another city'],
        'city.skip_delivery' => ['ua' => '⏭ Без доставки', 'en' => '⏭ Skip delivery'],

        // Warehouse
        'warehouse.choose' => ['ua' => '📬 <b>Оберіть відділення:</b>', 'en' => '📬 <b>Choose branch:</b>'],
        'warehouse.not_found' => [
            'ua' => '❌ Відділення не знайдено. Введіть інше місто:',
            'en' => '❌ No branches found. Enter another city:',
        ],

        // Quantity
        'quantity.ask' => ['ua' => '🔢 <b>Введіть кількість:</b>', 'en' => '🔢 <b>Enter quantity:</b>'],
        'quantity.invalid' => ['ua' => '❌ Тільки цифри. Введіть кількість:', 'en' => '❌ Numbers only. Enter quantity:'],

        // Confirm
        'confirm.title' => ['ua' => "\n<b>Підтвердіть замовлення:</b>", 'en' => "\n<b>Confirm your order:</b>"],
        'confirm.button' => ['ua' => '✅ Підтвердити', 'en' => '✅ Confirm'],
        'confirm.cancel' => ['ua' => '❌ Скасувати', 'en' => '❌ Cancel'],

        // Phone
        'phone.ask' => ['ua' => '📱 Натисніть кнопку нижче:', 'en' => '📱 Press the button below:'],
        'phone.confirm' => ['ua' => '📱 Підтвердіть номер телефону:', 'en' => '📱 Confirm your phone number:'],
        'phone.button' => ['ua' => '📱 Надіслати телефон', 'en' => '📱 Send phone number'],

        // Payment
        'payment.link' => ['ua' => 'Перейти до оплати', 'en' => 'Proceed to payment'],
        'payment.thanks' => ['ua' => '🎉 <b>Дякуємо!</b> Чекайте повідомлення.', 'en' => '🎉 <b>Thank you!</b> We\'ll contact you soon.'],
        'payment.description' => ['ua' => 'Замовлення', 'en' => 'Order'],
        'payment.delivery' => ['ua' => 'Доставка', 'en' => 'Delivery'],
    ];

    public static function t(string $key, string $lang = 'ua'): string
    {
        return self::TRANSLATIONS[$key][$lang] ?? self::TRANSLATIONS[$key]['ua'] ?? $key;
    }
}
