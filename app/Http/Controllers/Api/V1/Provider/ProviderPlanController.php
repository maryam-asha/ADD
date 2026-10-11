<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Membership\Models\Plan;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The plans this provider may assign: ADD's active public ones, and its own approved designs. */
class ProviderPlanController extends Controller
{
    use ActsForProvider;

    public function index(Request $request): AnonymousResourceCollection
    {
        $providerId = $this->provider($request)->id;

        $plans = Plan::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->where('is_public', true)->orWhere('provider_id', $providerId))
            ->with(['planModule', 'balances', 'features', 'discounts', 'resourceGrants.resourceType'])
            ->orderBy('order')
            ->get();

        return PlanResource::collection($plans);
    }
}
