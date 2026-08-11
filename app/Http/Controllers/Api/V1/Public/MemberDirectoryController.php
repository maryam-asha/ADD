<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Identity\Enums\ConsentType;
use App\Domain\Identity\Models\User;
use App\Http\Resources\MemberDirectoryResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Entirely separate from `community_members` — that table can never link
 * to a user (tests/Guards/CommunityMembersNoUserLinkTest.php). This lists
 * real user accounts instead, gated on consent (Unit 2 design, 2026-08-09).
 * No membership-tier gating and no community_categories — both are
 * explicitly out of scope for this listing.
 *
 * A user holding both `member` and `operations` roles who has opted in is
 * listed like anyone else — the project owner confirmed no role-based
 * exclusion here; this is a deliberate decision, not an oversight.
 */
class MemberDirectoryController extends PublicResourceController
{
    protected function modelClass(): string
    {
        return User::class;
    }

    protected function resourceClass(): string
    {
        return MemberDirectoryResource::class;
    }

    /**
     * Deliberately not using the shared PublicResourceController::index()'s
     * unpaginated $query->get(): unlike the other public listings (founders,
     * partners, plans, community-members), which are small, bounded,
     * admin-curated tables, this one lists `users`, which grows with
     * membership and is reachable with no auth — same reasoning
     * ErrorLogController already uses for diverging from AdminResourceController.
     */
    public function index(): AnonymousResourceCollection
    {
        $query = $this->scopeQuery(User::query())->orderBy('name');

        return MemberDirectoryResource::collection($query->paginate(20));
    }

    protected function scopeQuery(Builder $query): Builder
    {
        return $query
            ->with(['personalProfile', 'professionalProfile'])
            ->where('status', 'active')
            ->whereHas('consents', function (Builder $q) {
                $q->where('consent_type', ConsentType::PublicDirectory->value)->active();
            })
            ->where(function (Builder $q) {
                $q->whereHas('personalProfile')->orWhereHas('professionalProfile');
            });
    }
}
