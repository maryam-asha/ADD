<?php

namespace App\Domain\Services\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Resolves which services are visible and accessible to a given member.
 *
 * Visibility rules (applied in order):
 *  1. `is_active = false`  → never shown
 *  2. `visibility`         → member | public | both
 *  3. `allowed_plan_module_ids` → null = open, otherwise member must hold
 *     an active membership whose plan belongs to one of those modules
 *
 * `is_coming_soon` is NOT a filter — it passes through so the UI can render
 * the "Coming Soon" badge. The type is still returned; it just can't be actioned.
 */
class ServiceCatalogService
{
    /**
     * Return all axes with their accessible service types for the given member.
     * Types are already filtered; axes with zero accessible types are omitted.
     *
     * @return Collection<int, ServiceAxis> (with 'serviceTypes' already loaded)
     */
    public function forMember(User $user, ?string $axisSlug = null): Collection
    {
        $moduleIds = $this->activePlanModuleIds($user);

        $query = ServiceAxis::with([
            'serviceTypes' => function ($query) use ($moduleIds) {
                $query->active()
                    ->where(function ($q) {
                        $q->where('visibility', 'member')
                            ->orWhere('visibility', 'both');
                    })
                    ->accessibleByModules($moduleIds)
                    ->ordered();
            },
        ])
            ->ordered();

        if ($axisSlug !== null) {
            $query->where('slug', $axisSlug);
        }

        return $query->get()
            ->filter(fn (ServiceAxis $axis) => $axis->serviceTypes->isNotEmpty())
            ->values();
    }

    /**
     * Return the main service sections (axes) for the member without grouping services inside them.
     * Only returns axes that have accessible services for this member.
     *
     * @return Collection<int, ServiceAxis>
     */
    public function axesForMember(User $user): Collection
    {
        $moduleIds = $this->activePlanModuleIds($user);

        return ServiceAxis::whereHas('serviceTypes', function ($query) use ($moduleIds) {
            $query->active()
                ->where(function ($q) {
                    $q->where('visibility', 'member')
                        ->orWhere('visibility', 'both');
                })
                ->accessibleByModules($moduleIds);
        })
            ->withCount(['serviceTypes' => function ($query) use ($moduleIds) {
                $query->active()
                    ->where(function ($q) {
                        $q->where('visibility', 'member')
                            ->orWhere('visibility', 'both');
                    })
                    ->accessibleByModules($moduleIds);
            }])
            ->ordered()
            ->get();
    }

    /**
     * Return a single service section (axis) and its accessible services for the given member.
     */
    public function servicesForAxis(string $axisSlug, User $user): ?ServiceAxis
    {
        $moduleIds = $this->activePlanModuleIds($user);

        $axis = ServiceAxis::where('slug', $axisSlug)
            ->with([
                'serviceTypes' => function ($query) use ($moduleIds) {
                    $query->active()
                        ->where(function ($q) {
                            $q->where('visibility', 'member')
                                ->orWhere('visibility', 'both');
                        })
                        ->accessibleByModules($moduleIds)
                        ->ordered();
                },
            ])
            ->first();

        if ($axis === null || $axis->serviceTypes->isEmpty()) {
            return null;
        }

        return $axis;
    }

    /**
     * Return all accessible services as a flat list without grouping by axes.
     *
     * Paginated, unlike the axis-grouped reads above: this is the flat
     * catalog a member scrolls, and it grows with every service added,
     * while an axis holds a small bounded set.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ServiceType>
     */
    public function allServicesForMember(User $user, array $filters = []): LengthAwarePaginator
    {
        $moduleIds = $this->activePlanModuleIds($user);

        $query = ServiceType::active()
            ->where(function ($q) {
                $q->where('visibility', 'member')
                    ->orWhere('visibility', 'both');
            })
            ->accessibleByModules($moduleIds)
            ->with('axis')
            ->ordered();

        if (! empty($filters['axis'])) {
            $query->whereHas('axis', fn ($q) => $q->where('slug', $filters['axis']));
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name->ar', 'like', "%{$search}%")
                    ->orWhere('name->en', 'like', "%{$search}%")
                    ->orWhere('description->ar', 'like', "%{$search}%")
                    ->orWhere('description->en', 'like', "%{$search}%");
            });
        }

        // Same 1..100 clamp the other member listings use, so a client
        // cannot ask for the whole table in one page.
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));

        return $query->paginate($perPage);
    }

    /**
     * Return a single active service type by slug, ensuring the member
     * can access it (membership gate + visibility).
     */
    public function findForMember(string $slug, User $user): ?ServiceType
    {
        $moduleIds = $this->activePlanModuleIds($user);

        return ServiceType::active()
            ->where('slug', $slug)
            ->where(function ($q) {
                $q->where('visibility', 'member')
                    ->orWhere('visibility', 'both');
            })
            ->accessibleByModules($moduleIds)
            ->with('requestForm.activeFields')
            ->first();
    }

    /**
     * Return a single active service type by id, ensuring the member
     * can access it (membership gate + visibility).
     */
    public function findByIdForMember(int|string $id, User $user): ?ServiceType
    {
        $moduleIds = $this->activePlanModuleIds($user);

        return ServiceType::active()
            ->whereKey($id)
            ->where(function ($q) {
                $q->where('visibility', 'member')
                    ->orWhere('visibility', 'both');
            })
            ->accessibleByModules($moduleIds)
            ->with('requestForm.activeFields')
            ->first();
    }

    /**
     * The home screen's "Explore ADD": the services put on it, that this
     * member may see, by layer (the axis's order) and then their own order.
     *
     * @return Collection<int, ServiceType> (with 'axis' loaded)
     */
    public function homeServicesForMember(User $user): Collection
    {
        return ServiceType::query()
            ->visibleToMember($this->activePlanModuleIds($user))
            ->where('show_on_home', true)
            ->with('axis')
            ->get()
            ->sortBy([
                fn (ServiceType $a, ServiceType $b) => $a->axis->order <=> $b->axis->order,
                fn (ServiceType $a, ServiceType $b) => $a->order <=> $b->order,
            ])
            ->values();
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    /**
     * Collect the plan_module_id values from the member's currently active
     * memberships. Used to evaluate `allowed_plan_module_ids` gates.
     *
     * An empty array means the member holds no active memberships —
     * `accessibleByModules([])` still returns types where
     * `allowed_plan_module_ids` is null (open to all).
     */
    private function activePlanModuleIds(User $user): array
    {
        return Membership::query()
            ->where('owner_type', OwnerType::User->value)
            ->where('owner_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->with('plan:id,plan_module_id')
            ->get()
            ->pluck('plan.plan_module_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
