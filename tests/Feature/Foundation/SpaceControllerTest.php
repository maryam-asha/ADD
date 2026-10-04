<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\SpaceTypeAmenity;
use App\Domain\Foundation\Models\Zone;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SpaceControllerTest extends TestCase
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

    public function test_admin_can_create_a_space_and_status_defaults_to_active(): void
    {
        $this->actingAsAdmin();
        $building = Building::factory()->create();

        $response = $this->postJson('/api/v1/admin/spaces', [
            'building_id' => $building->id,
            'name' => ['ar' => 'غرفة الاجتماعات ب', 'en' => 'Meeting Room B'],
            'space_type' => 'room',
            'is_lockable' => true,
            'capacity' => 4,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'active');
        $response->assertJsonPath('data.name.en', 'Meeting Room B');
        $response->assertJsonPath('data.name.ar', 'غرفة الاجتماعات ب');
        $this->assertDatabaseHas('spaces', ['building_id' => $building->id, 'status' => 'active']);
    }

    /**
     * A co_space's capacity is the number of its desks, kept by the desks
     * themselves — it is never typed in (docs/decisions/coworking-seat-pool.md).
     */
    public function test_a_co_space_capacity_is_its_desks_not_a_typed_number(): void
    {
        $this->actingAsAdmin();
        $building = Building::factory()->create();
        $coSpace = fn (?int $capacity) => $this->postJson('/api/v1/admin/spaces', [
            'building_id' => $building->id,
            'name' => ['ar' => 'مساحة العمل', 'en' => 'Co-working'],
            'space_type' => 'co_space',
            'is_lockable' => false,
            'capacity' => $capacity,
        ]);

        $coSpace(16)->assertStatus(422)->assertJsonValidationErrors('capacity');
        $id = $coSpace(null)->assertCreated()->assertJsonPath('data.capacity', 0)->json('data.id');

        SeatDesk::factory()->count(3)->create(['space_id' => $id]);
        $this->assertSame(3, Space::find($id)->capacity);
    }

    /** A room turned into a co_space takes its desks' count, not its old seats. */
    public function test_a_space_turned_into_a_co_space_counts_its_desks(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create(['space_type' => 'room', 'capacity' => 12]);

        $this->putJson("/api/v1/admin/spaces/{$space->id}", [
            'building_id' => $space->building_id,
            'name' => ['ar' => 'مساحة', 'en' => 'Space'],
            'space_type' => 'co_space',
            'is_lockable' => false,
        ])->assertOk();

        $this->assertSame(0, $space->fresh()->capacity);
    }

    /**
     * A branch's co-working seats are the desks of its one co_space, so a
     * second one is refused — by creation or by turning a room into one.
     * Another branch may have its own.
     */
    public function test_a_branch_has_one_co_space_at_most(): void
    {
        $this->actingAsAdmin();
        $existing = Space::factory()->create();
        $sameBranch = Building::factory()->create(['branch_id' => $existing->building->branch_id]);
        $coSpaceIn = fn (Building $building) => $this->withHeader('lang', 'en')->postJson('/api/v1/admin/spaces', [
            'building_id' => $building->id,
            'name' => ['ar' => 'مساحة', 'en' => 'Co-working'],
            'space_type' => 'co_space',
            'is_lockable' => false,
        ]);

        $coSpaceIn($sameBranch)
            ->assertStatus(422)
            ->assertJsonPath('errors.space_type.0', 'This branch already has a co-working space; add desks to it instead.');
        $coSpaceIn(Building::factory()->create())->assertCreated();

        $room = Space::factory()->ofType(SpaceType::Room)->create(['building_id' => $sameBranch->id]);
        $this->putJson("/api/v1/admin/spaces/{$room->id}", [
            'building_id' => $sameBranch->id,
            'name' => ['ar' => 'غرفة', 'en' => 'Room'],
            'space_type' => 'co_space',
            'is_lockable' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('space_type');

        // The co_space itself is edited freely.
        $this->putJson("/api/v1/admin/spaces/{$existing->id}", [
            'building_id' => $existing->building_id,
            'name' => ['ar' => 'مساحة', 'en' => 'Co-working'],
            'space_type' => 'co_space',
            'is_lockable' => false,
        ])->assertOk();
    }

    public function test_index_can_be_filtered_by_building_id_and_zone_id(): void
    {
        $admin = $this->actingAsAdmin();
        $zone = Zone::factory()->create();
        $matching = Space::factory()->create([
            'building_id' => $zone->floor->building_id,
            'zone_id' => $zone->id,
        ]);
        Space::factory()->create();

        $response = $this->getJson("/api/v1/admin/spaces?building_id={$matching->building_id}&zone_id={$zone->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_index_can_be_paginated(): void
    {
        $this->actingAsAdmin();
        Space::factory()->count(8)->create();

        $response = $this->getJson('/api/v1/admin/spaces?per_page=3');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.total', 8);
        $response->assertJsonPath('meta.per_page', 3);
        $response->assertJsonPath('links.next', fn ($url) => str_contains($url, 'page=2'));
    }

    public function test_admin_can_update_structural_fields_without_touching_status(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->ofType(SpaceType::Room)->create(['capacity' => 4]);

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/spaces/{$space->id}", [
            'building_id' => $space->building_id,
            'zone_id' => $space->zone_id,
            'name' => ['ar' => 'قاعة تجريبية', 'en' => 'Test Room'],
            'space_type' => $space->space_type->value,
            'is_lockable' => $space->is_lockable,
            'capacity' => 10,
            'status' => 'retired',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Space updated.']);
        $this->assertSame(10, $space->fresh()->capacity);
        $this->assertSame('active', $space->fresh()->status->value);
    }

    public function test_admin_can_transition_space_status_and_it_is_logged(): void
    {
        $admin = $this->actingAsAdmin();
        $space = Space::factory()->create();

        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/spaces/{$space->id}/status", [
            'status' => 'maintenance',
            'status_reason' => 'Carpet replacement',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Space status updated.']);
        $this->assertSame('maintenance', $space->fresh()->status->value);

        $activity = Activity::where('description', 'space_status_changed')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame((int) $admin->id, (int) $activity->causer_id);
        $this->assertSame('active', $activity->properties['before']);
        $this->assertSame('maintenance', $activity->properties['after']);
    }

    public function test_admin_can_create_a_space_with_images_and_primary_image_is_first(): void
    {
        $this->actingAsAdmin();
        $building = Building::factory()->create();

        $response = $this->postJson('/api/v1/admin/spaces', [
            'building_id' => $building->id,
            'name' => ['ar' => 'غرفة الاجتماعات ب', 'en' => 'Meeting Room B'],
            'space_type' => 'room',
            'is_lockable' => true,
            'capacity' => 6,
            'images' => [
                'https://example.com/primary.jpg',
                'https://example.com/secondary.jpg',
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.main_image', 'https://example.com/primary.jpg');
        $response->assertJsonCount(2, 'data.images');
        $response->assertJsonPath('data.images.0', 'https://example.com/primary.jpg');
        $response->assertJsonPath('data.images.1', 'https://example.com/secondary.jpg');
    }

    public function test_admin_can_create_a_space_with_null_images(): void
    {
        $this->actingAsAdmin();
        $building = Building::factory()->create();

        $response = $this->postJson('/api/v1/admin/spaces', [
            'building_id' => $building->id,
            'name' => ['ar' => 'غرفة الاجتماعات ب', 'en' => 'Meeting Room B'],
            'space_type' => 'room',
            'is_lockable' => true,
            'capacity' => 4,
            'images' => null,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.main_image', null);
        $response->assertJsonPath('data.images', null);
    }

    public function test_admin_can_update_space_images(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create([
            'images' => ['https://example.com/old.jpg'],
        ]);

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/spaces/{$space->id}", [
            'building_id' => $space->building_id,
            'name' => ['ar' => 'قاعة تجريبية', 'en' => 'Test Room'],
            'space_type' => $space->space_type->value,
            'is_lockable' => $space->is_lockable,
            'images' => [
                'https://example.com/new-main.jpg',
                'https://example.com/new-second.jpg',
            ],
        ]);

        $response->assertOk();
        $this->assertSame('https://example.com/new-main.jpg', $space->fresh()->image_url);
        $this->assertCount(2, $space->fresh()->images);
    }

    public function test_admin_can_create_a_space_with_no_amenities_and_set_them_later(): void
    {
        $this->actingAsAdmin();
        $building = Building::factory()->create();

        $response = $this->postJson('/api/v1/admin/spaces', [
            'building_id' => $building->id,
            'name' => ['ar' => 'غرفة الاجتماعات ب', 'en' => 'Meeting Room B'],
            'space_type' => 'room',
            'is_lockable' => true,
            'capacity' => 4,
        ]);

        $response->assertCreated();
        $this->assertSame([], $response->json('data.amenities'));
    }

    public function test_admin_can_set_a_spaces_amenities_via_the_dedicated_action(): void
    {
        $this->actingAsAdmin();
        SpaceTypeAmenity::query()->create(['space_type' => 'room', 'amenity' => 'wifi']);
        SpaceTypeAmenity::query()->create(['space_type' => 'room', 'amenity' => 'ac']);
        $space = Space::factory()->room()->create();

        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/spaces/{$space->id}/amenities", [
            'amenities' => ['wifi', 'ac'],
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Space amenities updated.']);

        $this->assertDatabaseHas('space_amenities', ['space_id' => $space->id, 'amenity' => 'wifi']);
        $this->assertDatabaseHas('space_amenities', ['space_id' => $space->id, 'amenity' => 'ac']);

        $show = $this->getJson("/api/v1/admin/spaces/{$space->id}");
        $this->assertEqualsCanonicalizing(['wifi', 'ac'], $show->json('data.amenities'));
    }

    public function test_admin_can_clear_a_spaces_amenities_with_an_empty_array(): void
    {
        $this->actingAsAdmin();
        SpaceTypeAmenity::query()->create(['space_type' => 'room', 'amenity' => 'wifi']);
        $space = Space::factory()->room()->create();
        $space->amenities()->create(['amenity' => 'wifi']);

        $response = $this->patchJson("/api/v1/admin/spaces/{$space->id}/amenities", ['amenities' => []]);

        $response->assertOk();
        $this->assertDatabaseMissing('space_amenities', ['space_id' => $space->id, 'amenity' => 'wifi']);
    }

    public function test_setting_an_amenity_not_configured_for_the_spaces_type_is_rejected(): void
    {
        $this->actingAsAdmin();
        // Only wifi is configured for `room` — ac is not.
        SpaceTypeAmenity::query()->create(['space_type' => 'room', 'amenity' => 'wifi']);
        $space = Space::factory()->room()->create();

        $response = $this->patchJson("/api/v1/admin/spaces/{$space->id}/amenities", [
            'amenities' => ['wifi', 'ac'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('amenities');
        $this->assertDatabaseMissing('space_amenities', ['space_id' => $space->id]);
    }

    public function test_setting_an_invalid_amenity_value_is_rejected(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->room()->create();

        $response = $this->patchJson("/api/v1/admin/spaces/{$space->id}/amenities", [
            'amenities' => ['not-a-real-amenity'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('amenities.0');
    }

    public function test_a_rejected_amenities_update_leaves_existing_amenities_untouched(): void
    {
        $this->actingAsAdmin();
        SpaceTypeAmenity::query()->create(['space_type' => 'room', 'amenity' => 'wifi']);
        $space = Space::factory()->room()->create();
        $space->amenities()->create(['amenity' => 'wifi']);

        $response = $this->patchJson("/api/v1/admin/spaces/{$space->id}/amenities", [
            'amenities' => ['wifi', 'ac'],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('space_amenities', ['space_id' => $space->id, 'amenity' => 'wifi']);
        $this->assertDatabaseMissing('space_amenities', ['space_id' => $space->id, 'amenity' => 'ac']);
    }

    public function test_admin_can_delete_a_space(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create();

        $this->deleteJson("/api/v1/admin/spaces/{$space->id}")->assertNoContent();
        $this->assertDatabaseMissing('spaces', ['id' => $space->id]);
    }

    public function test_operations_cannot_delete_a_space(): void
    {
        $this->actingAsOperations();
        $space = Space::factory()->create();

        $this->deleteJson("/api/v1/admin/spaces/{$space->id}")->assertForbidden();
        $this->assertDatabaseHas('spaces', ['id' => $space->id]);
    }

    public function test_a_member_cannot_access_space_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/spaces')->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Booking rules set per space (docs/decisions/booking-outcomes-and-extension.md)
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function roomPayload(Building $building, array $overrides = []): array
    {
        return array_merge([
            'building_id' => $building->id,
            'name' => ['ar' => 'غرفة', 'en' => 'Room'],
            'space_type' => 'room',
            'is_lockable' => true,
            'capacity' => 4,
        ], $overrides);
    }

    public function test_a_space_sets_whether_bookings_extend_and_its_cancellation_window(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/spaces', $this->roomPayload(Building::factory()->create(), [
            'allows_extension' => false,
            'cancellation_window_minutes' => 120,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.allows_extension', false)
            ->assertJsonPath('data.cancellation_window_minutes', 120);
    }

    public function test_a_space_left_unset_allows_extension_and_falls_back_to_the_global_window(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/spaces', $this->roomPayload(Building::factory()->create()))
            ->assertCreated()
            ->assertJsonPath('data.allows_extension', true)
            ->assertJsonPath('data.cancellation_window_minutes', null);
    }

    public function test_an_update_can_turn_extension_off_and_clear_the_window(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->room()->create(['cancellation_window_minutes' => 90]);

        $this->putJson("/api/v1/admin/spaces/{$space->id}", $this->roomPayload($space->building, [
            'allows_extension' => false,
            'cancellation_window_minutes' => null,
        ]))->assertOk();

        $space->refresh();
        $this->assertFalse($space->allows_extension);
        $this->assertNull($space->cancellation_window_minutes);
    }

    public function test_a_negative_cancellation_window_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/spaces', $this->roomPayload(Building::factory()->create(), [
            'cancellation_window_minutes' => -5,
        ]))->assertUnprocessable()->assertJsonValidationErrors('cancellation_window_minutes');
    }
}
