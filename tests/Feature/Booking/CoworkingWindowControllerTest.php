<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SpaceCoworkingWindow;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Membership;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Staff lend a room to co-working while the co-working space is overloaded:
 * its seats join the branch's pool for a stretch, and it takes no room
 * bookings meanwhile (docs/decisions/coworking-seat-pool.md).
 */
class CoworkingWindowControllerTest extends TestCase
{
    use RefreshDatabase;

    private Space $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00', 'Asia/Damascus'));
        $this->room = Space::factory()->room()->create(['capacity' => 8]);

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lend(array $overrides = [])
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/admin/spaces/{$this->room->id}/coworking-windows", [
            'starts_at' => '2026-10-01 14:00',
            'ends_at' => '2026-10-01 18:00',
            'seats' => 6,
            ...$overrides,
        ]);
    }

    private function local(string $time): Carbon
    {
        return Carbon::parse("2026-10-01 {$time}", 'Asia/Damascus')->utc();
    }

    public function test_staff_lend_a_room_to_co_working_in_local_time(): void
    {
        $response = $this->lend()->assertCreated();

        $window = SpaceCoworkingWindow::query()->sole();
        $this->assertTrue($window->starts_at->equalTo($this->local('14:00')));
        $this->assertTrue($window->ends_at->equalTo($this->local('18:00')));
        $this->assertSame(6, $window->seats);
        $response->assertJsonPath('data.id', $window->id)
            ->assertJsonPath('data.seats', 6)
            ->assertJsonPath('data.starts_at', '2026-10-01T14:00:00+03:00');

        $this->withHeader('lang', 'en')->getJson("/api/v1/admin/spaces/{$this->room->id}/coworking-windows")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_room_with_a_booking_in_the_stretch_cannot_be_lent(): void
    {
        Booking::factory()->create(['space_id' => $this->room->id, 'start_at' => $this->local('15:00'), 'end_at' => $this->local('16:00')]);

        $this->lend()->assertStatus(422)->assertJsonPath('message', __('api.coworking.room_has_bookings', [], 'en'));
    }

    public function test_only_a_room_is_lent_and_for_no_more_seats_than_it_has(): void
    {
        $coSpace = Space::factory()->create();

        $this->withHeader('lang', 'en')->postJson("/api/v1/admin/spaces/{$coSpace->id}/coworking-windows", [
            'starts_at' => '2026-10-01 14:00', 'ends_at' => '2026-10-01 18:00', 'seats' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('space');

        $this->lend(['seats' => 9])->assertStatus(422)->assertJsonValidationErrors('seats');
        $this->lend(['ends_at' => '2026-10-01 13:00'])->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    public function test_two_stretches_of_one_room_do_not_overlap(): void
    {
        $this->lend()->assertCreated();

        $this->lend(['starts_at' => '2026-10-01 17:00', 'ends_at' => '2026-10-01 19:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_a_stretch_is_updated_and_deleted_with_a_message(): void
    {
        $id = $this->lend()->json('data.id');

        $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/coworking-windows/{$id}", ['seats' => 4])
            ->assertOk()
            ->assertExactJson(['message' => __('api.coworking.window_updated', [], 'en')]);
        $this->assertSame(4, SpaceCoworkingWindow::query()->sole()->seats);

        $this->withHeader('lang', 'en')->deleteJson("/api/v1/admin/coworking-windows/{$id}")
            ->assertOk()
            ->assertExactJson(['message' => __('api.coworking.window_deleted', [], 'en')]);
        $this->assertSame(0, SpaceCoworkingWindow::query()->count());
    }

    /**
     * Taking the lent seats back when subscriptions already count on them
     * would overbook the pool: refused, unless staff say so.
     */
    public function test_seats_already_counted_on_are_not_taken_back_without_an_override(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 15:00', 'Asia/Damascus'));
        $window = SpaceCoworkingWindow::factory()->create([
            'space_id' => $this->room->id, 'starts_at' => $this->local('14:00'), 'ends_at' => $this->local('18:00'), 'seats' => 2,
        ]);
        Membership::factory()->count(2)->create(['branch_id' => $this->room->building->branch_id]);

        $this->withHeader('lang', 'en')->deleteJson("/api/v1/admin/coworking-windows/{$window->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.coworking.would_overbook', [], 'en'));

        $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/coworking-windows/{$window->id}", ['seats' => 1])
            ->assertStatus(422);

        $this->withHeader('lang', 'en')->deleteJson("/api/v1/admin/coworking-windows/{$window->id}?override=1")
            ->assertOk();
    }

    public function test_a_member_cannot_lend_rooms(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->lend()->assertForbidden();
    }
}
