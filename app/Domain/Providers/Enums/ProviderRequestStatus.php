<?php

namespace App\Domain\Providers\Enums;

enum ProviderRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
