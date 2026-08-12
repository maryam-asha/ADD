<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeatDeskControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_admin_can_create_a_seat_desk(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create();

        $response = $this->postJson('/api/v1/admin/seats-desks', [
            'space_id' => $space->id,
            'label' => 'D-12',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('seats_desks', ['space_id' => $space->id, 'label' => 'D-12']);
    }

    public function test_index_can_be_filtered_by_space_id(): void
    {
        $this->actingAsAdmin();
        $spaceA = Space::factory()->create();
        $spaceB = Space::factory()->create();
        SeatDesk::factory()->for($spaceA)->create();
        SeatDesk::factory()->for($spaceB)->create();

        $response = $this->getJson("/api/v1/admin/seats-desks?space_id={$spaceA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_update_a_seat_desk_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $seatDesk = SeatDesk::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/seats-desks/{$seatDesk->id}", [
            'space_id' => $seatDesk->space_id,
            'label' => 'D-99',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Seat/desk updated.']);
        $this->assertSame('D-99', $seatDesk->fresh()->label);
    }

    public function test_admin_can_delete_a_seat_desk(): void
    {
        $this->actingAsAdmin();
        $seatDesk = SeatDesk::factory()->create();

        $this->deleteJson("/api/v1/admin/seats-desks/{$seatDesk->id}")->assertNoContent();
        $this->assertDatabaseMissing('seats_desks', ['id' => $seatDesk->id]);
    }

    public function test_a_member_cannot_access_seat_desk_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/seats-desks')->assertForbidden();
    }
}
