<?php

namespace App\Domain\Finance\Enums;

enum ExchangeRateSource: string
{
    case Manual = 'manual';
    case ExternalAccepted = 'external_accepted';
}
