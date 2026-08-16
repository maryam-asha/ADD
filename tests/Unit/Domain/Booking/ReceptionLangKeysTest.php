<?php

namespace Tests\Unit\Domain\Booking;

use Tests\TestCase;

class ReceptionLangKeysTest extends TestCase
{
    /** @return list<string> */
    public static function keys(): array
    {
        return [
            'checked_in',
            'checked_out',
            'cancelled',
            'payment_settled',
            'already_checked_in',
            'already_checked_out',
            'already_cancelled',
            'already_paid',
            'outside_business_hours',
            'no_capacity',
            'not_checked_in',
            'checkout_before_checkin',
            'checkout_past_closing',
            'not_yet_checked_out',
            'cancellation_window_passed',
        ];
    }

    public function test_every_reception_key_exists_in_english(): void
    {
        foreach (self::keys() as $key) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Lang::has("api.reception.{$key}", 'en'),
                "Missing lang/en/api.php reception.{$key}"
            );
        }
    }

    public function test_every_reception_key_exists_in_arabic(): void
    {
        foreach (self::keys() as $key) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Lang::has("api.reception.{$key}", 'ar'),
                "Missing lang/ar/api.php reception.{$key}"
            );
        }
    }
}
