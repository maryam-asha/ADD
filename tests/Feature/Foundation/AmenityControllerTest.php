<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Amenity;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\SpaceTypeAmenity;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AmenityControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    public function test_the_catalog_starts_with_the_eight_former_enum_values(): void
    {
        $this->actingAsOperations();

        $response = $this->getJson('/api/v1/admin/amenities')->assertOk();

        $this->assertSame(
            ['wifi', 'power_outlet', 'printing', 'ergonomic_chairs', 'locker', 'cafe_access', 'ac', 'stage'],
            array_column($response->json('data'), 'key'),
        );
        $this->assertSame(['en' => 'Wi-Fi', 'ar' => 'واي فاي'], $response->json('data.0.name'));
        $this->assertSame('wifi', $response->json('data.0.icon'));
    }

    public function test_store_derives_a_unique_key_from_the_english_name(): void
    {
        $this->actingAsOperations();
        Amenity::factory()->create(['key' => 'podcast_studio']);

        $this->postJson('/api/v1/admin/amenities', [
            'name' => ['en' => 'Podcast Studio', 'ar' => 'استوديو بودكاست'],
            'icon' => 'mic',
        ])->assertCreated()->assertJsonPath('data.key', 'podcast_studio_2')->assertJsonPath('data.is_active', true);
    }

    public function test_a_new_amenity_is_selectable_for_a_space_type_and_a_space(): void
    {
        $this->actingAsOperations();
        $key = $this->postJson('/api/v1/admin/amenities', [
            'key' => 'parking',
            'name' => ['en' => 'Parking', 'ar' => 'موقف سيارات'],
        ])->assertCreated()->json('data.key');

        $this->putJson('/api/v1/admin/space-type-amenities/room', ['amenities' => [$key]])->assertOk();

        $space = Space::factory()->create(['space_type' => 'room']);
        $this->patchJson("/api/v1/admin/spaces/{$space->id}/amenities", ['amenities' => [$key]])->assertOk();

        $this->assertDatabaseHas('space_amenities', ['space_id' => $space->id, 'amenity' => 'parking']);
    }

    public function test_update_never_changes_the_key(): void
    {
        $this->actingAsOperations();
        $amenity = Amenity::query()->where('key', 'wifi')->firstOrFail();

        $this->putJson("/api/v1/admin/amenities/{$amenity->id}", [
            'key' => 'internet',
            'name' => ['en' => 'Fast Wi-Fi', 'ar' => 'واي فاي سريع'],
        ])->assertOk()->assertJsonPath('message', __('api.admin.amenity_updated'));

        $amenity->refresh();
        $this->assertSame('wifi', $amenity->key);
        $this->assertSame('Fast Wi-Fi', $amenity->name['en']);
    }

    public function test_an_inactive_amenity_cannot_be_selected(): void
    {
        $this->actingAsOperations();
        Amenity::query()->where('key', 'locker')->update(['is_active' => false]);

        $this->putJson('/api/v1/admin/space-type-amenities/room', ['amenities' => ['locker']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amenities.0');
    }

    public function test_deleting_an_amenity_turns_it_off_everywhere(): void
    {
        $this->actingAsOperations();
        SpaceTypeAmenity::query()->create(['space_type' => 'room', 'amenity' => 'stage']);
        $space = Space::factory()->create(['space_type' => 'room']);
        $space->amenities()->create(['amenity' => 'stage']);
        $amenity = Amenity::query()->where('key', 'stage')->firstOrFail();

        $this->deleteJson("/api/v1/admin/amenities/{$amenity->id}")->assertNoContent();

        $this->assertDatabaseMissing('space_type_amenities', ['amenity' => 'stage']);
        $this->assertDatabaseMissing('space_amenities', ['amenity' => 'stage']);
    }

    public function test_a_member_cannot_manage_amenities(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/amenities')->assertForbidden();
    }
}
