<?php

namespace App\Domain\Inventory\Adapters;

use Add\Inventory\Contracts\LocationResolver;
use Add\Inventory\Support\Place;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Floor;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\Zone;
use Illuminate\Database\Eloquent\Model;

/**
 * Inventory sits in ADD's own physical hierarchy: an asset in a space, a
 * shelf of paper on a floor or in a store room, stock counted per branch.
 * A space belongs to its building directly and, when zoned, to a zone of
 * one of its floors — so "within a building" takes its floors, their zones
 * and every space of the building, zoned or not.
 */
class AddLocationResolver implements LocationResolver
{
    public const BRANCH = 'branch';

    public const BUILDING = 'building';

    public const FLOOR = 'floor';

    public const ZONE = 'zone';

    public const SPACE = 'space';

    private const MODELS = [
        self::BRANCH => Branch::class,
        self::BUILDING => Building::class,
        self::FLOOR => Floor::class,
        self::ZONE => Zone::class,
        self::SPACE => Space::class,
    ];

    public function types(): array
    {
        return array_keys(self::MODELS);
    }

    public function exists(Place $place): bool
    {
        $model = self::MODELS[$place->type] ?? null;

        return $model !== null && $model::query()->whereKey($place->id)->exists();
    }

    public function describe(Place $place): ?array
    {
        $record = $this->find($place);

        if ($record === null) {
            return null;
        }

        [$label, $parents] = match (true) {
            $record instanceof Branch => [$record->translate('name'), []],
            $record instanceof Building => [$record->translate('name'), [$record->branch]],
            $record instanceof Floor => [$record->label, [$record->building?->branch, $record->building]],
            $record instanceof Zone => [$record->label, [$record->floor?->building?->branch, $record->floor?->building, $record->floor]],
            $record instanceof Space => [$record->displayTitle(), [$record->building?->branch, $record->building, $record->zone?->floor, $record->zone]],
        };

        return [
            ...$place->toArray(),
            'label' => $label,
            'path' => array_values(array_map(fn (Model $parent) => $this->labelOf($parent), array_filter($parents))),
        ];
    }

    public function within(Place $place): array
    {
        if (! $this->exists($place)) {
            return [];
        }

        $id = $place->id;

        $buildings = match ($place->type) {
            self::BRANCH => Building::query()->where('branch_id', $id)->pluck('id')->all(),
            self::BUILDING => [$id],
            default => [],
        };
        $floors = match ($place->type) {
            self::BRANCH, self::BUILDING => Floor::query()->whereIn('building_id', $buildings)->pluck('id')->all(),
            self::FLOOR => [$id],
            default => [],
        };
        $zones = match ($place->type) {
            self::BRANCH, self::BUILDING, self::FLOOR => Zone::query()->whereIn('floor_id', $floors)->pluck('id')->all(),
            self::ZONE => [$id],
            default => [],
        };
        $spaces = match ($place->type) {
            self::BRANCH, self::BUILDING => Space::query()->whereIn('building_id', $buildings)->pluck('id')->all(),
            self::FLOOR, self::ZONE => Space::query()->whereIn('zone_id', $zones)->pluck('id')->all(),
            self::SPACE => [$id],
        };

        return [
            ...($place->type === self::BRANCH ? [$place] : []),
            ...array_map(fn (int $id) => new Place(self::BUILDING, $id), $buildings),
            ...array_map(fn (int $id) => new Place(self::FLOOR, $id), $floors),
            ...array_map(fn (int $id) => new Place(self::ZONE, $id), $zones),
            ...array_map(fn (int $id) => new Place(self::SPACE, $id), $spaces),
        ];
    }

    private function find(Place $place): ?Model
    {
        $model = self::MODELS[$place->type] ?? null;

        return $model === null ? null : $model::query()->find($place->id);
    }

    private function labelOf(Model $record): ?string
    {
        return match (true) {
            $record instanceof Branch, $record instanceof Building => $record->translate('name'),
            $record instanceof Floor, $record instanceof Zone => $record->label,
            default => null,
        };
    }
}
