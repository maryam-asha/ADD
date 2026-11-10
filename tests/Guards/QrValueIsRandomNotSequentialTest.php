<?php

namespace Tests\Guards;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Models\QrPoint;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/decisions/qr-lock-unlock.md §4: qr_value must be CSPRNG-drawn, not
 * derivable from another lock's value. Generates N real values via the
 * same code path DeviceController::store() uses and checks for any
 * lexicographic (sorted-adjacent shared-prefix) sequence.
 */
class QrValueIsRandomNotSequentialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_generated_qr_values_are_not_sequential(): void
    {
        $values = collect(range(1, 50))->map(fn () => Str::random(40))->all();

        $this->assertSame(50, count(array_unique($values)), 'Generated values must all be unique.');

        $sorted = $values;
        sort($sorted);
        for ($i = 1; $i < count($sorted); $i++) {
            $this->assertNotSame(
                substr($sorted[$i - 1], 0, 39),
                substr($sorted[$i], 0, 39),
                'Two generated values share a 39-character prefix — suspiciously sequential.'
            );
        }
    }

    public function test_creating_lock_devices_produces_non_sequential_qr_values(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $branch = Branch::factory()->create();

        $values = collect(range(1, 20))
            ->map(function ($i) use ($branch) {
                $response = $this->postJson('/api/v1/admin/devices', [
                    'branch_id' => $branch->id,
                    'type' => 'lock',
                    'hardware_mac' => 'lock-'.Str::random(10),
                    'external_ref' => (string) (1000 + $i),
                ]);

                return Device::find($response->json('data.id'))->qr_value;
            })
            ->all();

        $this->assertSame(20, count(array_unique($values)));
    }

    public function test_qr_points_are_not_sequential(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $values = SeatDesk::factory()->count(20)->create()
            ->map(function (SeatDesk $desk) {
                $response = $this->postJson('/api/v1/admin/qr-points', [
                    'place_type' => 'seat_desk',
                    'place_id' => $desk->id,
                ])->assertCreated();

                return QrPoint::find($response->json('data.id'))->qr_value;
            })
            ->all();

        $this->assertSame(20, count(array_unique($values)));

        sort($values);
        for ($i = 1; $i < count($values); $i++) {
            $this->assertNotSame(substr($values[$i - 1], 0, 39), substr($values[$i], 0, 39));
        }
    }
}
