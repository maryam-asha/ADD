<?php

namespace App\Domain\Identity\Enums;

enum CompanyStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
