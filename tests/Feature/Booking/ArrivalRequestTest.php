<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
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
}
