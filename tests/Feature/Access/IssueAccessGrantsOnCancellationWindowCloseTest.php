<?php

namespace Tests\Feature\Access;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IssueAccessGrantsOnCancellationWindowCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * Final-review C2 — without a lower time bound, this command would
     * issue a passcode for every historical confirmed booking on first
     * deploy, and any that fail to issue would be retried forever (a
     * failed vendor call never creates the row whereDoesntHave checks
     * for). A booking whose end_at has already passed must never match,
     * regardless of how long ago it was confirmed.
     */
    public function test_a_confirmed_booking_whose_end_at_is_in_the_past_is_never_issued_a_grant(): void
    {
        $space = Space::factory()->create(['allocation_model' => AllocationModel::BookingHourly]);
        Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'status' => BookingStatus::Confirmed,
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDays(2)->addHour(),
        ]);

        $this->artisan('access:issue-grants-on-cancellation-window-close')->assertSuccessful();

        $this->assertSame(0, $booking->accessGrants()->count());
        $this->assertDatabaseMissing('access_grants', ['source_id' => $booking->id]);
    }
}
