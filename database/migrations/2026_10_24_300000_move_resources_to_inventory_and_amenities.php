<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * `resources` mixed two things. What a space offers without being a
     * thing to track (rows of category `other`: Wi-Fi, café access, a stage)
     * becomes an amenity turned on for the space — the catalog one it names,
     * or a new catalog entry — and allowed for its space type. Equipment
     * (projector, mic, screen, whiteboard) becomes inventory assets: one
     * item per name under a "Presentation equipment" category shown to
     * members, one asset per unit of its `quantity`, in its space.
     *
     * A row with no space was "untracked general inventory": its equipment
     * goes to the first branch, noted for someone to place; an amenity with
     * no space has nowhere to be turned on and is dropped, as is an inactive
     * one (members never saw it). An asset `in_maintenance` gets the open
     * maintenance record that status always comes with.
     *
     * Raw queries on purpose: a migration must not depend on models that
     * later change. One-way — the database backup taken before deploying is
     * the rollback (2026_10_24_300100 drops `resources`).
     */
    public function up(): void
    {
        if (! Schema::hasTable('resources')) {
            return;
        }

        $now = now();

        foreach (DB::table('resources')->orderBy('id')->get() as $row) {
            if ($row->category === 'other') {
                $this->toAmenity($row, $now);
            } else {
                $this->toAssets($row, $now);
            }
        }
    }

    public function down(): void
    {
        //
    }

    private function toAmenity(object $row, $now): void
    {
        if ($row->space_id === null || $row->status !== 'active') {
            return;
        }

        $key = $this->amenityKeyFor($row->name, $now);

        if ($key === null) {
            return;
        }

        $spaceType = DB::table('spaces')->where('id', $row->space_id)->value('space_type');

        DB::table('space_amenities')->insertOrIgnore(['space_id' => $row->space_id, 'amenity' => $key, 'created_at' => $now, 'updated_at' => $now]);

        if ($spaceType !== null) {
            DB::table('space_type_amenities')->insertOrIgnore(['space_type' => $spaceType, 'amenity' => $key, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * The catalog amenity a free-text name means — the same guesses the
     * member browse used to make for these rows — or a new one. Null for a
     * smart lock: that is the space's own `is_lockable`, already shown.
     */
    private function amenityKeyFor(string $name, $now): ?string
    {
        $lower = Str::lower($name);

        if (Str::contains($lower, 'lock') && ! Str::contains($lower, 'locker')) {
            return null;
        }
        $guesses = [
            'wifi' => ['wi-fi', 'wifi'],
            'power_outlet' => ['power', 'outlet'],
            'cafe_access' => ['café', 'cafe'],
            'ac' => ['a/c', 'air conditioning'],
            'stage' => ['stage'],
            'locker' => ['locker'],
            'printing' => ['print'],
            'ergonomic_chairs' => ['ergonomic'],
        ];

        foreach ($guesses as $key => $needles) {
            if (Str::contains($lower, $needles) && DB::table('amenities')->where('key', $key)->exists()) {
                return $key;
            }
        }

        $base = Str::limit(Str::slug($name, '_'), 32, '') ?: 'amenity';
        $key = $base;

        for ($i = 2; DB::table('amenities')->where('key', $key)->exists(); $i++) {
            $key = $base.'_'.$i;
        }

        DB::table('amenities')->insert([
            'key' => $key,
            'name' => json_encode(['en' => $name, 'ar' => $name], JSON_UNESCAPED_UNICODE),
            'icon' => 'check-circle',
            'order' => (int) DB::table('amenities')->max('order') + 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $key;
    }

    private function toAssets(object $row, $now): void
    {
        $location = $row->space_id !== null
            ? ['space', $row->space_id]
            : ['branch', DB::table('branches')->orderBy('id')->value('id')];

        if ($location[1] === null) {
            return;
        }

        $itemId = $this->itemFor($row, $now);
        $status = match ($row->status) {
            'maintenance' => 'in_maintenance',
            'retired' => 'retired',
            default => 'in_service',
        };
        $note = $row->space_id === null ? 'Moved from resources without a space: place it.' : null;

        for ($i = 0; $i < max(1, (int) $row->quantity); $i++) {
            $assetId = DB::table('inv_assets')->insertGetId([
                'item_id' => $itemId,
                'qr_token' => Str::random(40),
                'status' => $status,
                'location_type' => $location[0],
                'location_id' => $location[1],
                'notes' => $note,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $now,
            ]);
            DB::table('inv_assets')->where('id', $assetId)->update(['code' => 'AST-'.str_pad((string) $assetId, 6, '0', STR_PAD_LEFT)]);

            DB::table('inv_asset_movements')->insert([
                'asset_id' => $assetId,
                'to_type' => $location[0],
                'to_id' => $location[1],
                'note' => 'Moved from resources.',
                'moved_at' => $row->created_at ?? $now,
                'created_at' => $now,
            ]);

            if ($status === 'in_maintenance') {
                DB::table('inv_maintenance_records')->insert([
                    'asset_id' => $assetId,
                    'type' => 'corrective',
                    'description' => $row->status_reason ?: 'In maintenance when moved from resources.',
                    'opened_at' => $row->status_from ?? $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function itemFor(object $row, $now): int
    {
        $categoryId = $this->categoryFor($row->category, $now);
        $existing = DB::table('inv_items')->where('category_id', $categoryId)->get()
            ->first(fn (object $item) => (json_decode($item->name, true)['en'] ?? null) === $row->name);

        if ($existing !== null) {
            return $existing->id;
        }

        $id = DB::table('inv_items')->insertGetId([
            'category_id' => $categoryId,
            'unit_id' => $this->pieceUnit($now),
            'name' => json_encode(['en' => $row->name, 'ar' => $row->name], JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('inv_items')->where('id', $id)->update(['sku' => 'ITM-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT)]);

        return $id;
    }

    private function categoryFor(string $category, $now): int
    {
        [$en, $ar, $icon] = match ($category) {
            'projector' => ['Projectors', 'أجهزة العرض', 'projector'],
            'mic' => ['Microphones', 'الميكروفونات', 'volume-2'],
            'screen' => ['Screens', 'الشاشات', 'screen'],
            'whiteboard' => ['Whiteboards', 'الألواح البيضاء', 'whiteboard'],
            default => [Str::headline($category), Str::headline($category), 'check-circle'],
        };

        $parentId = $this->rootCategory($now);
        $existing = DB::table('inv_categories')->where('parent_id', $parentId)->where('icon', $icon)->get()
            ->first(fn (object $row) => (json_decode($row->name, true)['en'] ?? null) === $en);

        return $existing->id ?? DB::table('inv_categories')->insertGetId([
            'parent_id' => $parentId,
            'kind' => 'asset',
            'name' => json_encode(['en' => $en, 'ar' => $ar], JSON_UNESCAPED_UNICODE),
            'icon' => $icon,
            'show_to_members' => true,
            'order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function rootCategory($now): int
    {
        $existing = DB::table('inv_categories')->whereNull('parent_id')->get()
            ->first(fn (object $row) => (json_decode($row->name, true)['en'] ?? null) === 'Presentation equipment');

        return $existing->id ?? DB::table('inv_categories')->insertGetId([
            'kind' => 'asset',
            'name' => json_encode(['en' => 'Presentation equipment', 'ar' => 'معدات العرض'], JSON_UNESCAPED_UNICODE),
            'icon' => 'projector',
            'show_to_members' => true,
            'order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function pieceUnit($now): int
    {
        return DB::table('inv_units')->where('code', 'piece')->value('id') ?? DB::table('inv_units')->insertGetId([
            'code' => 'piece',
            'name' => json_encode(['en' => 'Piece', 'ar' => 'قطعة'], JSON_UNESCAPED_UNICODE),
            'allows_fractions' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
