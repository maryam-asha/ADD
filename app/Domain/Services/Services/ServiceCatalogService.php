<?php

namespace App\Domain\Services\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceType;
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
     * @return Collection<int, ServiceAxis>  (with 'serviceTypes' already loaded)
     */
    public function forMember(User $user): Collection
    {
        $moduleIds = $this->activePlanModuleIds($user);

        return ServiceAxis::with([
            'serviceTypes' => function ($query) use ($moduleIds) {
                $query->active()
                    ->where(function ($q) {
                        $q->where('visibility', 'member')
                          ->orWhere('visibility', 'both');
                    })
                    ->accessibleByModules($moduleIds)
                    ->with('fields')
                    ->ordered();
            },
        ])
        ->ordered()
        ->get()
        ->filter(fn (ServiceAxis $axis) => $axis->serviceTypes->isNotEmpty())
        ->values();
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
            ->with('fields')
            ->first();
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
