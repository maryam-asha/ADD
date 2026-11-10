<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Kitchen\Enums\QrPointPlace;
use App\Domain\Kitchen\Models\QrPoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QrPoint>
 */
class QrPointFactory extends Factory
{
    protected $model = QrPoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $branch = Branch::factory()->create();
        $space = Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $branch->id])->id,
        ]);

        return [
            'branch_id' => $branch->id,
            'place_type' => QrPointPlace::SeatDesk,
            'place_id' => SeatDesk::factory()->create(['space_id' => $space->id])->id,
            'qr_value' => Str::random(40),
        ];
    }

    /** A point on a space (a room) rather than a desk. */
    public function space(): static
    {
        return $this->state(function () {
            $branch = Branch::factory()->create();
            $space = Space::factory()->room()->create([
                'building_id' => Building::factory()->create(['branch_id' => $branch->id])->id,
                'name' => ['en' => 'Room', 'ar' => 'غرفة'],
            ]);

            return [
                'branch_id' => $branch->id,
                'place_type' => QrPointPlace::Space,
                'place_id' => $space->id,
            ];
        });
    }
}
