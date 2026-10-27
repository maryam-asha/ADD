<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * The member's past coworking sessions (GET member/coworking-sessions) and
 * one session in full (GET member/coworking-sessions/{type}/{id}): walk-ins
 * and checked-in bookings on a coworking space, one list.
 */
class MemberCoworkingSessionHistoryTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private Space $space;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->space = $this->coworkingSpace(hourlyRatePoints: 100);
        $this->member = $this->actingAsMember();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Damascus')->utc();
    }

    /** A paid two-hour walk-in on 10 August, 08:00–10:00. */
    private function paidWalkin(?User $member = null, ?Space $space = null): WalkinSession
    {
        return WalkinSession::factory()->create([
            'space_id' => ($space ?? $this->space)->id,
            'user_id' => ($member ?? $this->member)->id,
            'checked_in_at' => $this->at('2026-08-10 08:00'),
            'checked_out_at' => $this->at('2026-08-10 10:00'),
            'price_points' => 200,
            'amount_owed' => '20',
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
            'paid_at' => $this->at('2026-08-10 10:00'),
        ]);
    }

    /** An unpaid coworking booking on 12 August, 09:00–11:00, checked in and out. */
    private function unpaidBooking(?Space $space = null): Booking
    {
        return Booking::factory()->create([
            'space_id' => ($space ?? $this->space)->id,
            'user_id' => $this->member->id,
            'start_at' => $this->at('2026-08-12 09:00'),
            'end_at' => $this->at('2026-08-12 11:00'),
            'checked_in_at' => $this->at('2026-08-12 09:00'),
            'checked_out_at' => $this->at('2026-08-12 11:00'),
            'status' => BookingStatus::Completed,
            'price_points' => 200,
            'amount_owed' => '20',
        ]);
    }

    private function list(array $query = []): TestResponse
    {
        return $this->withHeader('lang', 'en')->getJson('/api/v1/member/coworking-sessions?'.http_build_query($query));
    }

    public function test_the_list_holds_the_members_ended_sessions_newest_first(): void
    {
        $walkin = $this->paidWalkin();
        $booking = $this->unpaidBooking();

        // None of these is a past coworking session of this member's.
        WalkinSession::factory()->create(['space_id' => $this->space->id, 'user_id' => $this->member->id, 'checked_in_at' => $this->at('2026-08-17 08:00')]);
        $this->paidWalkin(User::factory()->create());
        $this->unpaidBooking(Space::factory()->room()->create());
        Booking::factory()->create(['space_id' => $this->space->id, 'user_id' => $this->member->id]);

        $this->list()
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'booking')
            ->assertJsonPath('data.0.id', $booking->id)
            ->assertJsonPath('data.1.type', 'walkin')
            ->assertJsonPath('data.1.id', $walkin->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.payment_state', null);
    }

    public function test_a_card_shows_the_space_the_time_and_whether_it_is_paid(): void
    {
        $this->paidWalkin();

        $this->list()
            ->assertOk()
            ->assertJsonPath('data.0.space.id', $this->space->id)
            ->assertJsonPath('data.0.space.name', $this->space->translate('name'))
            ->assertJsonPath('data.0.date_label', 'August 10, 2026')
            ->assertJsonPath('data.0.duration_seconds', 7200)
            ->assertJsonPath('data.0.checked_out_at', $this->at('2026-08-10 10:00')->toIso8601String())
            ->assertJsonPath('data.0.payment.state', 'paid')
            ->assertJsonPath('data.0.payment.amount_points', 200)
            ->assertJsonPath('data.0.discounts', []);
    }

    public function test_the_list_pages_and_filters_by_payment_state(): void
    {
        $this->paidWalkin();
        $booking = $this->unpaidBooking();

        $this->list(['per_page' => 1])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.last_page', 2);

        $this->list(['payment_state' => 'unpaid'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $booking->id)
            ->assertJsonPath('data.0.payment.state', 'unpaid')
            ->assertJsonPath('meta.payment_state', 'unpaid');

        $this->list(['payment_state' => 'refunded'])->assertUnprocessable()->assertJsonValidationErrors('payment_state');
    }

    public function test_the_detail_shows_the_report_and_every_attempt_to_pay(): void
    {
        $walkin = $this->paidWalkin();

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/coworking-sessions/walkin/{$walkin->id}")
            ->assertOk()
            ->assertJsonPath('data.type', 'walkin')
            ->assertJsonPath('data.id', $walkin->id)
            ->assertJsonPath('data.reference', $walkin->reference)
            ->assertJsonPath('data.date_label', 'August 10, 2026')
            ->assertJsonPath('data.duration_seconds', 7200)
            ->assertJsonPath('data.payment.state', 'paid')
            ->assertJsonPath('data.totals.payment_state', 'paid')
            ->assertJsonPath('data.lines.0.kind', 'time')
            ->assertJsonPath('data.payments', [])
            ->assertJsonStructure(['data' => ['started_via', 'termination_source', 'coverage', 'plan', 'discounts']]);
    }

    public function test_a_booking_session_has_its_detail_too(): void
    {
        $booking = $this->unpaidBooking();

        $this->getJson("/api/v1/member/coworking-sessions/booking/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.type', 'booking')
            ->assertJsonPath('data.payment.state', 'unpaid');
    }

    /** Not the member's, never checked in, or not a session type: its existence is not theirs to learn. */
    public function test_a_session_that_is_not_the_members_is_not_found(): void
    {
        $other = $this->paidWalkin(User::factory()->create());
        $neverStarted = Booking::factory()->create(['space_id' => $this->space->id, 'user_id' => $this->member->id]);

        $this->getJson("/api/v1/member/coworking-sessions/walkin/{$other->id}")->assertNotFound();
        $this->getJson("/api/v1/member/coworking-sessions/booking/{$neverStarted->id}")->assertNotFound();
        $this->getJson('/api/v1/member/coworking-sessions/event/1')->assertNotFound();
    }
}
