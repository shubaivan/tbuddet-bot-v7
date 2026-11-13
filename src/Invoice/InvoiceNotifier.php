<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Entity\Enum\RoleEnum;
use App\Entity\UserOrder;
use App\Repository\TelegramUserRepository;
use App\Service\Analytics\TelegramNotifier;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Telegram side of pay-by-invoice: managers (DM + the shop group topic) and the buyer.
 * Every send is best-effort: a Telegram failure must never break checkout or admin actions.
 */
class InvoiceNotifier
{
    public function __construct(
        private readonly Nutgram $bot,
        private readonly TelegramUserRepository $telegramUserRepository,
        private readonly TelegramNotifier $groupNotifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function invoiceIssued(UserOrder $order, string $adminOrderUrl, bool $emailed): void
    {
        $payer = $order->getInvoicePayer();
        $esc = static fn (?string $s): string => TelegramNotifier::esc((string) $s);

        $text = sprintf(
            "🧾 <b>Рахунок № %s — замовлення #%d</b>\nПлатник: %s, %s %s\nКонтакт: %s, +%s, %s\nСума: %s грн, сплатити до %s\n%s\n\n<a href=\"%s\">Відкрити замовлення</a> → «Позначити оплаченим», коли кошти надійдуть.",
            $esc($order->getInvoiceNumber()),
            $order->getId(),
            $esc($payer['name'] ?? ''),
            strlen((string) ($payer['tax_id'] ?? '')) === 10 ? 'РНОКПП' : 'ЄДРПОУ',
            $esc($payer['tax_id'] ?? ''),
            $esc($payer['contact_person'] ?? ''),
            $esc($payer['phone'] ?? ''),
            $esc($payer['email'] ?? ''),
            $order->getTotalAmount(),
            $order->getInvoiceDueDate()?->format('d.m.Y') ?? '—',
            $emailed ? '✉️ PDF надіслано платнику на email.' : '⚠️ PDF НЕ надіслано на email — надішліть вручну з адмінки.',
            $adminOrderUrl,
        );

        $this->toManagers($text);
        $this->toGroup($text);
    }

    public function invoicePaid(UserOrder $order, string $paidBy, string $adminOrderUrl): void
    {
        $text = sprintf(
            "💰 <b>Оплата за рахунком № %s отримана — замовлення #%d</b>\nСума: %s грн\nПідтвердив: %s\n\n<a href=\"%s\">Відкрити замовлення</a>",
            TelegramNotifier::esc((string) $order->getInvoiceNumber()),
            $order->getId(),
            $order->getTotalAmount(),
            TelegramNotifier::esc($paidBy),
            $adminOrderUrl,
        );
        $this->toManagers($text);
        $this->toGroup($text);

        $buyerText = sprintf(
            "Оплату за рахунком № %s отримано ✅\nЗамовлення #%d передано в роботу.",
            TelegramNotifier::esc((string) $order->getInvoiceNumber()),
            $order->getId(),
        );
        $chatIds = array_values(array_unique(array_filter([
            $order->getTelegramUserId()?->getChatId(),
            $order->getClientUserId()?->getTelegramChatId(),
        ])));
        foreach ($chatIds as $chatId) {
            $this->send($buyerText, $chatId);
        }
    }

    private function toManagers(string $text): void
    {
        foreach ($this->telegramUserRepository->findByRole(RoleEnum::MANAGER) as $manager) {
            $this->send($text, $manager->getChatId());
        }
    }

    private function toGroup(string $text): void
    {
        try {
            $this->groupNotifier->send($text);
        } catch (\Throwable $e) {
            $this->logger->error('Invoice group notification failed', ['error' => $e->getMessage()]);
        }
    }

    private function send(string $text, int|string|null $chatId): void
    {
        if (!$chatId) {
            return;
        }
        try {
            $this->bot->sendMessage(text: $text, chat_id: $chatId, parse_mode: ParseMode::HTML);
        } catch (\Throwable $e) {
            $this->logger->error('Invoice Telegram notification failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
        }
    }
}
