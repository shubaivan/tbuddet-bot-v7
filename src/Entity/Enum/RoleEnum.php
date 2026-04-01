<?php

namespace App\Entity\Enum;

enum RoleEnum: string
{
    case ADMIN = 'ROLE_ADMIN';
    case USER = 'ROLE_USER';
    case MANAGER = 'ROLE_MANAGER';
    case FUTURE = 'ROLE_FUTURE';
}
