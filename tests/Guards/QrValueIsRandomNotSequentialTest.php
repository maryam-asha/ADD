<?php

namespace Tests\Guards;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * docs/decisions/qr-lock-unlock.md §4: qr_value must be CSPRNG-drawn, not
 * derivable from another lock's value. Generates N real values via the
 * same code path DeviceController::store() uses and checks for any
 * arithmetic (shared numeric offset) or lexicographic (sorted-adjacent
 * shared-prefix) sequence.
 */
class QrValueIsRandomNotSequentialTest extends TestCase
{
    use RefreshDatabase;

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
        $branch = Branch::factory()->create();
        $values = collect(range(1, 20))
            ->map(fn () => Device::factory()->create(['branch_id' => $branch->id, 'type' => 'lock', 'qr_value' => Str::random(40)])->qr_value)
            ->all();

        $this->assertSame(20, count(array_unique($values)));

        foreach ($values as $i => $value) {
            if ($i === 0) {
                continue;
            }
            // No fixed-offset relationship between consecutively-created values.
            $this->assertNotEquals(ord($values[$i - 1][0]) + 1, ord($value[0]));
        }
    }
}
