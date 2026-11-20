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
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

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

    public function invoiceIssued(
        UserOrder $order,
        string $adminOrderUrl,
        bool $emailed,
        ?string $publicPdfUrl = null,
        bool $sentToBuyerTelegram = false,
    ): void
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
            $esc(($payer['email'] ?? '') !== '' ? $payer['email'] : 'email не вказано'),
            $order->getTotalAmount(),
            $order->getInvoiceDueDate()?->format('d.m.Y') ?? '—',
            self::deliveryNote($emailed, $sentToBuyerTelegram, (string) ($payer['email'] ?? '')),
            $adminOrderUrl,
        );

        $text = str_replace("\nСума: ", "\n💳 Оплата: <b>за рахунком</b>\nСума: ", $text);
        if ($order->getDeliveryAmount() > 0) {
            $text = str_replace("\nСума: ", "\n🚚 ".$esc($order->getDeliverySummary())."\nСума: ", $text);
        }
        if (null !== $publicPdfUrl) {
            $text .= "\n📄 Рахунок (PDF): ".$esc($publicPdfUrl);
        }

        $this->toManagers($text);
        $this->toGroup($text);
    }

    /** Manager-facing line: how the buyer got the PDF (or that they still need it). */
    public static function deliveryNote(bool $emailed, bool $sentToBuyerTelegram, string $payerEmail): string
    {
        if ($emailed) {
            return '✉️ PDF надіслано платнику на email.';
        }
        if ($sentToBuyerTelegram) {
            return '📨 PDF надіслано покупцю в Telegram (email не вказано).';
        }
        if ($payerEmail === '') {
            return 'ℹ️ Email не вказано — PDF доступний покупцю на сторінці замовлення та в кабінеті.';
        }

        return '⚠️ PDF НЕ надіслано на email — надішліть вручну з адмінки.';
    }

    /**
     * Sends the invoice PDF to the buyer's Telegram (the customer bot) — used when
     * no email was given. Returns true when at least one chat received it.
     */
    public function sendPdfToBuyer(UserOrder $order, string $pdf, ?string $publicPdfUrl = null): bool
    {
        $chatIds = self::buyerChatIds($order);
        if ($chatIds === []) {
            return false;
        }

        $caption = sprintf(
            "🧾 Рахунок на оплату № %s — замовлення #%d\nСума: %s грн, сплатити до %s.\nУ призначенні платежу вкажіть: «Оплата згідно рахунку № %s».",
            TelegramNotifier::esc((string) $order->getInvoiceNumber()),
            $order->getId(),
            number_format((float) $order->getTotalAmount(), 2, ',', ' '),
            $order->getInvoiceDueDate()?->format('d.m.Y') ?? '—',
            TelegramNotifier::esc((string) $order->getInvoiceNumber()),
        );

        $sent = false;
        foreach ($chatIds as $chatId) {
            $stream = fopen('php://temp', 'r+b');
            if ($stream === false) {
                break;
            }
            fwrite($stream, $pdf);
            rewind($stream);
            try {
                $this->bot->sendDocument(
                    document: InputFile::make($stream, sprintf('Rahunok-%s.pdf', $order->getInvoiceNumber())),
                    chat_id: $chatId,
                    caption: $caption,
                    parse_mode: ParseMode::HTML,
                );
                $sent = true;
            } catch (\Throwable $e) {
                $this->logger->error('Invoice PDF to buyer Telegram failed', [
                    'order' => $order->getId(),
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
                if (null !== $publicPdfUrl) {
                    // The document upload failed — still give the buyer the link.
                    $this->send($caption."\n📄 ".TelegramNotifier::esc($publicPdfUrl), $chatId);
                }
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        return $sent;
    }

    /** @return list<int|string> */
    public static function buyerChatIds(UserOrder $order): array
    {
        return array_values(array_unique(array_filter([
            $order->getTelegramUserId()?->getChatId(),
            $order->getClientUserId()?->getTelegramChatId(),
        ])));
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
        foreach (self::buyerChatIds($order) as $chatId) {
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
