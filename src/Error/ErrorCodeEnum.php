<?php

namespace App\Error;

enum ErrorCodeEnum: string
{
    case MALFORMED_REQUEST_PAYLOAD_INVALID = '01925949-22cb-7bac-aa28-998b5af3f306';
    case PASSWORD_MISMATCH = '01920b8c-f803-7c50-a95a-58d852638b38';
}
