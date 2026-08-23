<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArrivalRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    private function spaceInBranch(Branch $branch): Space
    {
        $building = Building::factory()->for($branch)->create();

        return Space::factory()->room()->for($building)->create(['hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
    }

    public function test_creating_an_arrival_request_matches_todays_confirmed_booking(): void
    {
        $branch = Branch::factory()->create();
        $member = $this->actingAsMember();
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $this->spaceInBranch($branch)->id,
            'start_at' => Carbon::parse('2026-08-23 14:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 15:00:00', 'Asia/Damascus'),
        ]);

        $response = $this->postJson('/api/v1/member/arrival-requests');

        $response->assertCreated();
        $response->assertJsonPath('data.matched_booking_id', $booking->id);
        $this->assertSame(1, ArrivalRequest::where('user_id', $member->id)->count());
    }

    public function test_creating_an_arrival_request_with_no_matching_booking_leaves_it_unmatched(): void
    {
        Branch::factory()->create();
        $this->actingAsMember();

        $response = $this->postJson('/api/v1/member/arrival-requests');

        $response->assertCreated();
        $response->assertJsonPath('data.matched_booking_id', null);
    }

    public function test_a_non_member_cannot_create_an_arrival_request(): void
    {
        $this->actingAsOperations();

        $this->postJson('/api/v1/member/arrival-requests')->assertForbidden();
    }

    public function test_operations_can_list_pending_arrival_requests(): void
    {
        $this->actingAsOperations();
        $branch = Branch::factory()->create();
        $pending = ArrivalRequest::factory()->create(['requested_at' => now()->subMinutes(5)]);
        ArrivalRequest::factory()->confirmed()->create();

        $response = $this->getJson('/api/v1/admin/reception/arrival-requests');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $pending->id);
    }

    public function test_confirming_a_matched_arrival_request_checks_in_the_booking(): void
    {
        $operator = $this->actingAsOperations();
        $branch = Branch::factory()->create();
        $space = $this->spaceInBranch($branch);
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Sunday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);
        $booking = Booking::factory()->create(['space_id' => $space->id]);
        $arrivalRequest = ArrivalRequest::factory()->create(['matched_booking_id' => $booking->id]);

        $response = $this->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/confirm");

        $response->assertOk();
        $this->assertNotNull($booking->fresh()->checked_in_at);
        $arrivalRequest->refresh();
        $this->assertSame(ArrivalRequestStatus::Confirmed, $arrivalRequest->status);
        $this->assertSame($operator->id, $arrivalRequest->confirmed_by_user_id);
        $this->assertSame($space->id, $arrivalRequest->confirmed_space_id);
    }

    public function test_confirming_a_matched_request_still_enforces_check_in_guards(): void
    {
        $this->actingAsOperations();
        $branch = Branch::factory()->create();
        $space = $this->spaceInBranch($branch);
        $booking = Booking::factory()->checkedIn()->create(['space_id' => $space->id]);
        $arrivalRequest = ArrivalRequest::factory()->create(['matched_booking_id' => $booking->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/confirm");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking has already been checked in.']);
        $this->assertSame(ArrivalRequestStatus::Pending, $arrivalRequest->fresh()->status);
    }

    public function test_confirming_an_unmatched_request_without_space_id_is_rejected(): void
    {
        $this->actingAsOperations();
        $arrivalRequest = ArrivalRequest::factory()->create();

        $response = $this->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/confirm");

        $response->assertStatus(422);
        $this->assertSame(ArrivalRequestStatus::Pending, $arrivalRequest->fresh()->status);
    }

    public function test_confirming_an_unmatched_request_with_space_id_creates_a_walk_in_session(): void
    {
        $operator = $this->actingAsOperations();
        $branch = Branch::factory()->create();
        $space = $this->spaceInBranch($branch);
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Sunday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);
        $arrivalRequest = ArrivalRequest::factory()->create();

        $response = $this->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/confirm", [
            'space_id' => $space->id,
        ]);

        $response->assertOk();
        $this->assertSame(1, WalkinSession::where('space_id', $space->id)->where('user_id', $arrivalRequest->user_id)->count());
        $arrivalRequest->refresh();
        $this->assertSame(ArrivalRequestStatus::Confirmed, $arrivalRequest->status);
        $this->assertSame($operator->id, $arrivalRequest->confirmed_by_user_id);
        $this->assertSame($space->id, $arrivalRequest->confirmed_space_id);
    }

    public function test_confirming_a_non_pending_request_is_conflict(): void
    {
        $this->actingAsOperations();
        $arrivalRequest = ArrivalRequest::factory()->rejected()->create();

        $this->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/confirm")
            ->assertStatus(409);
    }

    public function test_operations_can_reject_a_pending_arrival_request(): void
    {
        $this->actingAsOperations();
        $arrivalRequest = ArrivalRequest::factory()->create();

        $response = $this->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/reject");

        $response->assertOk();
        $this->assertSame(ArrivalRequestStatus::Rejected, $arrivalRequest->fresh()->status);
    }

    public function test_rejecting_a_non_pending_request_is_conflict(): void
    {
        $this->actingAsOperations();
        $arrivalRequest = ArrivalRequest::factory()->confirmed()->create();

        $this->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/reject")
            ->assertStatus(409);
    }
}
