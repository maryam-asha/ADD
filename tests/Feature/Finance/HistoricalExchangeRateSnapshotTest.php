<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\ExchangeRateSource;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalExchangeRateSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_transaction_or_booking_snapshots_amount_and_is_unaffected_by_future_exchange_rate_changes(): void
    {
        // 1. Initial exchange rate: 1 USD = 13,000 SYP
        $initialRate = ExchangeRate::create([
            'currency_code' => 'SYP',
            'rate_to_base' => number_format(1 / 13000, 10, '.', ''),
            'source' => ExchangeRateSource::Api,
            'effective_from' => now()->subDay(),
        ]);

        $member = User::factory()->create(['preferred_currency' => 'USD']);
        $space = Space::factory()->create([
            'hourly_rate_points' => 500,
        ]);

        // 2. Create a booking that snapshots price at this moment
        $booking = Booking::create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHours(2),
            'amount_owed' => '100',
            'currency' => 'USD',
            'status' => 'confirmed',
            'payment_state' => 'unpaid',
        ]);

        $this->assertEquals('100', (string) $booking->fresh()->amount_owed);
        $this->assertSame('USD', $booking->fresh()->currency);

        // 3. Exchange rate updates substantially the next day: 1 USD = 16,000 SYP
        ExchangeRate::create([
            'currency_code' => 'SYP',
            'rate_to_base' => number_format(1 / 16000, 10, '.', ''),
            'source' => ExchangeRateSource::Manual,
            'effective_from' => now(),
        ]);

        // 4. Assert that the past booking remains 100.00 USD and was NOT recalculated or altered
        $freshBooking = $booking->fresh();
        $this->assertEquals('100', (string) $freshBooking->amount_owed);
        $this->assertSame('USD', $freshBooking->currency);
    }
}
