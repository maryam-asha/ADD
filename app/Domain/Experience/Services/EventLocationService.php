<?php

namespace App\Domain\Experience\Services;

use App\Domain\Experience\Models\Event;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;

class EventLocationService
{
    /**
     * Display string for an event's location.
     *
     * `location` and `space_id` are mutually exclusive by validation:
     * a stored `location` means an off-site event (a street address, a
     * partner venue) and is shown verbatim, while an on-site event
     * derives its label here, at read time, from the live branch and
     * space names in the caller's locale. Nothing about an on-site
     * location is stored — a renamed branch or room updates everywhere
     * at once, and one row serves both languages.
     */
    public function displayLocation(Event $event, ?string $locale = null): ?string
    {
        if (filled($event->location)) {
            return $event->location;
        }

        $space = $event->relationLoaded('space')
            ? $event->space
            : $event->space()->with('building.branch')->first();

        if (! $space) {
            return null;
        }

        return $this->composeLabel(
            $space->building?->branch?->translate('name', $locale),
            $this->spaceName($space, $locale),
        );
    }

    /**
     * Event-hostable rooms, for the admin's room picker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableRooms(?int $branchId = null, ?string $locale = null): array
    {
        $query = Space::query()
            ->whereIn('space_type', [SpaceType::EventHall->value, SpaceType::Room->value])
            ->where('status', OperationalStatus::Active)
            ->with(['building.branch']);

        if ($branchId) {
            $query->whereHas('building', fn ($q) => $q->where('branch_id', $branchId));
        }

        return $query->get()->map(function (Space $space) use ($locale) {
            $branch = $space->building?->branch;

            return [
                'id' => $space->id,
                'space_id' => $space->id,
                'name' => $this->spaceName($space, $locale),
                'name_ar' => $this->spaceName($space, 'ar'),
                'name_en' => $this->spaceName($space, 'en'),
                'type' => $space->space_type instanceof SpaceType
                    ? $space->space_type->value
                    : (string) $space->space_type,
                'capacity' => $space->capacity,
                'branch_id' => $branch?->id,
                'branch_name' => $branch?->translate('name', $locale),
            ];
        })->values()->all();
    }

    /**
     * A space's own name, falling back to its type when unnamed — older
     * rows predate the name column, and it stays nullable.
     */
    public function spaceName(Space $space, ?string $locale = null): string
    {
        $name = $space->translate('name', $locale);

        if (filled($name)) {
            return $name;
        }

        return match ($space->space_type) {
            SpaceType::EventHall => __('api.space.types.event_hall', [], $locale),
            SpaceType::Room => __('api.space.types.room', [], $locale),
            default => __('api.space.types.space', [], $locale),
        };
    }

    private function composeLabel(?string ...$parts): ?string
    {
        $label = implode(' – ', array_filter(
            array_map(static fn (?string $p) => $p !== null ? trim($p) : null, $parts),
            static fn (?string $p) => filled($p),
        ));

        return filled($label) ? $label : null;
    }
}
