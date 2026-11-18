<?php

namespace App\Domain\Inventory;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Models\Asset;
use App\Domain\Foundation\Models\Space;
use App\Domain\Inventory\Adapters\AddLocationResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * The equipment a member sees on a space: its assets in service whose
 * category is marked `show_to_members` (a projector, two microphones), one
 * entry per item with how many there are. An asset under repair, retired
 * or lost drops off at once. Loaded for a whole page of spaces in one query.
 */
class SpaceEquipment
{
    /** @var array<int, list<array{key: string, label: string, icon: string}>> */
    private array $bySpace = [];

    /**
     * @param  iterable<int>  $spaceIds
     */
    public function load(iterable $spaceIds): void
    {
        $ids = collect($spaceIds)->map(fn ($id) => (int) $id)->diff(array_keys($this->bySpace))->values();

        if ($ids->isEmpty()) {
            return;
        }

        foreach ($ids as $id) {
            $this->bySpace[$id] = [];
        }

        $this->visible()
            ->whereIn('location_id', $ids)
            ->with('item.category')
            ->orderBy('item_id')
            ->get()
            ->groupBy('location_id')
            ->each(function ($assets, $spaceId) {
                $this->bySpace[(int) $spaceId] = $assets->groupBy('item_id')->map(function ($group) {
                    $item = $group->first()->item;
                    $name = (string) $item->translate('name');

                    return [
                        'key' => 'item_'.$item->id,
                        'label' => $group->count() > 1 ? $name.' ×'.$group->count() : $name,
                        'icon' => $item->category->icon ?? 'check-circle',
                    ];
                })->values()->all();
            });
    }

    /**
     * @return list<array{key: string, label: string, icon: string}>
     */
    public function for(Space $space): array
    {
        $this->load([$space->id]);

        return $this->bySpace[$space->id];
    }

    /**
     * The ids of spaces holding visible equipment whose name matches, for
     * the browse filter.
     *
     * @return Builder<Asset>
     */
    public function spacesMatching(string $needle): Builder
    {
        return $this->visible()
            ->select('location_id')
            ->whereHas('item', fn (Builder $item) => $item->where('name', 'like', "%{$needle}%"));
    }

    /**
     * @return Builder<Asset>
     */
    private function visible(): Builder
    {
        return Asset::query()
            ->where('location_type', AddLocationResolver::SPACE)
            ->where('status', AssetStatus::InService)
            ->whereHas('item.category', fn (Builder $category) => $category->where('show_to_members', true));
    }
}
