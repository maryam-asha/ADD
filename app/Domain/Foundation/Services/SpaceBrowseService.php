<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Foundation\Enums\AmenityType;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\ResourceCategory;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Resource;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\SettingService;
use App\Support\Presenters\BranchHoursPresenter;
use App\Support\Presenters\PriceLabelPresenter;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class SpaceBrowseService
{
    public function __construct(
        private readonly BusinessHoursService $businessHours,
        private readonly SettingService $settings,
        private readonly PointConversionService $points,
        private readonly BranchHoursPresenter $hours,
        private readonly PriceLabelPresenter $prices,
    ) {}

    /**
     * Note: there is no `date` filter here. One was documented for a long
     * time and never read. "Spaces with any availability on this date" is a
     * real feature but a different one — it means an availability
     * computation per card per page — and it belongs after the calendar
     * exists and can be measured. Dates live in
     * Member\SpaceAvailabilityController.
     *
     * @param array{
     *     space_type?: ?string,
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

        // 2. Working Hours Banner for Branch: today's hours, plus the branch's
        // week and closures. They are the same for every space, so they are
        // stated here, once, rather than in each space's details.
        $today = Carbon::now($timezone)->startOfDay();
        $periods = $this->businessHours->periodsFor($today, $branch);
        $banner = [
            ...$this->buildWorkingHoursBanner($periods, $today, $timezone),
            ...$this->branchWeek($branch, $periods, $timezone),
        ];

        // 3. Query Active Spaces for Branch
        $spacesQuery = Space::query()
            ->active()
            ->with(['resources', 'amenities', 'building', 'zone.floor'])
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
        $items = collect($paginator->items())->map(fn (Space $space) => $this->formatSpaceCard($space, $user));

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
     * The branch's hours beyond today, for the list's banner — everything the
     * member app needs about the branch before any booking screen opens:
     *
     * - `periods`: today's, the periods behind `hours`.
     * - `schedule` / `open_weekdays` / `days_label`: the ordinary week, its
     *   days grouped by the hours they share (a branch keeps different hours
     *   on different days).
     * - the timezone, and its label derived per request (Damascus has DST).
     *
     * The upcoming closures are not here: the booking calendar is set up
     * from the space details, which carry them as plain dates.
     *
     * Written by the same BranchHoursPresenter as the calendars' hours card.
     *
     * @param  array<int, array{open_time: string, close_time: string}>  $periods  today's
     * @return array<string, mixed>
     */
    private function branchWeek(Branch $branch, array $periods, string $timezone): array
    {
        $locale = app()->getLocale();
        $timezoneLabel = $this->hours->timezoneLabel($timezone);

        return [
            'title' => __('api.booking.branch_hours_title', [
                'branch' => $branch->translate('name', $locale) ?? 'Aleppo Digital District',
            ], $locale),
            'periods' => $periods,
            ...$this->hours->week($this->businessHours->weeklySchedule($branch), $timezone, $locale),
            'timezone' => $timezone,
            'timezone_label' => $timezoneLabel,
            'timezone_note' => __('api.booking.timezone_note', ['offset' => $timezoneLabel], $locale),
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
    private function formatSpaceCard(Space $space, ?User $user): array
    {
        $badge = match ($space->space_type) {
            SpaceType::CoSpace => 'OPEN SPACE',
            SpaceType::Room => 'MEETING ROOM',
            SpaceType::Business => 'PRIVATE OFFICE',
            SpaceType::EventHall => 'EVENT HALL',
        };

        $title = $space->displayTitle();

        $subtitle = match ($space->space_type) {
            SpaceType::CoSpace => 'Open Workspace',
            default => __('api.space.up_to_people', ['count' => $space->capacity ?? 1]),
        };

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
            'pricing' => $this->prices->hourly((int) ($space->hourly_rate_points ?? 0), $user),
            // A sale live now: the rate struck through, the sale rate, when it ends.
            'sale' => $this->prices->sale(null, (int) ($space->hourly_rate_points ?? 0), $user, true, 'api.space'),
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

        // Two genuinely different sources merge here: the fixed facility
        // amenities toggled on for this space (SpaceAmenity) and its active
        // equipment (Resource — projectors, mics, ...). See AmenityType's
        // own doc comment for why these no longer live on the same model.
        foreach ($space->amenities as $amenity) {
            $amenities[] = $this->resolveAmenityType($amenity->amenity);
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

            $amenities[] = $this->resolveResourceAmenity($res);
        }

        // Add lockable if not already present
        if ($space->is_lockable && ! collect($amenities)->contains('key', 'lockable')) {
            $amenities[] = ['key' => 'lockable', 'label' => 'Lockable', 'icon' => 'lock'];
        }

        return array_values($amenities);
    }

    /**
     * Icon mapping carried over unchanged from when these 8 values were
     * (briefly) ResourceCategory cases — same icons, now resolved off
     * AmenityType/SpaceAmenity instead. Labels are translated per-locale,
     * unlike a Resource's free-text admin-authored name.
     *
     * @return array{key: string, label: string, icon: string}
     */
    private function resolveAmenityType(AmenityType $amenity): array
    {
        $icon = match ($amenity) {
            AmenityType::Wifi => 'wifi',
            AmenityType::PowerOutlet => 'zap',
            AmenityType::Printing => 'printer',
            AmenityType::ErgonomicChairs => 'check-circle',
            AmenityType::Locker => 'lock',
            AmenityType::CafeAccess => 'coffee',
            AmenityType::Ac => 'snowflake',
            AmenityType::Stage => 'stage',
        };

        return [
            'key' => $amenity->value,
            'label' => __('api.space.amenities.'.$amenity->value),
            'icon' => $icon,
        ];
    }

    /**
     * Category-first amenity resolution shared by the card view
     * (`buildAmenities()`) and the detail checklist
     * (`buildAmenitiesChecklist()`). A properly categorized resource is
     * trusted directly; the substring-matching guesses only run for the
     * catch-all `Other` category, as a backward-compat path for legacy rows
     * that haven't been recategorized.
     *
     * @return array{key: string, label: string, icon: string}
     */
    private function resolveResourceAmenity(Resource $res): array
    {
        $icon = match ($res->category) {
            ResourceCategory::Screen => 'screen',
            ResourceCategory::Projector => 'projector',
            ResourceCategory::Whiteboard => 'whiteboard',
            ResourceCategory::Mic => 'volume-2',
            default => 'check-circle',
        };

        $label = $res->name;
        $key = $res->category->value;

        if ($res->category === ResourceCategory::Other) {
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
        }

        return ['key' => $key, 'label' => $label, 'icon' => $icon];
    }

    /**
     * @return array<string, mixed>
     */
    public function details(Space $space, ?User $user = null): array
    {
        if ($space->status !== OperationalStatus::Active) {
            abort(404);
        }

        $space->loadMissing(['resources', 'amenities', 'building.branch', 'zone.floor']);

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

        $title = $space->displayTitle();

        $subtitle = match ($space->space_type) {
            SpaceType::CoSpace => 'Open Workspace',
            default => __('api.space.up_to_people', ['count' => $space->capacity ?? 1]),
        };

        $descriptionKey = 'api.space.descriptions.'.$space->space_type->value;
        $description = __($descriptionKey);

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
                'pricing' => $this->prices->hourly((int) ($space->hourly_rate_points ?? 0), $user),
                'sale' => $this->prices->sale(null, (int) ($space->hourly_rate_points ?? 0), $user, true, 'api.space'),
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
     * Data-driven regardless of space type: every entry comes from the
     * space's own active `amenities`/`resources` rows (plus the
     * `is_lockable` flag), not a per-`space_type` fabricated list.
     *
     * @return array<int, array{key: string, name: string, icon: string}>
     */
    private function buildAmenitiesChecklist(Space $space): array
    {
        $checklist = [];

        foreach ($space->amenities as $amenity) {
            $resolved = $this->resolveAmenityType($amenity->amenity);
            $checklist[] = [
                'key' => $resolved['key'],
                'name' => $resolved['label'],
                'icon' => $resolved['icon'],
            ];
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

            $amenity = $this->resolveResourceAmenity($res);
            $checklist[] = [
                'key' => $amenity['key'],
                'name' => $amenity['label'],
                'icon' => $amenity['icon'],
            ];
        }

        if ($space->is_lockable) {
            $checklist[] = ['key' => 'smart_lock', 'name' => 'Keyless Smart Lock', 'icon' => 'lock'];
        }

        return array_values($checklist);
    }
}
