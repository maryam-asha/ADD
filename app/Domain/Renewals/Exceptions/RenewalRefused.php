<?php

namespace App\Domain\Renewals\Exceptions;

use App\Domain\Renewals\Enums\RenewalStopReason;
use RuntimeException;

/** A renewal that cannot happen now, for a reason stored on the subject. */
class RenewalRefused extends RuntimeException
{
    public function __construct(public readonly RenewalStopReason $reason)
    {
        parent::__construct($reason->value);
    }
}
