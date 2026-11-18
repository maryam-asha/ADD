<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\SpaceTypeAmenity;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpaceTypeAmenityControllerTest extends TestCase
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

    public function test_index_returns_all_four_space_types_with_their_configured_amenities(): void
    {
        $this->actingAsAdmin();
        SpaceTypeAmenity::query()->create(['space_type' => 'co_space', 'amenity' => 'wifi']);
        SpaceTypeAmenity::query()->create(['space_type' => 'co_space', 'amenity' => 'locker']);

        $response = $this->getJson('/api/v1/admin/space-type-amenities');

        $response->assertOk();
        $response->assertJsonCount(4, 'data');

        $byType = collect($response->json('data'))->keyBy('space_type');

        $this->assertEqualsCanonicalizing(['wifi', 'locker'], $byType['co_space']['amenities']);
        // A type with zero configured amenities still appears, with an
        // empty array rather than being missing.
        $this->assertSame([], $byType['room']['amenities']);
        $this->assertSame([], $byType['business']['amenities']);
        $this->assertSame([], $byType['event_hall']['amenities']);
    }

    public function test_update_replaces_a_types_amenity_set_and_it_persists(): void
    {
        $this->actingAsAdmin();
        SpaceTypeAmenity::query()->create(['space_type' => 'room', 'amenity' => 'wifi']);

        $response = $this->withHeader('lang', 'en')->putJson('/api/v1/admin/space-type-amenities/room', [
            'amenities' => ['ac', 'cafe_access'],
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Amenities updated.']);

        $this->assertDatabaseMissing('space_type_amenities', ['space_type' => 'room', 'amenity' => 'wifi']);
        $this->assertDatabaseHas('space_type_amenities', ['space_type' => 'room', 'amenity' => 'ac']);
        $this->assertDatabaseHas('space_type_amenities', ['space_type' => 'room', 'amenity' => 'cafe_access']);

        $index = $this->getJson('/api/v1/admin/space-type-amenities');
        $byType = collect($index->json('data'))->keyBy('space_type');
        $this->assertEqualsCanonicalizing(['ac', 'cafe_access'], $byType['room']['amenities']);
    }

    public function test_update_can_clear_a_types_amenities_with_an_empty_array(): void
    {
        $this->actingAsAdmin();
        SpaceTypeAmenity::query()->create(['space_type' => 'business', 'amenity' => 'wifi']);

        $response = $this->putJson('/api/v1/admin/space-type-amenities/business', [
            'amenities' => [],
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('space_type_amenities', ['space_type' => 'business']);
    }

    public function test_update_rejects_an_invalid_amenity_value(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/v1/admin/space-type-amenities/room', [
            'amenities' => ['not-a-real-amenity'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('amenities.0');
    }

    public function test_update_with_an_unknown_space_type_is_not_found(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/v1/admin/space-type-amenities/not-a-real-type', [
            'amenities' => ['wifi'],
        ]);

        $response->assertNotFound();
    }

    public function test_operations_can_also_manage_the_matrix(): void
    {
        $this->actingAsOperations();

        $response = $this->putJson('/api/v1/admin/space-type-amenities/event_hall', [
            'amenities' => ['stage'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('space_type_amenities', ['space_type' => 'event_hall', 'amenity' => 'stage']);
    }

    public function test_a_member_cannot_access_space_type_amenity_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/space-type-amenities')->assertForbidden();
    }
}
