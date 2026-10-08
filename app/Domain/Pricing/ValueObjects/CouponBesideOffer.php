<?php

namespace App\Domain\Pricing\ValueObjects;

/**
 * How a member's coupon sits beside the offer live now on what they are
 * looking at — "my coupons" asked about a space, a plan or an event.
 *
 * `offer` is the best offer for this member there now, or null, beside the
 * item's own price (`originalPoints`: a space's hourly rate). A plan or an
 * event has one price, so it is quoted with the coupon exactly as the
 * purchase would: `pricePoints` is what they would pay and `applied` whether
 * the code would be used. Time in a space is priced by its length, unknown
 * until a slot is picked, so both stay null there — coupons/preview answers
 * once it is.
 */
final class CouponBesideOffer
{
    /** Stacks on the offer. */
    public const STACKS = 'stacks';

    /** Does not stack, and the price is not known yet: the better of the two applies. */
    public const BETTER_OF_TWO = 'better_of_two';

    /** Does not stack, and beats the offer: the code is used instead. */
    public const CODE_IS_BETTER = 'code_is_better';

    /** Does not stack, and the offer is lower: the code would not be used. */
    public const OFFER_IS_BETTER = 'offer_is_better';

    public function __construct(
        public readonly ?OfferPrice $offer,
        public readonly int $originalPoints,
        public readonly bool $combines,
        public readonly ?int $pricePoints,
        public readonly ?bool $applied,
    ) {}

    /** Which of the four cases this is — null when there is no offer to sit beside. */
    public function note(): ?string
    {
        return match (true) {
            $this->offer === null => null,
            $this->combines => self::STACKS,
            $this->applied === null => self::BETTER_OF_TWO,
            $this->applied => self::CODE_IS_BETTER,
            default => self::OFFER_IS_BETTER,
        };
    }
}
