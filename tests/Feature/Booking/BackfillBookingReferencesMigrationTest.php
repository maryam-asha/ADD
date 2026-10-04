<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bookings made before they had numbers get theirs in the order they were
 * made, one series per year, and the next new booking carries on from there.
 */
class BackfillBookingReferencesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_04_100000_add_reference_to_bookings_table.php');
    }

    private function insertBookingMadeAt(string $createdAt): int
    {
        return DB::table('bookings')->insertGetId([
            'space_id' => Space::factory()->room()->create()->id,
            'user_id' => User::factory()->create()->id,
            'start_at' => '2026-12-01 10:00:00',
            'end_at' => '2026-12-01 11:00:00',
            'status' => 'confirmed',
            'payment_state' => 'unpaid',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    public function test_existing_bookings_are_numbered_in_the_order_they_were_made_per_year(): void
    {
        $this->migration()->down();
        $later = $this->insertBookingMadeAt('2026-03-01 09:00:00');
        $earlier = $this->insertBookingMadeAt('2026-01-05 09:00:00');
        $lastYear = $this->insertBookingMadeAt('2025-12-31 23:00:00');

        $this->migration()->up();

        $this->assertSame('BK-2026-00001', DB::table('bookings')->where('id', $earlier)->value('reference'));
        $this->assertSame('BK-2026-00002', DB::table('bookings')->where('id', $later)->value('reference'));
        $this->assertSame('BK-2025-00001', DB::table('bookings')->where('id', $lastYear)->value('reference'));
    }

    public function test_the_next_booking_carries_on_from_the_backfilled_series(): void
    {
        $this->migration()->down();
        $this->insertBookingMadeAt(now()->format('Y').'-01-05 09:00:00');

        $this->migration()->up();

        $this->assertSame('BK-'.now()->format('Y').'-00002', Booking::factory()->create()->reference);
    }
}
