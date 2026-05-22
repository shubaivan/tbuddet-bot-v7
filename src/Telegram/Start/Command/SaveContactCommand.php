<?php

namespace App\Telegram\Start\Command;

use App\Service\TelegramUserService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;

/**
 * Saves a bot user's phone number when they share their contact via the
 * request_contact button shown by {@see StartCommand::promptForPhone()}.
 *
 * Registered globally with $bot->onContact() so it works outside any
 * conversation. Conversations that ask for a contact themselves (e.g. the
 * checkout flow) handle the update before this global handler runs.
 */
class SaveContactCommand
{
    public function __construct(
        private TelegramUserService $telegramUserService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $chatId = $bot->chatId();

        try {
            $contact = $bot->message()?->contact;
            $tgUser = $this->telegramUserService->getCurrentUser();
            if ($contact === null || $tgUser === null || $chatId === null) {
                return;
            }

            // Accept only the user's OWN contact, never a forwarded one.
            if ($contact->user_id !== null
                && $bot->userId() !== null
                && (int) $contact->user_id !== (int) $bot->userId()) {
                return;
            }

            if (!$tgUser->getPhoneNumber()) {
                $this->telegramUserService->savePhone($contact->phone_number);
                $this->em->flush();
            }

            $lang = $tgUser->getPreferredLanguage() ?? 'ua';
            $bot->sendMessage(
                text: $lang === 'ua'
                    ? '✅ Дякуємо! Ваш номер телефону збережено.'
                    : '✅ Thank you! Your phone number is saved.',
                chat_id: $chatId,
                reply_markup: ReplyKeyboardRemove::make(true),
            );
        } catch (\Throwable $e) {
            $this->logger->error('SaveContactCommand failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }
}
