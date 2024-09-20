<?php

namespace App\Security;

use App\Authenticator\UserFactoryInterface;
use App\Entity\TelegramUser;
use Symfony\Component\Security\Core\User\UserInterface;

class UserFactory implements UserFactoryInterface
{
    public function __construct(private UserManager $userManager)
    {
    }

    public function createFromTelegram(array $data): UserInterface
    {
        $user = new TelegramUser();

        if (isset($data['id'])) {
            $user->setTelegramId($data['id']);
        }

        if (isset($data['first_name'])) {
            $user->setFirstName($data['first_name']);
        }

        if (isset($data['last_name'])) {
            $user->setLastName($data['last_name']);
        }

        if (isset($data['username'])) {
            $user->setUsername($data['username']);
        }

        if (isset($data['auth_date'])) {
            $user->setAuthDate((int)$data['auth_date']);
        }

        $this->userManager->save($user);

        return $user;
    }
}
