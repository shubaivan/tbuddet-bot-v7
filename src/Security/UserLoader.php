<?php

namespace App\Security;

use App\Authenticator\UserLoaderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserLoader implements UserLoaderInterface
{
    public function __construct(private UserManager $userManager)
    {
    }

    public function loadByTelegramId(string $id): ?UserInterface
    {
        return $this->userManager->find($id);
    }
}
