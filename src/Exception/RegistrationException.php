<?php

namespace App\Exception;

use App\Exception\Enum\AuthCodeEnum;

class RegistrationException extends AuthException
{
    private string $authMessage = 'User registration error';
    private string $authCode = AuthCodeEnum::USER_REGISTRATION_ERROR->value;

    public function __construct(int $statusCode, string $message = '', ?\Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct($statusCode, $message, $previous, $headers, $code);
    }

    public function getAuthMessage(): string
    {
        return $this->authMessage;
    }

    public function getAuthCode(): string
    {
        return $this->authCode;
    }
}
