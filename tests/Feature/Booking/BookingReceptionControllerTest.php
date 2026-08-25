<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class BookingReceptionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    private function openSpace(): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_operations_can_check_in_a_confirmed_booking(): void
    {
        $operator = $this->actingAsOperations();
        $booking = Booking::factory()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in");

        $response->assertOk()->assertExactJson(['message' => 'Checked in.']);
        $this->assertNotNull($booking->fresh()->checked_in_at);
        $activity = Activity::where('description', 'booking_checked_in')->latest('id')->first();
        $this->assertSame($operator->id, $activity->causer_id);
    }

    public function test_check_in_fails_if_already_checked_in(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->checkedIn()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking has already been checked in.']);
    }

    public function test_check_in_fails_outside_business_hours(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->create(['space_id' => $this->openSpace()->id]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 23:00:00', 'Asia/Damascus'));

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in");

        $response->assertStatus(422)->assertExactJson(['message' => 'This action is not available outside business hours.']);
    }

    public function test_check_in_fails_if_booking_is_cancelled(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->cancelled()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking has already been cancelled.']);
    }

    public function test_check_in_fails_if_booking_is_pending(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->pending()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking must be approved before it can be checked in.']);
        $this->assertNull($booking->fresh()->checked_in_at);
    }

    public function test_check_in_fails_if_booking_is_rejected(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->create([
            'space_id' => $this->openSpace()->id,
            'status' => BookingStatus::Rejected,
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking was rejected and cannot be checked in.']);
        $this->assertNull($booking->fresh()->checked_in_at);
    }

    public function test_check_in_on_a_nonexistent_booking_is_404(): void
    {
        $this->actingAsOperations();

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/bookings/99999/check-in')->assertNotFound();
    }

    public function test_operations_can_check_out_a_checked_in_booking(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-out", [
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->toIso8601String(),
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Checked out.']);
        $this->assertSame('20.00', (string) $booking->fresh()->amount_owed);
    }

    public function test_check_out_fails_if_entered_time_is_before_check_in(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-out", [
            'checked_out_at' => Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->toIso8601String(),
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => 'The checkout time cannot be before the check-in time.']);
    }

    public function test_check_out_fails_if_entered_time_is_past_closing(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-17 21:00:00', 'Asia/Damascus'));

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-out", [
            'checked_out_at' => Carbon::parse('2026-08-17 20:01:00', 'Asia/Damascus')->toIso8601String(),
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => "The checkout time cannot be after the branch's closing time."]);
    }

    public function test_check_out_fails_if_already_checked_out(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus')->setTimezone('UTC'),
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-out", [
            'checked_out_at' => Carbon::parse('2026-08-17 09:30:00', 'Asia/Damascus')->toIso8601String(),
        ]);

        $response->assertStatus(409)->assertExactJson(['message' => 'This session has already been checked out.']);
    }

    public function test_operations_can_settle_payment_after_checkout(): void
    {
        $operator = $this->actingAsOperations();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus')->setTimezone('UTC'),
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->setTimezone('UTC'),
            'amount_owed' => '10.00',
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/settle-payment", [
            'payment_method' => 'sham',
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Payment settled.']);
        $booking->refresh();
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame($operator->id, $booking->paid_by);
    }

    public function test_settle_payment_fails_if_already_paid(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_out_at' => now(),
            'amount_owed' => '10.00',
            'payment_state' => PaymentState::Paid,
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/settle-payment", [
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking or session has already been paid.']);
    }

    public function test_settle_payment_fails_if_not_yet_checked_out(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->checkedIn()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/settle-payment", [
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => 'This booking or session must be checked out before payment can be settled.']);
    }

    public function test_settle_payment_rejects_an_invalid_payment_method(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $this->openSpace()->id,
            'checked_out_at' => now(),
            'amount_owed' => '10.00',
        ]);

        $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/settle-payment", ['payment_method' => 'visa'])
            ->assertStatus(422);
    }

    public function test_operations_can_cancel_a_confirmed_booking_within_the_window(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->create([
            'space_id' => $this->openSpace()->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/cancel");

        $response->assertOk()->assertExactJson(['message' => 'Booking cancelled.']);
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancel_fails_past_the_window(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->create([
            'space_id' => $this->openSpace()->id,
            'start_at' => now()->addMinutes(30),
            'end_at' => now()->addHours(1),
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/cancel");

        $response->assertStatus(422)->assertExactJson(['message' => 'This booking is past its cancellation window.']);
    }

    public function test_cancel_fails_if_already_checked_in(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $this->openSpace()->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/cancel");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking has already been checked in.']);
    }

    public function test_cancel_fails_if_already_cancelled(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->cancelled()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/cancel");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking has already been cancelled.']);
    }

    public function test_pending_approval_lists_only_pending_bookings_ordered_by_start_at_desc(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $earlier = Booking::factory()->pending()->create([
            'space_id' => $space->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
        ]);
        $later = Booking::factory()->pending()->create([
            'space_id' => $space->id,
            'start_at' => now()->addDays(5),
            'end_at' => now()->addDays(5)->addHour(),
        ]);
        Booking::factory()->create(['space_id' => $space->id]);
        Booking::factory()->cancelled()->create(['space_id' => $space->id]);
        Booking::factory()->rejected()->create(['space_id' => $space->id]);

        $response = $this->getJson('/api/v1/admin/reception/bookings/pending-approval');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $later->id);
        $response->assertJsonPath('data.1.id', $earlier->id);
        $response->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_pending_approval_is_paginated(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        Booking::factory()->pending()->count(26)->create(['space_id' => $space->id]);

        $response = $this->getJson('/api/v1/admin/reception/bookings/pending-approval');

        $response->assertOk();
        $response->assertJsonCount(25, 'data');
        $this->assertSame(26, $response->json('meta.total'));
    }

    public function test_a_member_cannot_access_reception_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $booking = Booking::factory()->create(['space_id' => $this->openSpace()->id]);

        $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in")->assertForbidden();
    }
}
