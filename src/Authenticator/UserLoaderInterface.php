<?php

namespace App\Authenticator;

use Symfony\Component\Security\Core\User\UserInterface;

interface UserLoaderInterface
{
    public function loadByTelegramId(string $id): ?UserInterface;
}
