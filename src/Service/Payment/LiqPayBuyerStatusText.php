<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * The Telegram line a buyer gets when the LiqPay status of the order changes
 * (anything but `success`, which has its own «thank you» message): plain Ukrainian
 * instead of the raw LiqPay code («try_again», «wait_secure»…).
 */
final class LiqPayBuyerStatusText
{
    private const FAILED = ['failure', 'error'];

    private const DECLINED = ['try_again'];

    private const REFUNDED = ['reversed'];

    private const PROCESSING = [
        'processing', 'prepared', 'wait_secure', 'wait_accept', 'wait_compensation',
        'wait_lc', 'wait_reserve', 'hold_wait', 'wait_card',
    ];

    private const NEEDS_CONFIRMATION = [
        '3ds_verify', 'otp_verify', 'cvv_verify', 'pin_verify', 'ivr_verify', 'captcha_verify',
        'password_verify', 'phone_verify', 'sender_verify', 'receiver_verify',
        'senderapp_verify', 'wait_qr', 'wait_sender',
    ];

    private const AWAITING = ['invoice_wait', 'cash_wait'];

    public static function forBuyer(string $status, int $orderId): string
    {
        $order = 'замовлення #'.$orderId;

        return match (true) {
            \in_array($status, self::DECLINED, true) => sprintf(
                'Банк відхилив оплату за %s. Спробуйте іншу картку або оплатіть ще раз у «Моїх замовленнях» на сайті.',
                $order,
            ),
            \in_array($status, self::FAILED, true) => sprintf(
                'Оплата за %s не пройшла. Спробуйте ще раз у «Моїх замовленнях» на сайті або напишіть нам — менеджер допоможе.',
                $order,
            ),
            \in_array($status, self::REFUNDED, true) => sprintf(
                'Оплату за %s повернено на вашу картку.',
                $order,
            ),
            \in_array($status, self::PROCESSING, true) => sprintf(
                'Платіж за %s обробляється банком. Повідомимо, щойно оплату буде підтверджено.',
                $order,
            ),
            \in_array($status, self::NEEDS_CONFIRMATION, true) => sprintf(
                'Платіж за %s чекає на ваше підтвердження (код із SMS, 3-D Secure або застосунок банку). Завершіть оплату на сторінці LiqPay.',
                $order,
            ),
            \in_array($status, self::AWAITING, true) => sprintf(
                'Платіж за %s очікує на оплату.',
                $order,
            ),
            'sandbox' === $status => sprintf(
                'Тестову оплату за %s отримано (режим перевірки LiqPay).',
                $order,
            ),
            default => sprintf(
                'Статус оплати за %s оновлено. Подробиці — у «Моїх замовленнях» на сайті або в нашого менеджера.',
                $order,
            ),
        };
    }
}
