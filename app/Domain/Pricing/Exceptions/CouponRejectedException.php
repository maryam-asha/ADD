<?php

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Pricing\Enums\CouponRejection;

/**
 * A coupon that cannot be used, and why. A ReceptionActionException, so
 * every endpoint that already turns one into a 422 with its translated
 * message does the same for a refused coupon.
 */
class CouponRejectedException extends ReceptionActionException
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(public readonly CouponRejection $rejection, array $params = [])
    {
        parent::__construct($rejection->messageKey(), 422, $params);
    }
}
