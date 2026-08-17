<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalkInSessionControllerTest extends TestCase
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

    private function openSpace(?int $capacity = 2): Space
    {
        $space = Space::factory()->room()->create(['capacity' => $capacity, 'hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_operations_can_start_a_walk_in_session(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $member = User::factory()->create();

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $space->id,
            'user_id' => $member->id,
        ]);

        $response->assertCreated();
        $this->assertSame(1, WalkinSession::where('space_id', $space->id)->count());
    }

    public function test_starting_a_walk_in_fails_when_the_space_is_at_capacity(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace(1);
        WalkinSession::factory()->create(['space_id' => $space->id]);

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $space->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => 'This space has no available capacity right now.']);
    }

    public function test_starting_a_walk_in_fails_outside_business_hours(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        Carbon::setTestNow(Carbon::parse('2026-08-17 23:00:00', 'Asia/Damascus'));

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $space->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => 'This action is not available outside business hours.']);
    }

    public function test_starting_a_walk_in_requires_a_valid_space_and_user(): void
    {
        $this->actingAsOperations();

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/walk-ins', ['space_id' => 99999, 'user_id' => 99999])
            ->assertStatus(422);
    }

    public function test_operations_can_check_out_a_walk_in_session(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/walk-ins/{$session->id}/check-out", [
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->toIso8601String(),
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Checked out.']);
        $this->assertSame('20.00', (string) $session->fresh()->amount_owed);
    }

    public function test_check_out_fails_if_the_walk_in_session_was_never_checked_in(): void
    {
        // Not reachable via factory (checked_in_at is required at creation),
        // but exercised directly against the service in
        // SessionClosureServiceTest; this controller test instead covers
        // the already-checked-out failure mode, which IS reachable via the
        // HTTP surface.
        $this->actingAsOperations();
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus')->setTimezone('UTC'),
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/walk-ins/{$session->id}/check-out", [
            'checked_out_at' => Carbon::parse('2026-08-17 09:30:00', 'Asia/Damascus')->toIso8601String(),
        ]);

        $response->assertStatus(409)->assertExactJson(['message' => 'This session has already been checked out.']);
    }

    public function test_operations_can_settle_payment_for_a_walk_in_session(): void
    {
        $operator = $this->actingAsOperations();
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_out_at' => now(),
            'amount_owed' => '10.00',
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/walk-ins/{$session->id}/settle-payment", [
            'payment_method' => 'mtn',
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Payment settled.']);
        $session->refresh();
        $this->assertSame(PaymentState::Paid, $session->payment_state);
        $this->assertSame($operator->id, $session->paid_by);
    }

    public function test_settle_payment_fails_if_not_yet_checked_out(): void
    {
        $this->actingAsOperations();
        $session = WalkinSession::factory()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/walk-ins/{$session->id}/settle-payment", [
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => 'This booking or session must be checked out before payment can be settled.']);
    }

    public function test_a_member_cannot_start_a_walk_in(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $this->openSpace()->id,
            'user_id' => $member->id,
        ])->assertForbidden();
    }
}
