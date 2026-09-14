<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\ResourceCategory;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Resource;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class SpaceBrowseService
{
    public function __construct(
        private readonly BusinessHoursService $businessHours,
        private readonly SettingService $settings,
        private readonly PointConversionService $points,
    ) {}

    /**
     * @param array{
     *     space_type?: ?string,
     *     date?: ?string,
     *     min_capacity?: ?int,
     *     branch_id?: ?int,
     *     amenity?: ?string,
     *     per_page?: ?int,
     *     page?: ?int,
     * } $filters
     * @return array<string, mixed>
     */
    public function browse(array $filters, ?User $user = null): array
    {
        $timezone = $this->settings->get('app.timezone', 'Asia/Damascus');

        // 1. Resolve Target Branch
        $branch = $this->resolveBranch($filters['branch_id'] ?? null, $user);
        if (! $branch) {
            return [
                'working_hours_banner' => null,
                'spaces' => [],
                'meta' => [
                    'current_page' => 1,
                    'from' => null,
                    'last_page' => 1,
                    'per_page' => 10,
                    'to' => null,
                    'total' => 0,
                    'total_results' => 0,
                    'space_type' => 'all',
                    'branch_id' => null,
                ],
            ];
        }

        // 2. Working Hours Banner for Branch (Today)
        $today = Carbon::now($timezone)->startOfDay();
        $periods = $this->businessHours->periodsFor($today, $branch);
        $banner = $this->buildWorkingHoursBanner($periods, $today, $timezone);

        // 3. Query Active Spaces for Branch
        $spacesQuery = Space::query()
            ->active()
            ->with(['resources', 'building', 'zone.floor'])
            ->whereHas('building', fn ($q) => $q->where('branch_id', $branch->id));

        $requestedType = strtolower((string) ($filters['space_type'] ?? 'all'));
        $resolvedType = $this->mapSpaceType($requestedType);

        if ($resolvedType !== null) {
            $spacesQuery->where('space_type', $resolvedType->value);
        }

        if (! empty($filters['min_capacity'])) {
            $spacesQuery->where('capacity', '>=', (int) $filters['min_capacity']);
        }

        if (! empty($filters['amenity'])) {
            $amenityNeedle = strtolower($filters['amenity']);
            $spacesQuery->whereHas('resources', function ($q) use ($amenityNeedle) {
                $q->where('status', OperationalStatus::Active)
                    ->where(function ($sub) use ($amenityNeedle) {
                        $sub->where('category', $amenityNeedle)
                            ->orWhere('name', 'LIKE', "%{$amenityNeedle}%");
                    });
            });
        }

        // 4. Paginate spaces (default: 10 per page)
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 10)));
        $paginator = $spacesQuery->orderBy('hourly_rate_points', 'asc')->paginate($perPage);

        // 5. Transform Space Cards
        $items = collect($paginator->items())->map(fn (Space $space) => $this->formatSpaceCard($space));

        return [
            'working_hours_banner' => $banner,
            'spaces' => $items->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
                'total_results' => $paginator->total(),
                'space_type' => $requestedType ?: 'all',
                'branch_id' => $branch->id,
            ],
        ];
    }

    private function resolveBranch(?int $branchId, ?User $user): ?Branch
    {
        if ($branchId) {
            return Branch::query()->where('is_active', true)->find($branchId);
        }

        if ($user) {
            $homeBranch = $user->branches()->wherePivot('is_home_branch', true)->first();
            if ($homeBranch && $homeBranch->is_active) {
                return $homeBranch;
            }
        }

        return Branch::query()->where('is_active', true)->first();
    }

    private function mapSpaceType(string $type): ?SpaceType
    {
        return match ($type) {
            'co_space', 'hot_desk', 'open_space' => SpaceType::CoSpace,
            'room', 'meeting_room' => SpaceType::Room,
            'business', 'private_office' => SpaceType::Business,
            'event_hall' => SpaceType::EventHall,
            default => null,
        };
    }

    /**
     * @param  array<int, array{open_time: string, close_time: string}>  $periods
     * @return array<string, mixed>|null
     */
    private function buildWorkingHoursBanner(array $periods, CarbonInterface $targetDate, string $timezone): ?array
    {
        if (empty($periods)) {
            return [
                'text' => __('api.space.working_hours_notice'),
                'hours' => null,
                'open_time' => null,
                'close_time' => null,
                'is_open' => false,
            ];
        }

        $firstOpen = $periods[0]['open_time'];
        $lastClose = end($periods)['close_time'];

        $openCarbon = Carbon::parse($firstOpen, $timezone);
        $closeCarbon = Carbon::parse($lastClose, $timezone);

        $formattedHours = sprintf('%s – %s', $openCarbon->format('g:i A'), $closeCarbon->format('g:i A'));

        return [
            'text' => __('api.space.working_hours_notice'),
            'hours' => $formattedHours,
            'open_time' => $firstOpen,
            'close_time' => $lastClose,
            'is_open' => true,
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, icon: string}>
     */
    public function categories(): array
    {
        return [
            ['key' => 'all', 'label' => 'All', 'icon' => 'grid'],
            ['key' => 'co_space', 'label' => 'Hot Desk', 'icon' => 'desk'],
            ['key' => 'business', 'label' => 'Private Office', 'icon' => 'door'],
            ['key' => 'room', 'label' => 'Meeting Room', 'icon' => 'meeting'],
            ['key' => 'event_hall', 'label' => 'Event Hall', 'icon' => 'stage'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSpaceCard(Space $space): array
    {
        $badge = match ($space->space_type) {
            SpaceType::CoSpace => 'OPEN SPACE',
            SpaceType::Room => 'MEETING ROOM',
            SpaceType::Business => 'PRIVATE OFFICE',
            SpaceType::EventHall => 'EVENT HALL',
        };

        $title = match ($space->space_type) {
            SpaceType::CoSpace => 'Hot Desk',
            SpaceType::Room => 'Meeting Room A',
            SpaceType::Business => 'Private Office '.($space->id),
            SpaceType::EventHall => 'Event Hall',
        };

        $subtitle = match ($space->space_type) {
            SpaceType::CoSpace => 'Open Workspace',
            default => __('api.space.up_to_people', ['count' => $space->capacity ?? 1]),
        };

        $ratePoints = (int) ($space->hourly_rate_points ?? 0);
        $rate = (float) $this->points->pointsToUsd($ratePoints);
        $currency = 'USD';
        $symbol = '$';
        $isStartingFrom = $space->space_type === SpaceType::CoSpace;

        $amenities = $this->buildAmenities($space);

        return [
            'id' => $space->id,
            'title' => $title,
            'subtitle' => $subtitle,
            'badge' => $badge,
            'status' => $space->status->value,
            'status_label' => __('api.space.statuses.'.$space->status->value),
            'space_type' => $space->space_type->value,
            'capacity' => $space->capacity,
            'capacity_label' => $space->space_type === SpaceType::CoSpace
                ? __('api.space.single_seat')
                : __('api.space.up_to_people', ['count' => $space->capacity ?? 1]),
            'pricing' => [
                'starting_from' => $isStartingFrom,
                'hourly_rate_points' => $ratePoints,
                'hourly_rate' => $rate,
                'currency' => $currency,
                'currency_symbol' => $symbol,
                'formatted' => sprintf('%s%s / %s', $symbol, rtrim(rtrim(number_format($rate, 2), '0'), '.'), __('api.space.per_hour')),
            ],
            'amenities' => $amenities,
            'main_image' => $space->main_image,
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, icon: string}>
     */
    private function buildAmenities(Space $space): array
    {
        $amenities = [];

        if ($space->space_type === SpaceType::CoSpace) {
            $amenities[] = ['key' => 'seat', 'label' => __('api.space.single_seat'), 'icon' => 'user'];
        }

        // Fully qualified deliberately: Pint's phpdoc_types fixer lowercases a
        // bare `Resource` to PHP's builtin `resource` stream pseudo-type, which
        // silently breaks static analysis of this Eloquent model. The FQCN is
        // left alone by that fixer.
        /** @var \App\Domain\Foundation\Models\Resource $res */
        foreach ($space->resources as $res) {
            if ($res->status !== OperationalStatus::Active) {
                continue;
            }

            $icon = match ($res->category) {
                ResourceCategory::Screen => 'screen',
                ResourceCategory::Projector => 'projector',
                ResourceCategory::Whiteboard => 'whiteboard',
                ResourceCategory::Mic => 'volume-2',
                default => 'check-circle',
            };

            $label = $res->name;
            $key = $res->category->value;

            $lower = strtolower($res->name);
            if (str_contains($lower, 'wi-fi') || str_contains($lower, 'wifi')) {
                $icon = 'wifi';
                $key = 'wifi';
                $label = 'Wi-Fi';
            } elseif (str_contains($lower, 'power') || str_contains($lower, 'outlet')) {
                $icon = 'zap';
                $key = 'power';
                $label = 'Power';
            } elseif (str_contains($lower, 'café') || str_contains($lower, 'cafe')) {
                $icon = 'coffee';
                $key = 'cafe';
                $label = 'Café';
            } elseif (str_contains($lower, 'a/c') || str_contains($lower, 'air conditioning')) {
                $icon = 'snowflake';
                $key = 'ac';
                $label = 'A/C';
            } elseif (str_contains($lower, 'lock')) {
                $icon = 'lock';
                $key = 'lockable';
                $label = 'Lockable';
            } elseif (str_contains($lower, 'stage')) {
                $icon = 'stage';
                $key = 'stage';
                $label = 'Stage';
            } elseif (str_contains($lower, 'audio') || str_contains($lower, 'mic')) {
                $icon = 'volume-2';
                $key = 'audio';
                $label = 'Audio';
            } elseif (str_contains($lower, 'screen')) {
                $icon = 'tv';
                $key = 'screen';
                $label = 'Screen';
            } elseif (str_contains($lower, 'whiteboard')) {
                $icon = 'edit-3';
                $key = 'whiteboard';
                $label = 'Whiteboard';
            } elseif (str_contains($lower, 'projector')) {
                $icon = 'video';
                $key = 'projector';
                $label = 'Projector';
            }

            $amenities[] = [
                'key' => $key,
                'label' => $label,
                'icon' => $icon,
            ];
        }

        // Add lockable if not already present
        if ($space->is_lockable && ! collect($amenities)->contains('key', 'lockable')) {
            $amenities[] = ['key' => 'lockable', 'label' => 'Lockable', 'icon' => 'lock'];
        }

        return array_values($amenities);
    }

    /**
     * @return array<string, mixed>
     */
    public function details(Space $space, ?User $user = null): array
    {
        if ($space->status !== OperationalStatus::Active) {
            abort(404);
        }

        $space->loadMissing(['resources', 'building.branch', 'zone.floor']);

        $timezone = $this->settings->get('app.timezone', 'Asia/Damascus');
        $branch = $space->building->branch;
        $periods = $this->businessHours->periodsFor(Carbon::now($timezone), $branch);
        $hoursBanner = $this->buildWorkingHoursBanner($periods, Carbon::now($timezone), $timezone);

        $badge = match ($space->space_type) {
            SpaceType::CoSpace => 'OPEN SPACE',
            SpaceType::Room => 'MEETING ROOM',
            SpaceType::Business => 'PRIVATE OFFICE',
            SpaceType::EventHall => 'EVENT HALL',
        };

        $title = match ($space->space_type) {
            SpaceType::CoSpace => 'Hot Desk',
            SpaceType::Room => 'Meeting Room A',
            SpaceType::Business => 'Private Office '.($space->id),
            SpaceType::EventHall => 'Event Hall',
        };

        $subtitle = match ($space->space_type) {
            SpaceType::CoSpace => 'Open Workspace',
            default => __('api.space.up_to_people', ['count' => $space->capacity ?? 1]),
        };

        $descriptionKey = 'api.space.descriptions.'.$space->space_type->value;
        $description = __($descriptionKey);

        $ratePoints = (int) ($space->hourly_rate_points ?? 0);
        $rate = (float) $this->points->pointsToUsd($ratePoints);
        $currency = 'USD';
        $symbol = '$';
        $isStartingFrom = $space->space_type === SpaceType::CoSpace;

        $quickFeatures = $this->buildQuickFeatures($space);

        $branchName = $branch->translate('name') ?? 'Aleppo Digital District';
        $floorLabel = $space->zone?->floor?->label ?? 'Ground Floor';
        $formattedLocation = sprintf('%s - %s', $branchName, $floorLabel);

        $formattedWorkingHours = $hoursBanner['hours'] ?? '8:00 AM – 10:00 PM';

        $amenitiesChecklist = $this->buildAmenitiesChecklist($space);

        return [
            'id' => $space->id,
            'badge' => $badge,
            'title' => $title,
            'subtitle' => $subtitle,
            'description' => $description,
            'images' => $space->images,
            'quick_features' => $quickFeatures,
            'details' => [
                'status' => $space->status->value,
                'status_label' => __('api.space.statuses.'.$space->status->value),
                'price' => [
                    'starting_from' => $isStartingFrom,
                    'hourly_rate_points' => $ratePoints,
                    'hourly_rate' => $rate,
                    'currency' => $currency,
                    'currency_symbol' => $symbol,
                    'formatted' => sprintf('%s%s / %s', $symbol, rtrim(rtrim(number_format($rate, 2), '0'), '.'), __('api.space.per_hour')),
                ],
                'capacity' => [
                    'count' => $space->capacity ?? 1,
                    'formatted' => ($space->capacity ?? 1) === 1
                        ? __('api.space.person', ['count' => 1])
                        : __('api.space.people', ['count' => $space->capacity]),
                ],
                'location' => [
                    'branch' => $branchName,
                    'building' => $space->building->translate('name'),
                    'floor' => $floorLabel,
                    'zone' => $space->zone?->label,
                    'formatted' => $formattedLocation,
                ],
                'working_hours' => [
                    'formatted' => $formattedWorkingHours,
                    'open_time' => $hoursBanner['open_time'] ?? null,
                    'close_time' => $hoursBanner['close_time'] ?? null,
                    'is_open' => $hoursBanner['is_open'] ?? true,
                ],
            ],
            'amenities' => $amenitiesChecklist,
            'cta' => [
                'space_id' => $space->id,
                'button_text' => __('api.space.book_this_space'),
                'can_book' => true,
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, icon: string}>
     */
    private function buildQuickFeatures(Space $space): array
    {
        $features = [];

        $capacity = $space->capacity ?? 1;
        $seatLabel = $capacity === 1
            ? __('api.space.seat', ['count' => 1])
            : __('api.space.seats', ['count' => $capacity]);

        $features[] = ['key' => 'seat', 'label' => $seatLabel, 'icon' => 'user'];
        $features[] = ['key' => 'wifi', 'label' => 'Wi-Fi', 'icon' => 'wifi'];
        $features[] = ['key' => 'power', 'label' => 'Power', 'icon' => 'zap'];

        $areaLabel = match ($space->space_type) {
            SpaceType::CoSpace => __('api.space.open_area'),
            default => __('api.space.private_area'),
        };

        $features[] = ['key' => 'area', 'label' => $areaLabel, 'icon' => 'maximize-2'];

        return $features;
    }

    /**
     * @return array<int, array{key: string, name: string, icon: string}>
     */
    private function buildAmenitiesChecklist(Space $space): array
    {
        $checklist = [];

        // Base checklist standard for hot desks/spaces
        if ($space->space_type === SpaceType::CoSpace) {
            $checklist = [
                ['key' => 'wifi', 'name' => 'High-Speed Wi-Fi', 'icon' => 'wifi'],
                ['key' => 'power', 'name' => 'Power Outlets', 'icon' => 'zap'],
                ['key' => 'ergonomic_chairs', 'name' => 'Ergonomic Chairs', 'icon' => 'check-circle'],
                ['key' => 'printing', 'name' => 'Printing Access', 'icon' => 'printer'],
                ['key' => 'locker', 'name' => 'Locker (Add-on)', 'icon' => 'lock'],
                ['key' => 'cafe', 'name' => 'Cafe Access', 'icon' => 'coffee'],
            ];
        } else {
            // For rooms, offices, halls: start with resources
            foreach ($space->resources as $res) {
                if ($res->status !== OperationalStatus::Active) {
                    continue;
                }
                $icon = match ($res->category) {
                    ResourceCategory::Screen => 'tv',
                    ResourceCategory::Projector => 'video',
                    ResourceCategory::Whiteboard => 'edit-3',
                    ResourceCategory::Mic => 'volume-2',
                    default => 'check-circle',
                };
                $checklist[] = [
                    'key' => $res->category->value.'_'.$res->id,
                    'name' => $res->name,
                    'icon' => $icon,
                ];
            }

            if ($space->is_lockable) {
                $checklist[] = ['key' => 'smart_lock', 'name' => 'Keyless Smart Lock', 'icon' => 'lock'];
            }

            // Standard facilities
            $checklist[] = ['key' => 'wifi', 'name' => 'High-Speed Wi-Fi', 'icon' => 'wifi'];
            $checklist[] = ['key' => 'ac', 'name' => 'Climate Control A/C', 'icon' => 'snowflake'];
            $checklist[] = ['key' => 'cafe', 'name' => 'Cafe Access', 'icon' => 'coffee'];
        }

        return array_values($checklist);
    }
}
