<?php

namespace App\Domain\Experience\Services;

use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;

class EventLocationService
{
    /**
     * Resolve the location string as Branch + Room.
     */
    public function resolveLocation(
        ?int &$branchId,
        ?int $spaceId = null,
        ?string $room = null,
        ?string $explicitLocation = null,
        string $locale = 'ar'
    ): ?string {
        $space = null;
        if ($spaceId) {
            $space = Space::with('building.branch')->find($spaceId);
            if ($space && ! $branchId && $space->building?->branch_id) {
                $branchId = (int) $space->building->branch_id;
            }
        }

        // Determine Room Name
        $roomName = null;
        if ($space) {
            $roomName = $this->formatSpaceRoomName($space, $locale);
        } elseif (! empty($room)) {
            $roomName = $this->formatCustomRoomName($room, $locale);
        } elseif (! empty($explicitLocation)) {
            return $explicitLocation;
        } else {
            // Default fallback if nothing specified
            return null;
        }

        // Determine Branch Name
        $branch = null;
        if ($branchId) {
            $branch = Branch::find($branchId);
        }
        if (! $branch) {
            $branch = Branch::where('is_default', true)->first() ?? Branch::first();
            if ($branch && ! $branchId) {
                $branchId = (int) $branch->id;
            }
        }

        $branchName = $branch
            ? ($branch->translate('name', $locale) ?: $branch->translate('name', 'en') ?: 'Main Branch')
            : ($locale === 'ar' ? 'الفرع الرئيسي' : 'Main Branch');

        return "{$branchName} – {$roomName}";
    }

    /**
     * Retrieve available event rooms and meeting rooms for event hosting.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableRooms(?int $branchId = null, string $locale = 'ar'): array
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
            $type = $space->space_type instanceof SpaceType ? $space->space_type->value : (string) $space->space_type;

            return [
                'id' => $space->id,
                'space_id' => $space->id,
                'name' => $this->formatSpaceRoomName($space, $locale),
                'name_ar' => $this->formatSpaceRoomName($space, 'ar'),
                'name_en' => $this->formatSpaceRoomName($space, 'en'),
                'type' => $type,
                'capacity' => $space->capacity,
                'branch_id' => $branch?->id,
                'branch_name' => $branch ? ($branch->translate('name', $locale) ?: $branch->translate('name', 'en')) : null,
            ];
        })->values()->all();
    }

    public function formatSpaceRoomName(Space $space, string $locale = 'ar'): string
    {
        if ($space->space_type === SpaceType::EventHall) {
            return $locale === 'ar' ? 'قاعة الفعاليات' : 'Event Hall';
        }

        if ($space->space_type === SpaceType::Room) {
            return $locale === 'ar' ? 'غرفة الاجتماعات' : 'Meeting Room';
        }

        return $locale === 'ar' ? 'غرفة الفعاليات' : 'Event Room';
    }

    public function formatCustomRoomName(string $room, string $locale = 'ar'): string
    {
        $normalized = strtolower(trim(str_replace(['-', '_'], ' ', $room)));

        if (str_contains($normalized, 'event')) {
            return $locale === 'ar' ? 'قاعة الفعاليات' : 'Event Room';
        }

        if (str_contains($normalized, 'meeting') || $normalized === 'room') {
            return $locale === 'ar' ? 'غرفة الاجتماعات' : 'Meeting Room';
        }

        return trim($room);
    }
}
