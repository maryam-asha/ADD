<?php

// app/Http/Controllers/Api/V1/Public/MemberDirectoryController.php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Identity\Enums\ConsentType;
use App\Domain\Identity\Models\User;
use App\Http\Resources\MemberDirectoryResource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Entirely separate from `community_members` — that table can never link
 * to a user (tests/Guards/CommunityMembersNoUserLinkTest.php). This lists
 * real user accounts instead, gated on consent (Unit 2 design, 2026-08-09).
 * No membership-tier gating and no community_categories — both are
 * explicitly out of scope for this listing.
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

    protected function scopeQuery(Builder $query): Builder
    {
        return $query
            ->with(['personalProfile', 'professionalProfile'])
            ->whereHas('consents', function (Builder $q) {
                $q->where('consent_type', ConsentType::PublicDirectory->value)->whereNull('revoked_at');
            })
            ->where(function (Builder $q) {
                $q->whereHas('personalProfile')->orWhereHas('professionalProfile');
            });
    }
}
