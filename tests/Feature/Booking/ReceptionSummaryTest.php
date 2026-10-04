<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The reception desk's counts and "up next" list come from real rows, by the
 * same rules as the screens they link to.
 */
class ReceptionSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, SettingSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time, string $date = '2026-08-17'): Carbon
    {
        return Carbon::parse("{$date} {$time}", 'Asia/Damascus')->utc();
    }

    public function test_the_desk_sees_its_day_from_real_rows(): void
    {
        // Inside now: one booking, one walk-in — one of them asking to end.
        Booking::factory()->create(['start_at' => $this->at('09:00'), 'end_at' => $this->at('12:00'), 'checked_in_at' => $this->at('09:00')]);
        $walkIn = WalkinSession::factory()->create(['checked_in_at' => $this->at('08:00')]);
        $walkIn->endRequests()->create(['user_id' => $walkIn->user_id, 'requested_at' => $this->at('09:50'), 'status' => SessionEndRequestStatus::Pending, 'payment_method' => 'cash']);

        // Expected today, not yet arrived; tomorrow's and a cancelled one are not.
        $expected = Booking::factory()->create(['start_at' => $this->at('14:00'), 'end_at' => $this->at('15:00')]);
        Booking::factory()->create(['start_at' => $this->at('14:00', '2026-08-18'), 'end_at' => $this->at('15:00', '2026-08-18')]);
        Booking::factory()->create(['start_at' => $this->at('16:00'), 'end_at' => $this->at('17:00'), 'status' => BookingStatus::Cancelled]);

        // Waiting at the desk.
        ArrivalRequest::factory()->create(['requested_at' => $this->at('09:55'), 'status' => ArrivalRequestStatus::Pending]);

        // Owed: a closed walk-in nobody has paid for.
        WalkinSession::factory()->create([
            'checked_in_at' => $this->at('07:00'),
            'checked_out_at' => $this->at('08:00'),
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 100,
        ]);

        $response = $this->withHeader('lang', 'en')->getJson('/api/v1/admin/reception/summary')->assertOk();

        $response->assertJsonPath('data.stats.active_sessions', 2)
            ->assertJsonPath('data.stats.expected_bookings', 1)
            ->assertJsonPath('data.stats.pending_arrivals', 1)
            ->assertJsonPath('data.stats.pending_end_requests', 1)
            ->assertJsonPath('data.stats.pending_payments', 1);

        // Soonest first: the end request, the arrival, then the booking.
        $this->assertSame(['end_request', 'arrival', 'booking'], array_column($response->json('data.upcoming'), 'kind'));
        $response->assertJsonPath('data.upcoming.0.session', ['type' => 'walkin', 'id' => $walkIn->id])
            ->assertJsonPath('data.upcoming.0.payment_method', 'cash')
            ->assertJsonPath('data.upcoming.2.id', $expected->id)
            ->assertJsonPath('data.upcoming.2.at', '2026-08-17T14:00:00+03:00');
    }
}
