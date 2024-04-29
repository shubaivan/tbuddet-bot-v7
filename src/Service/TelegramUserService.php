<?php

namespace App\Service;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;

class TelegramUserService
{
    private ?TelegramUser $currentUser;

    public function __construct(private TelegramUserRepository $telegramUserRepository) {}

    public function initUser(array $from)
    {
        $telegramUser = new TelegramUser();
        $this->currentUser = $this->telegramUserRepository->getByTelegramId($from['id']);
        if (!$this->currentUser) {
            $telegramUser->setTelegramId($from['id']);

            if (isset($from['first_name'])) {
                $telegramUser->setFirstName($from['first_name']);
            }

            if (isset($from['last_name'])) {
                $telegramUser->setLastName($from['last_name']);
            }

            if (isset($from['username'])) {
                $telegramUser->setUsername($from['username']);
            }

            if (isset($from['language_code'])) {
                $telegramUser->setLanguageCode($from['language_code']);
            }

            $this->telegramUserRepository->save($telegramUser);

            $this->currentUser = $telegramUser;
        }

        return $this->currentUser;
    }

    public function savePhone(string $phone_number): void
    {
        $this->currentUser->setPhoneNumber($phone_number);
    }

    public function getCurrentUser(): ?TelegramUser
    {
        return $this->currentUser;
    }
}