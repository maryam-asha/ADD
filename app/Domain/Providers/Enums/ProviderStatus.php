<?php

namespace App\Domain\Providers\Enums;

enum ProviderStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
