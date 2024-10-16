<?php

namespace App\Exception\Enum;

use App\Exception\RegistrationException;

enum AuthExceptionEnum: string
{
    case REGISTRATION_EXCEPTION = RegistrationException::class;
}
