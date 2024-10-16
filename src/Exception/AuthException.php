<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthException extends HttpException implements AuthExceptionInterface
{
    public function __construct(int $statusCode, string $message = '', ?\Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct($statusCode, $message, $previous, $headers, $code);
    }

    public function getAuthMessage(): string
    {
        return '';
    }

    public function getAuthCode(): string
    {
        return '';
    }
}
