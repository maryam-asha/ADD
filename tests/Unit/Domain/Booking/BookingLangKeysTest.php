<?php

namespace Tests\Unit\Domain\Booking;

use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

class BookingLangKeysTest extends TestCase
{
    /** @return list<string> */
    public static function keys(): array
    {
        return [
            'invalid_start_time',
            'duration_too_short',
            'duration_invalid_granularity',
            'slot_unavailable',
            'buffer_conflict',
            'wallet_choice_required',
            'not_pending',
            'rejection_reason_required',
            'approved',
            'rejected',
            'invalid_extension_duration',
            'extension_conflict',
            'extended',
            'wallet_not_owned',
        ];
    }

    public function test_every_booking_key_exists_in_english(): void
    {
        foreach (self::keys() as $key) {
            $this->assertTrue(Lang::has("api.booking.{$key}", 'en'), "Missing lang/en/api.php booking.{$key}");
        }
    }

    public function test_every_booking_key_exists_in_arabic(): void
    {
        foreach (self::keys() as $key) {
            $this->assertTrue(Lang::has("api.booking.{$key}", 'ar'), "Missing lang/ar/api.php booking.{$key}");
        }
    }
}
