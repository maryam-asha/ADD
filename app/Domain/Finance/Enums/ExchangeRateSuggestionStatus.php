<?php

namespace App\Domain\Finance\Enums;

enum ExchangeRateSuggestionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';
    case Superseded = 'superseded';
}
