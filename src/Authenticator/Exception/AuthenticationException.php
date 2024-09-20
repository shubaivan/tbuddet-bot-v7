<?php

namespace App\Authenticator\Exception;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

class AuthenticationException extends CustomUserMessageAuthenticationException
{
}
