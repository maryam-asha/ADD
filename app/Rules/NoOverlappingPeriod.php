<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fails if [openTime, closeTime] (both inclusive) overlaps any of the
 * given existing periods. `closeTime` is the value being validated (this
 * rule is attached to the `close_time` field); `openTime` is the sibling
 * field's value, passed in via the constructor since ValidationRule only
 * sees the one attribute it's attached to. Two closed intervals
 * [a1,a2] and [b1,b2] overlap iff a1 <= b2 AND b1 <= a2 — with H:i
 * zero-padded strings, string comparison is equivalent to numeric
 * comparison for same-day times.
 *
 * The "which siblings" query (same branch+weekday, or same branch+date,
 * excluding the record being updated) is the caller's job — this rule
 * only does the interval-overlap math.
 */
class NoOverlappingPeriod implements ValidationRule
{
    /**
     * @param  iterable<array{open_time: string, close_time: string}>  $existingPeriods
     */
    public function __construct(
        private readonly iterable $existingPeriods,
        private readonly string $openTime,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach ($this->existingPeriods as $period) {
            if ($this->openTime <= $period['close_time'] && $period['open_time'] <= $value) {
                $fail('This time period overlaps an existing period.');

                return;
            }
        }
    }
}
