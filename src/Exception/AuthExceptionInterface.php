<?php

namespace App\Exception;

interface AuthExceptionInterface
{
    public function getAuthMessage(): string;
    public function getAuthCode(): string;
}
