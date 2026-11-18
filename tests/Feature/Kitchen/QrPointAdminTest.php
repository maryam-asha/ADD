<?php

namespace Tests\Feature\Kitchen;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Models\QrPoint;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A stored random code per desk or room, made by staff; the app's scan
 * resolves it.
 */
class QrPointAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_store_creates_a_random_value_for_a_desk(): void
    {
        $desk = SeatDesk::factory()->create();

        $response = $this->postJson('/api/v1/admin/qr-points', ['place_type' => 'seat_desk', 'place_id' => $desk->id]);

        $response->assertCreated();
        $scan = $response->json('data.scan_value');
        $this->assertStringStartsWith('add-place:v1:', $scan);
        $this->assertSame(40, strlen(substr($scan, strlen('add-place:v1:'))));
        $this->assertSame($desk->label, $response->json('data.place_label'));
        $this->assertSame($desk->id, QrPoint::fromScan($scan)->place_id);
    }

    public function test_a_desks_qr_point_is_its_ordering_code(): void
    {
        $desk = SeatDesk::factory()->create();

        $listed = fn () => collect($this->getJson('/api/v1/admin/seats-desks')->assertOk()->json('data'))->firstWhere('id', $desk->id);
        $this->assertNull($listed()['qr_scan_value']);

        $point = $this->postJson('/api/v1/admin/qr-points', ['place_type' => 'seat_desk', 'place_id' => $desk->id])
            ->assertCreated();

        $this->assertSame($point->json('data.id'), $desk->fresh()->qr_point_id);
        $this->assertSame($point->json('data.scan_value'), $listed()['qr_scan_value']);

        $this->deleteJson('/api/v1/admin/qr-points/'.$point->json('data.id'))->assertNoContent();

        $this->assertNull($desk->fresh()->qr_point_id);
        $this->assertNull($listed()['qr_scan_value']);
    }

    public function test_store_is_idempotent_per_place(): void
    {
        $desk = SeatDesk::factory()->create();
        $body = ['place_type' => 'seat_desk', 'place_id' => $desk->id];

        $first = $this->postJson('/api/v1/admin/qr-points', $body)->assertCreated();
        $second = $this->postJson('/api/v1/admin/qr-points', $body)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.scan_value'), $second->json('data.scan_value'));
        $this->assertSame(1, QrPoint::query()->count());
    }

    public function test_branch_comes_from_the_place(): void
    {
        $branch = Branch::factory()->create();
        $building = Building::factory()->create(['branch_id' => $branch->id]);
        $space = Space::factory()->room()->create([
            'building_id' => $building->id,
            'name' => ['en' => 'Board room', 'ar' => 'غرفة الاجتماعات'],
        ]);
        $desk = SeatDesk::factory()->create(['space_id' => $space->id]);

        $this->postJson('/api/v1/admin/qr-points', ['place_type' => 'seat_desk', 'place_id' => $desk->id])
            ->assertCreated()->assertJsonPath('data.branch_id', $branch->id);
        $this->postJson('/api/v1/admin/qr-points', ['place_type' => 'space', 'place_id' => $space->id])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.place_label', $space->translate('name'));
    }

    public function test_unknown_place_is_422(): void
    {
        $this->postJson('/api/v1/admin/qr-points', ['place_type' => 'seat_desk', 'place_id' => 999999])
            ->assertUnprocessable();
        $this->postJson('/api/v1/admin/qr-points', ['place_type' => 'kitchen', 'place_id' => 1])
            ->assertUnprocessable();
    }

    public function test_index_filters_and_destroy(): void
    {
        $a = QrPoint::factory()->create();
        QrPoint::factory()->space()->create();

        $this->getJson('/api/v1/admin/qr-points')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/qr-points?place_type=space')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/qr-points?branch_id='.$a->branch_id)->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson('/api/v1/admin/qr-points/'.$a->id)->assertNoContent();
        $this->assertSame(1, QrPoint::query()->count());
    }

    public function test_requires_qr_points_create(): void
    {
        $member = User::factory()->create();
        Sanctum::actingAs($member, ['*']);
        $desk = SeatDesk::factory()->create();

        $this->postJson('/api/v1/admin/qr-points', ['place_type' => 'seat_desk', 'place_id' => $desk->id])
            ->assertForbidden();
    }
}
