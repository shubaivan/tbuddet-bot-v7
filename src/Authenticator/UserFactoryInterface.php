<?php

namespace App\Authenticator;

use Symfony\Component\Security\Core\User\UserInterface;

interface UserFactoryInterface
{
    /**
     * @param array $data contains id, first_name, last_name, username, photo_url, auth_date and hash fields
     */
    public function createFromTelegram(array $data): UserInterface;
}
