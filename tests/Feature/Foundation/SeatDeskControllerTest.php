<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Building;
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

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    public function test_admin_can_create_a_seat_desk(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create();

        $response = $this->postJson('/api/v1/admin/seats-desks', [
            'space_id' => $space->id,
            'code' => 'D-12',
            'label' => 'Window desk',
        ]);

        $response->assertCreated()->assertJsonPath('data.code', 'D-12');
        $this->assertDatabaseHas('seats_desks', ['space_id' => $space->id, 'code' => 'D-12', 'label' => 'Window desk']);
    }

    /** The code is what the QR and the member see, so a branch never repeats it. */
    public function test_a_code_is_unique_within_the_branch(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create();
        $sameBranch = Space::factory()->create(['building_id' => Building::factory()->create(['branch_id' => $space->building->branch_id])->id]);
        SeatDesk::factory()->create(['space_id' => $space->id, 'code' => 'D-01']);

        $this->postJson('/api/v1/admin/seats-desks', ['space_id' => $sameBranch->id, 'code' => 'D-01', 'label' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/admin/seats-desks', ['space_id' => Space::factory()->create()->id, 'code' => 'D-01', 'label' => 'x'])
            ->assertCreated();
    }

    public function test_a_desk_keeps_its_own_code_on_update(): void
    {
        $this->actingAsAdmin();
        $desk = SeatDesk::factory()->create(['code' => 'D-05']);

        $this->putJson("/api/v1/admin/seats-desks/{$desk->id}", [
            'space_id' => $desk->space_id,
            'code' => 'D-05',
            'label' => 'Renamed',
        ])->assertOk();
    }

    /** A co_space's seats in the pool are its desks: adding or removing one moves the count. */
    public function test_the_co_space_capacity_follows_its_desks(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create(['capacity' => 0]);
        $other = Space::factory()->create(['capacity' => 0]);

        $id = $this->postJson('/api/v1/admin/seats-desks', ['space_id' => $space->id, 'code' => 'D-1', 'label' => 'a'])->json('data.id');
        $this->postJson('/api/v1/admin/seats-desks', ['space_id' => $space->id, 'code' => 'D-2', 'label' => 'b']);
        $this->assertSame(2, $space->fresh()->capacity);

        $this->putJson("/api/v1/admin/seats-desks/{$id}", ['space_id' => $other->id, 'code' => 'D-1', 'label' => 'a'])->assertOk();
        $this->assertSame(1, $space->fresh()->capacity);
        $this->assertSame(1, $other->fresh()->capacity);

        $this->deleteJson("/api/v1/admin/seats-desks/{$id}")->assertNoContent();
        $this->assertSame(0, $other->fresh()->capacity);
    }

    public function test_admin_cannot_create_a_seat_desk_inside_a_non_co_space(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create(['space_type' => 'room']);

        $response = $this->postJson('/api/v1/admin/seats-desks', [
            'space_id' => $space->id,
            'code' => 'D-13',
            'label' => 'D-13',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('space_id');
        $this->assertDatabaseMissing('seats_desks', ['space_id' => $space->id]);
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
            'code' => 'D-99',
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

    public function test_operations_cannot_delete_a_seat_desk(): void
    {
        $this->actingAsOperations();
        $seatDesk = SeatDesk::factory()->create();

        $this->deleteJson("/api/v1/admin/seats-desks/{$seatDesk->id}")->assertForbidden();
        $this->assertDatabaseHas('seats_desks', ['id' => $seatDesk->id]);
    }

    public function test_a_member_cannot_access_seat_desk_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/seats-desks')->assertForbidden();
    }
}
