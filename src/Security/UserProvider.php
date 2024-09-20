<?php

namespace App\Security;

use App\Entity\TelegramUser;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserProvider implements UserProviderInterface
{
    public function __construct(private UserManager $userManager)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if (!$user = $this->userManager->find($identifier)) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return $class === TelegramUser::class;
    }
}
