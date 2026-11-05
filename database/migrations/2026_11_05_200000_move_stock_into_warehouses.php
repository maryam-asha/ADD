<?php

use Add\Inventory\Support\Place;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Floor;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\Zone;
use App\Domain\Inventory\Adapters\AddLocationResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock moves from ADD's places into warehouses (inventory warehouses,
 * 2026-11-05). Every branch gets a primary warehouse, which takes the
 * stock counted on the branch itself; every other place with stock rows —
 * a building, a floor, a zone, a space — becomes a warehouse of its own in
 * its branch, not selling until staff say so, active only while it holds
 * something; rows of a place that no longer exists go to an "Unknown
 * place" warehouse. Only where each row points changes: quantities, costs,
 * dates, who and why stay as they were.
 *
 * The rows are re-pointed with plain updates, around the append-only
 * ledger model on purpose: nothing here writes a quantity.
 *
 * One way: take a database backup before deploying — it is the rollback.
 */
return new class extends Migration
{
    private const TABLES = ['inv_stock_movements', 'inv_stock_balances', 'item_sales'];

    public function up(): void
    {
        DB::transaction(function () {
            $primaries = $this->primaryWarehouses();
            $positive = DB::table('inv_stock_balances')
                ->where('location_type', '!=', 'warehouse')
                ->where('quantity', '>', 0)
                ->get(['location_type', 'location_id'])
                ->map(fn ($row) => $row->location_type.':'.$row->location_id)
                ->flip();

            foreach ($this->placesWithStock() as $place) {
                $warehouse = $place->type === AddLocationResolver::BRANCH && isset($primaries[$place->id])
                    ? $primaries[$place->id]
                    : $this->warehouseFor($place, $positive->has($place->key()));

                foreach (self::TABLES as $table) {
                    DB::table($table)
                        ->where('location_type', $place->type)
                        ->where('location_id', $place->id)
                        ->update(['location_type' => 'warehouse', 'location_id' => $warehouse]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Moving stock into warehouses is one way; restore the backup taken before deploying.');
    }

    /**
     * @return array<int, int> branch id => its primary warehouse id
     */
    private function primaryWarehouses(): array
    {
        $primaries = [];

        foreach (DB::table('branches')->orderBy('id')->get(['id', 'name']) as $branch) {
            $name = json_decode((string) $branch->name, true) ?: [];
            $primaries[$branch->id] = $this->insert([
                'en' => trim('Main warehouse – '.($name['en'] ?? $name['ar'] ?? '#'.$branch->id)),
                'ar' => trim('المستودع الرئيسي – '.($name['ar'] ?? $name['en'] ?? '#'.$branch->id)),
            ], $branch->id, isPrimary: true, allowsSales: true, isActive: true);
        }

        return $primaries;
    }

    /**
     * @return list<Place>
     */
    private function placesWithStock(): array
    {
        $places = [];

        foreach (self::TABLES as $table) {
            foreach (DB::table($table)->where('location_type', '!=', 'warehouse')->distinct()->get(['location_type', 'location_id']) as $row) {
                $place = new Place($row->location_type, (int) $row->location_id);
                $places[$place->key()] = $place;
            }
        }

        return array_values($places);
    }

    private function warehouseFor(Place $place, bool $holdsStock): int
    {
        $resolver = app(AddLocationResolver::class);
        $described = in_array($place->type, $resolver->types(), true) ? $resolver->describe($place) : null;
        $branch = $described === null ? null : $this->branchOf($place);

        if ($described === null || $branch === null) {
            $label = "Unknown place #{$place->type}:{$place->id}";

            return $this->insert(['en' => $label, 'ar' => "مكان غير معروف #{$place->type}:{$place->id}"], null, isPrimary: false, allowsSales: false, isActive: $holdsStock);
        }

        $label = (string) ($described['label'] ?? "#{$place->id}");

        return $this->insert(['en' => $label, 'ar' => $label], $branch, isPrimary: false, allowsSales: false, isActive: $holdsStock);
    }

    private function branchOf(Place $place): ?int
    {
        $branch = match ($place->type) {
            AddLocationResolver::BRANCH => $place->id,
            AddLocationResolver::BUILDING => Building::query()->whereKey($place->id)->value('branch_id'),
            AddLocationResolver::FLOOR => Floor::query()->find($place->id)?->building?->branch_id,
            AddLocationResolver::ZONE => Zone::query()->find($place->id)?->floor?->building?->branch_id,
            AddLocationResolver::SPACE => Space::query()->find($place->id)?->building?->branch_id,
            default => null,
        };

        return $branch === null ? null : (int) $branch;
    }

    /**
     * @param  array{en: string, ar: string}  $name
     */
    private function insert(array $name, ?int $branch, bool $isPrimary, bool $allowsSales, bool $isActive): int
    {
        return DB::table('inv_warehouses')->insertGetId([
            'name' => json_encode($name, JSON_UNESCAPED_UNICODE),
            'place_type' => $branch === null ? null : AddLocationResolver::BRANCH,
            'place_id' => $branch,
            'item_scope' => 'all',
            'allows_sales' => $allowsSales,
            'is_primary' => $isPrimary,
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
